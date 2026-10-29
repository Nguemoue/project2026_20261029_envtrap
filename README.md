# envtrap

One PHP file. No Composer. No network.

`env()` outside `config/` is a production bug. After `php artisan config:cache`, Laravel stops loading `.env`. A call that worked on your laptop returns `null` — or the default you passed — instead of the value in `.env`, unless the operating system itself exports that variable.

Un fichier PHP. Zéro Composer. Aucun appel réseau.

`env()` en dehors de `config/` est un bug de production. Après `php artisan config:cache`, Laravel ne charge plus `.env`. L'appel qui marchait en local renvoie `null` (ou la valeur par défaut), plus jamais celle du `.env`, sauf si le système exporte déjà la variable.

```bash
curl -fsSL -o envtrap.php \
  https://raw.githubusercontent.com/Nguemoue/project2026_20261029_envtrap/main/envtrap.php
php envtrap.php --self-test
php envtrap.php /path/to/your/laravel
```

## The bug, precisely

Laravel's own docs tell you to call `env()` only from `config/*.php`. Everywhere else you are supposed to use `config()`. People still write this in a controller:

```php
$secret = env('STRIPE_SECRET');
```

Sequence that bites:

1. Locally, `.env` is loaded, so `env('STRIPE_SECRET')` returns the key. Tests pass. You ship it.
2. Deploy runs `php artisan config:cache` (Forge, Vapor, Envoyer, Docker, GitHub Actions — almost everyone).
3. On the next request Laravel does **not** load `.env`. `env('STRIPE_SECRET')` does not see that file.
4. Stripe receives `null`. Only in production. The stack trace points at the SDK, not at `config:cache`.

`getenv()` and `Illuminate\Support\Env::get()` fall into the same trap. A second argument does not save you: `env('STRIPE_SECRET', 'sk_test_placeholder')` will keep returning the placeholder once `.env` is no longer loaded.

The fix is boring and correct:

```php
// config/services.php
'stripe' => ['secret' => env('STRIPE_SECRET')],

// app/Http/Controllers/BillingController.php
$secret = config('services.stripe.secret');
```

envtrap exists so that step 3 is caught in CI, on the machine that still has the source, before the deploy.

## What a run looks like

```text
envtrap 1.0.0
HIGH    production-debug  .env.production:2
        APP_DEBUG is enabled in a production or staging env file.
        APP_DEBUG=true
HIGH    env-outside-config  app/Http/Controllers/BillingController.php:7
        env() outside config/ does not read .env after config:cache. Use config() instead.
        $secret = env('STRIPE_SECRET');
HIGH    raw-sql-interpolation  app/Http/Controllers/BillingController.php:8
        Raw SQL is built with string interpolation. Use a ? binding instead.
        $rows = DB::table('payments')->whereRaw("user_id = {$this->userId}")->get();
HIGH    unguarded-model  app/Models/User.php:5
        Mass assignment is wide open ($guarded = []). Set $fillable, or a real deny-list.
        protected $guarded = [];
MEDIUM  debug-leftover  app/Http/Controllers/BillingController.php:9
        Debug call left in application code (dd).
        dd($secret, $rows);

4 files, 5 findings (4 high, 1 medium, 0 low)
Exit 1: findings at or above high.
```

Snippets are source lines. Values from `.env` are never printed. `APP_KEY` is reported as `APP_KEY=` with the secret dropped.

## Rules

| Severity | Rule | What it means |
| --- | --- | --- |
| high | `env-outside-config` | `env()`, `getenv()`, or `Env::get()` outside `config/`. Inside `tests/` this is low, because PHPUnit is not `config:cache`. |
| high | `unguarded-model` | `$guarded = []` or `$guarded = array()`. Mass assignment is wide open. |
| high | `raw-sql-interpolation` | `whereRaw` / `selectRaw` / the other `*Raw` methods, or `DB::raw()`, with a concatenated or interpolated variable. `whereRaw('id = ?', [$id])` is fine. |
| high | `production-debug` | `APP_DEBUG=true` in `.env.production`, `.env.prod`, or `.env.staging`. |
| high | `production-app-env` | `APP_ENV=local` (or `dev` / `development`) in one of those files. |
| high | `production-empty-key` | `APP_KEY` empty, `null`, or `base64:` with nothing after it. |
| high | `dotenv-not-ignored` | A `.env` file is in the tree and `.gitignore` does not cover it (`.env`, `.env*`, `*.env`, …). |
| medium | `debug-leftover` | `dd()`, `dump()`, `ray()`, `var_dump()`, `print_r()` outside `tests/`. |
| medium | `model-unguard` | `Model::unguard()`. Sometimes intentional. Default CI does not fail on medium. |

`@env('local')` in Blade is **not** flagged. That directive compiles to `app()->environment()`, which reads `config('app.env')`. `{{ env('APP_NAME') }}` **is** flagged.

## Usage

```bash
php envtrap.php                      # scan the current directory
php envtrap.php /var/www/app
php envtrap.php --json               # stdout is JSON only
php envtrap.php --fail-on=medium     # also fail on dd() and Model::unguard()
php envtrap.php --fail-on=never      # always exit 0, still print the report
php envtrap.php --no-color
php envtrap.php --self-test          # built-in fixtures, no network
```

Exit `0` when nothing reaches the fail level (default: `high`). Exit `1` when something does. Exit `2` when the path or the flags are wrong.

PHP 8.1+ with the `tokenizer` extension, which PHP ships by default. The script tokenizes PHP. It does not `include` your application, so a syntax error in the project does not crash the scan, and a `dd()` in the project does not run.

Skipped directories: `vendor/`, `node_modules/`, `.git/`, `storage/`, `bootstrap/cache/`, `public/build/`, `public/hot/`, `public/storage/`.

## CI

```yaml
- uses: shivammathur/setup-php@v2
  with:
    php-version: '8.3'
    coverage: none
- name: envtrap
  run: php envtrap.php --fail-on=high
```

Or, without adding the file to the app, from GitHub Actions:

```yaml
- name: envtrap
  run: |
    curl -fsSL -o /tmp/envtrap.php \
      https://raw.githubusercontent.com/Nguemoue/project2026_20261029_envtrap/main/envtrap.php
    php /tmp/envtrap.php .
```

## Install

**Copy the file.** That is the supported install.

```bash
curl -fsSL -o envtrap.php \
  https://raw.githubusercontent.com/Nguemoue/project2026_20261029_envtrap/main/envtrap.php
```

`composer.json` is included so you can point a path repository at this git URL. The package is not required to be on Packagist.

## What this is not

- Not a full security audit. SQL detection is a heuristic. A binding hidden behind a helper will be missed. A concatenated literal with no variable will not be flagged.
- Not a substitute for code review. `$guarded = ['id']` can still be the wrong list. envtrap only catches the empty one.
- It does not upload code anywhere. Scanning is local.

## Le piège, en français

La doc Laravel le dit : `env()` ne se lit que dans `config/`. Partout ailleurs, c'est `config()`. Pourtant on écrit encore, dans un contrôleur :

```php
$secret = env('STRIPE_SECRET');
```

Ça marche sur le portable, parce que `.env` est chargé. Au déploiement, `php artisan config:cache` (Forge, Vapor, Docker, la CI) met la config en cache et **ne recharge plus `.env`**. L'appel renvoie `null`, ou la valeur par défaut du deuxième argument — jamais la ligne du `.env` — sauf si la variable est aussi exportée par le système. Stripe reçoit `null`. Uniquement en production. La stack trace accuse le SDK.

`getenv()` et `Env::get()` font la même chose.

Le correctif :

```php
// config/services.php
'stripe' => ['secret' => env('STRIPE_SECRET')],

// le contrôleur
$secret = config('services.stripe.secret');
```

envtrap sert à voir ça dans la CI, sur la machine qui a encore le code source.

Le fichier se suffit. `php envtrap.php --self-test` rejoue les cas limites (commentaires, chaînes, `->env()`, `::env()`, `@env` dans Blade, bindings `?`, `.gitignore` qui contient `*.env`). Le code scanné n'est jamais exécuté et ne quitte pas la machine.

## License

MIT. See [LICENSE](LICENSE).
