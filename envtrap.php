#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * envtrap — catch the Laravel config:cache footgun before it hits production.
 *
 * After `php artisan config:cache`, Laravel does not load `.env`.
 * `env()` / `getenv()` / `Env::get()` outside `config/` then miss every
 * value that lives only in `.env` (they return null, or the default
 * argument — never the .env value — unless the OS itself exports the var).
 *
 * This file is the whole tool. No Composer. No framework. No network.
 * It tokenizes your code; it never executes it.
 *
 *   php envtrap.php [path] [--json] [--fail-on=high|medium|low|never] [--no-color]
 *   php envtrap.php --self-test
 */

const ENVTRAP_VERSION = '1.0.0';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "envtrap runs in a terminal, not as a web page.\n");
    exit(2);
}

if (!extension_loaded('tokenizer')) {
    fwrite(STDERR, "envtrap needs the tokenizer extension (shipped with PHP).\n");
    exit(2);
}

$options = envtrap_parse_args($argv);
if ($options['help']) {
    envtrap_help();
    exit(0);
}
if ($options['self_test']) {
    exit(envtrap_self_test($argv[0]));
}

$path = $options['path'];
if (!file_exists($path)) {
    fwrite(STDERR, "Path not found: {$path}\n");
    exit(2);
}

$root = realpath($path);
if ($root === false) {
    fwrite(STDERR, "Path not readable: {$path}\n");
    exit(2);
}

if (is_file($root)) {
    $scanRoot = dirname($root);
    $only = [ltrim(str_replace('\\', '/', substr($root, strlen($scanRoot))), '/')];
    $report = envtrap_scan($scanRoot, $only);
} else {
    $report = envtrap_scan($root, null);
}

$useColor = $options['color'] && !$options['json'];
$fail = envtrap_should_fail($report['findings'], $options['fail_on']);

if ($options['json']) {
    echo json_encode([
        'tool' => 'envtrap',
        'version' => ENVTRAP_VERSION,
        'path' => $root,
        'scanned_files' => $report['scanned_files'],
        'fail_on' => $options['fail_on'],
        'ok' => !$fail,
        'counts' => $report['counts'],
        'findings' => $report['findings'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    envtrap_render($report, $useColor, $options['fail_on'], $fail);
}

exit($fail ? 1 : 0);

/**
 * @param list<string> $argv
 * @return array{path:string,json:bool,color:bool,fail_on:string,self_test:bool,help:bool}
 */
function envtrap_parse_args(array $argv): array
{
    $opts = [
        'path' => '.',
        'json' => false,
        'color' => getenv('NO_COLOR') === false,
        'fail_on' => 'high',
        'self_test' => false,
        'help' => false,
    ];
    $pathSet = false;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $opts['json'] = true;
        } elseif ($arg === '--no-color') {
            $opts['color'] = false;
        } elseif ($arg === '--self-test') {
            $opts['self_test'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            $opts['help'] = true;
        } elseif (str_starts_with($arg, '--fail-on=')) {
            $level = substr($arg, strlen('--fail-on='));
            if (!in_array($level, ['high', 'medium', 'low', 'never'], true)) {
                fwrite(STDERR, "Unknown --fail-on level \"{$level}\". Use high, medium, low, or never.\n");
                exit(2);
            }
            $opts['fail_on'] = $level;
        } elseif (str_starts_with($arg, '-')) {
            fwrite(STDERR, "Unknown option {$arg}. Try --help.\n");
            exit(2);
        } elseif (!$pathSet) {
            $opts['path'] = $arg;
            $pathSet = true;
        } else {
            fwrite(STDERR, "Unexpected argument {$arg}. Pass a single path.\n");
            exit(2);
        }
    }

    if (!$opts['color'] || !function_exists('stream_isatty') || !stream_isatty(STDOUT)) {
        if ($opts['color'] && function_exists('stream_isatty') && !stream_isatty(STDOUT)) {
            $opts['color'] = false;
        }
    }

    return $opts;
}

function envtrap_help(): void
{
    $v = ENVTRAP_VERSION;
    echo <<<TXT
envtrap {$v}
Catch Laravel production footguns before config:cache hides them.

Usage:
  php envtrap.php [path] [options]

Options:
  --json                         Machine-readable report on stdout
  --fail-on=high|medium|low|never
                                 Exit 1 at this severity or above (default: high)
  --no-color                     Plain text
  --self-test                    Run the built-in checks and exit
  -h, --help                     Show this help

Exit codes:
  0  nothing at or above the fail level
  1  findings at or above the fail level
  2  bad usage, or the path cannot be read

What it flags:
  high    env() / getenv() / Env::get() outside config/
  high    \$guarded = []
  high    raw SQL built by concatenating variables
  high    APP_DEBUG / APP_ENV=local / empty APP_KEY in .env.production|.env.prod|.env.staging
  high    a .env file whose name is not covered by .gitignore
  medium  dd(), dump(), ray(), var_dump(), print_r() outside tests/
  medium  Model::unguard()
  low     env() inside tests/

Your code stays on this machine. envtrap never executes it and never sends it anywhere.

TXT;
}

/**
 * @param list<string>|null $onlyRel
 * @return array{scanned_files:int,counts:array{high:int,medium:int,low:int},findings:list<array<string,mixed>>}
 */
function envtrap_scan(string $root, ?array $onlyRel): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $files = envtrap_collect($root, $onlyRel);
    $gitignore = '';
    $giPath = $root . '/.gitignore';
    if (is_file($giPath)) {
        $gitignore = (string) file_get_contents($giPath);
    }

    $findings = [];
    $scanned = 0;

    foreach ($files as $rel => $abs) {
        if (!is_file($abs)) {
            continue;
        }
        $size = filesize($abs);
        if ($size === false || $size > 1500000) {
            continue;
        }
        $code = file_get_contents($abs);
        if ($code === false || str_contains($code, "\0")) {
            continue;
        }
        $scanned++;
        $base = basename($rel);

        if ($base === '.env' || preg_match('/^\.env\.(production|prod|staging)$/', $base) === 1) {
            $findings = array_merge($findings, envtrap_scan_env_file($rel, $code, $gitignore));
            continue;
        }

        if (str_ends_with($base, '.blade.php')) {
            $findings = array_merge($findings, envtrap_scan_blade($rel, $code));
            continue;
        }

        if (str_ends_with($base, '.php')) {
            $findings = array_merge($findings, envtrap_scan_php($rel, $code));
        }
    }

    usort($findings, static function (array $a, array $b): int {
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        return [$rank[$a['severity']], $a['file'], $a['line'], $a['rule']]
            <=> [$rank[$b['severity']], $b['file'], $b['line'], $b['rule']];
    });

    $counts = ['high' => 0, 'medium' => 0, 'low' => 0];
    foreach ($findings as $finding) {
        $counts[$finding['severity']]++;
    }

    return [
        'scanned_files' => $scanned,
        'counts' => $counts,
        'findings' => $findings,
    ];
}

/**
 * @param list<string>|null $onlyRel
 * @return array<string,string> relative path => absolute path
 */
function envtrap_collect(string $root, ?array $onlyRel): array
{
    if ($onlyRel !== null) {
        $out = [];
        foreach ($onlyRel as $rel) {
            $rel = ltrim(str_replace('\\', '/', $rel), '/');
            $out[$rel] = $root . '/' . $rel;
        }
        return $out;
    }

    $out = [];
    envtrap_walk($root, $root, $out);
    return $out;
}

/**
 * @param array<string,string> $out
 */
function envtrap_walk(string $dir, string $root, array &$out): void
{
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $abs = $dir . '/' . $item;
        if (is_link($abs)) {
            continue;
        }
        $rel = ltrim(substr(str_replace('\\', '/', $abs), strlen($root)), '/');
        if (is_dir($abs)) {
            if (in_array($item, ['vendor', 'node_modules', '.git', '.idea', '.vscode'], true)) {
                continue;
            }
            if (in_array($rel, ['storage', 'bootstrap/cache', 'public/build', 'public/hot', 'public/storage'], true)) {
                continue;
            }
            envtrap_walk($abs, $root, $out);
            continue;
        }
        if (!is_file($abs)) {
            continue;
        }
        if (!envtrap_interesting_file($item)) {
            continue;
        }
        $out[$rel] = $abs;
    }
}

/**
 * @return list<array<string,mixed>>
 */
function envtrap_scan_php(string $rel, string $code): array
{
    $findings = [];
    $isConfig = envtrap_is_config($rel);
    $inTests = envtrap_in_tests($rel);
    $tokens = PhpToken::tokenize($code);
    $count = count($tokens);

    $calls = [
        'env' => true,
        'getenv' => true,
        'dd' => true,
        'dump' => true,
        'ray' => true,
        'var_dump' => true,
        'print_r' => true,
        'unguard' => true,
        'whereraw' => true,
        'orwhereraw' => true,
        'havingraw' => true,
        'orhavingraw' => true,
        'orderbyraw' => true,
        'groupbyraw' => true,
        'selectraw' => true,
        'get' => true,
        'raw' => true,
    ];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if ($token->id !== T_STRING || !isset($calls[strtolower($token->text)])) {
            continue;
        }
        $name = strtolower($token->text);
        $prev = envtrap_prev($tokens, $i);
        $next = envtrap_next($tokens, $i);
        if ($next === null || $next->text !== '(') {
            continue;
        }
        if ($prev !== null && $prev->id === T_FUNCTION) {
            continue;
        }

        $isObjectCall = $prev !== null && in_array($prev->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);

        if ($name === 'get') {
            if (!$isObjectCall || $prev->id !== T_DOUBLE_COLON) {
                continue;
            }
            $class = envtrap_prev($tokens, envtrap_index_of($tokens, $prev));
            if ($class === null || strcasecmp(envtrap_class_basename($class->text), 'Env') !== 0) {
                continue;
            }
            if (!$isConfig) {
                $findings[] = envtrap_finding(
                    $inTests ? 'low' : 'high',
                    'env-outside-config',
                    $rel,
                    $token->line,
                    'Env::get() outside config/ does not read .env after config:cache. Use config() instead.',
                    envtrap_line_at($code, $token->pos)
                );
            }
            continue;
        }

        if ($name === 'raw') {
            if ($prev === null || $prev->id !== T_DOUBLE_COLON) {
                continue;
            }
            $class = envtrap_prev($tokens, envtrap_index_of($tokens, $prev));
            if ($class === null || strcasecmp(envtrap_class_basename($class->text), 'DB') !== 0) {
                continue;
            }
            $arg = envtrap_first_arg($code, $tokens, envtrap_index_of($tokens, $next));
            if (envtrap_sql_is_dangerous($arg)) {
                $findings[] = envtrap_finding(
                    'high',
                    'raw-sql-interpolation',
                    $rel,
                    $token->line,
                    'DB::raw() builds SQL with a variable. Bind it with ? instead of interpolating.',
                    envtrap_line_at($code, $token->pos)
                );
            }
            continue;
        }

        if (in_array($name, ['whereraw', 'orwhereraw', 'havingraw', 'orhavingraw', 'orderbyraw', 'groupbyraw', 'selectraw'], true)) {
            $arg = envtrap_first_arg($code, $tokens, envtrap_index_of($tokens, $next));
            if (envtrap_sql_is_dangerous($arg)) {
                $findings[] = envtrap_finding(
                    'high',
                    'raw-sql-interpolation',
                    $rel,
                    $token->line,
                    'Raw SQL is built with string interpolation. Use a ? binding instead.',
                    envtrap_line_at($code, $token->pos)
                );
            }
            continue;
        }

        if ($name === 'unguard') {
            $findings[] = envtrap_finding(
                'medium',
                'model-unguard',
                $rel,
                $token->line,
                'Model::unguard() turns off mass-assignment protection for the rest of the request.',
                envtrap_line_at($code, $token->pos)
            );
            continue;
        }

        if (in_array($name, ['env', 'getenv'], true)) {
            if ($isObjectCall) {
                continue;
            }
            if ($isConfig) {
                continue;
            }
            $findings[] = envtrap_finding(
                $inTests ? 'low' : 'high',
                'env-outside-config',
                $rel,
                $token->line,
                $name . '() outside config/ does not read .env after config:cache. Use config() instead.',
                envtrap_line_at($code, $token->pos)
            );
            continue;
        }

        // dd, dump, ray, var_dump, print_r
        if ($isObjectCall || $inTests) {
            continue;
        }
        $findings[] = envtrap_finding(
            'medium',
            'debug-leftover',
            $rel,
            $token->line,
            'Debug call left in application code (' . $token->text . ').',
            envtrap_line_at($code, $token->pos)
        );
    }

    $findings = array_merge($findings, envtrap_scan_guarded($rel, $tokens, $code));

    return $findings;
}

/**
 * @param list<PhpToken> $tokens
 * @return list<array<string,mixed>>
 */
function envtrap_scan_guarded(string $rel, array $tokens, string $code): array
{
    $findings = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if ($token->id !== T_VARIABLE || $token->text !== '$guarded') {
            continue;
        }
        $eq = envtrap_next($tokens, $i);
        if ($eq === null || $eq->text !== '=') {
            continue;
        }
        $eqIndex = envtrap_index_of($tokens, $eq);
        $value = envtrap_next($tokens, $eqIndex);
        if ($value === null) {
            continue;
        }
        $empty = false;
        if ($value->text === '[') {
            $empty = envtrap_bracket_empty($tokens, envtrap_index_of($tokens, $value));
        } elseif (strcasecmp($value->text, 'array') === 0) {
            $paren = envtrap_next($tokens, envtrap_index_of($tokens, $value));
            if ($paren !== null && $paren->text === '(') {
                $empty = envtrap_bracket_empty($tokens, envtrap_index_of($tokens, $paren));
            }
        }
        if ($empty) {
            $findings[] = envtrap_finding(
                'high',
                'unguarded-model',
                $rel,
                $token->line,
                'Mass assignment is wide open ($guarded = []). Set $fillable, or a real deny-list.',
                envtrap_line_at($code, $token->pos)
            );
        }
    }
    return $findings;
}

/**
 * @param list<PhpToken> $tokens
 */
function envtrap_bracket_empty(array $tokens, int $openIdx): bool
{
    $open = $tokens[$openIdx]->text;
    $close = $open === '[' ? ']' : ')';
    $depth = 0;
    $total = count($tokens);
    for ($k = $openIdx; $k < $total; $k++) {
        $text = $tokens[$k]->text;
        if ($text === $open) {
            if ($depth === 1) {
                return false;
            }
            $depth++;
            continue;
        }
        if ($text === $close) {
            $depth--;
            if ($depth === 0) {
                return true;
            }
            continue;
        }
        if ($depth === 1) {
            $id = $tokens[$k]->id;
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            return false;
        }
    }
    return false;
}

/**
 * @return list<array<string,mixed>>
 */
function envtrap_scan_blade(string $rel, string $code): array
{
    $stripped = preg_replace('/\{\{--.*?--\}\}/s', '', $code) ?? $code;
    $stripped = preg_replace('/<!--.*?-->/s', '', $stripped) ?? $stripped;
    $findings = [];
    $lines = preg_split('/\R/', $stripped) ?: [];
    foreach ($lines as $idx => $line) {
        $lineNo = $idx + 1;
        if (preg_match('/(?<!@)(?<!->)(?<!::)\b(env|getenv)\s*\(/i', $line, $m) === 1) {
            $findings[] = envtrap_finding(
                'high',
                'env-outside-config',
                $rel,
                $lineNo,
                $m[1] . '() in a Blade view does not read .env after config:cache. Pass config() from the controller, or use the @env directive to branch on the environment.',
                envtrap_trim_snippet($line)
            );
        }
        if (preg_match('/(?<!->)(?<!::)\b(dd|dump|ray|var_dump|print_r)\s*\(/i', $line, $m) === 1) {
            $findings[] = envtrap_finding(
                'medium',
                'debug-leftover',
                $rel,
                $lineNo,
                'Debug call left in a Blade view (' . $m[1] . ').',
                envtrap_trim_snippet($line)
            );
        }
    }
    return $findings;
}

/**
 * @return list<array<string,mixed>>
 */
function envtrap_scan_env_file(string $rel, string $code, string $gitignore): array
{
    $findings = [];
    $base = basename($rel);
    if ($base === '.env' && !envtrap_gitignore_covers_dotenv($gitignore)) {
        $findings[] = envtrap_finding(
            'high',
            'dotenv-not-ignored',
            $rel,
            1,
            '.env is in the tree and .gitignore does not ignore it. A commit would publish its secrets.',
            '.env'
        );
    }

    if (preg_match('/^\.env\.(production|prod|staging)$/', $base) !== 1) {
        return $findings;
    }

    $lines = preg_split('/\R/', $code) ?: [];
    foreach ($lines as $idx => $line) {
        $parsed = envtrap_parse_env_assignment($line);
        if ($parsed === null) {
            continue;
        }
        [$key, $value] = $parsed;
        $lineNo = $idx + 1;
        if ($key === 'APP_DEBUG' && in_array(strtolower($value), ['true', '1', 'yes', 'on'], true)) {
            $findings[] = envtrap_finding(
                'high',
                'production-debug',
                $rel,
                $lineNo,
                'APP_DEBUG is enabled in a production or staging env file.',
                'APP_DEBUG=' . $value
            );
        }
        if ($key === 'APP_ENV' && in_array(strtolower($value), ['local', 'dev', 'development'], true)) {
            $findings[] = envtrap_finding(
                'high',
                'production-app-env',
                $rel,
                $lineNo,
                'APP_ENV is still local/dev in a production or staging env file.',
                'APP_ENV=' . $value
            );
        }
        if ($key === 'APP_KEY' && ($value === '' || strtolower($value) === 'null' || $value === 'base64:')) {
            $findings[] = envtrap_finding(
                'high',
                'production-empty-key',
                $rel,
                $lineNo,
                'APP_KEY is empty in a production or staging env file. Cookies and encrypted data will not round-trip.',
                'APP_KEY='
            );
        }
    }

    return $findings;
}

/**
 * @return array{0:string,1:string}|null
 */
function envtrap_parse_env_assignment(string $line): ?array
{
    $trim = trim($line);
    if ($trim === '' || str_starts_with($trim, '#')) {
        return null;
    }
    if (str_starts_with($trim, 'export ')) {
        $trim = trim(substr($trim, 7));
    }
    $eq = strpos($trim, '=');
    if ($eq === false) {
        return null;
    }
    $key = trim(substr($trim, 0, $eq));
    $value = trim(substr($trim, $eq + 1));
    if ($key === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
        return null;
    }
    $quoted = false;
    if (strlen($value) >= 2) {
        $a = $value[0];
        $b = $value[strlen($value) - 1];
        if (($a === '"' && $b === '"') || ($a === "'" && $b === "'")) {
            $value = substr($value, 1, -1);
            $quoted = true;
        }
    }
    if (!$quoted) {
        $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
        $value = trim($value);
    }
    return [$key, $value];
}

function envtrap_gitignore_covers_dotenv(string $gitignore): bool
{
    if ($gitignore === '') {
        return false;
    }
    foreach (preg_split('/\R/', $gitignore) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '!')) {
            continue;
        }
        $line = str_replace('\\', '/', $line);
        $line = rtrim($line, '/');
        if (in_array($line, ['.env', '/.env', '.env*', '/.env*', '**/.env', '**/.env*', '*.env', '/*.env', '**/*.env', '*'], true)) {
            return true;
        }
    }
    return false;
}

function envtrap_sql_is_dangerous(string $arg): bool
{
    if ($arg === '') {
        return false;
    }
    if (str_contains($arg, '{$')) {
        return true;
    }
    if (preg_match('/\.\s*\$/', $arg) === 1 || preg_match('/\$\w+\s*\./', $arg) === 1) {
        return true;
    }
    if (preg_match('/"(?:[^"\\\\]|\\\\.)*\\$(?:\\w|\\{)/', $arg) === 1) {
        return true;
    }
    return false;
}

/**
 * @param list<PhpToken> $tokens
 */
function envtrap_first_arg(string $code, array $tokens, int $parenIndex): string
{
    $depth = 0;
    $start = $tokens[$parenIndex]->pos;
    $sliceEnd = null;
    $total = count($tokens);
    for ($k = $parenIndex; $k < $total; $k++) {
        $text = $tokens[$k]->text;
        if ($text === '(') {
            $depth++;
        } elseif ($text === ')') {
            $depth--;
            if ($depth === 0) {
                if ($sliceEnd === null) {
                    $sliceEnd = $tokens[$k]->pos;
                }
                break;
            }
        } elseif ($text === ',' && $depth === 1 && $sliceEnd === null) {
            $sliceEnd = $tokens[$k]->pos;
        }
    }
    if ($sliceEnd === null) {
        return '';
    }
    return substr($code, $start + 1, $sliceEnd - ($start + 1));
}

/**
 * @param list<PhpToken> $tokens
 */
function envtrap_prev(array $tokens, int $i): ?PhpToken
{
    for ($j = $i - 1; $j >= 0; $j--) {
        $id = $tokens[$j]->id;
        if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
            continue;
        }
        return $tokens[$j];
    }
    return null;
}

/**
 * @param list<PhpToken> $tokens
 */
function envtrap_next(array $tokens, int $i): ?PhpToken
{
    $total = count($tokens);
    for ($j = $i + 1; $j < $total; $j++) {
        $id = $tokens[$j]->id;
        if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
            continue;
        }
        return $tokens[$j];
    }
    return null;
}

/**
 * @param list<PhpToken> $tokens
 */
function envtrap_index_of(array $tokens, PhpToken $needle): int
{
    $index = array_search($needle, $tokens, true);
    if ($index === false) {
        throw new RuntimeException('Token disappeared while scanning.');
    }
    return (int) $index;
}

function envtrap_class_basename(string $name): string
{
    $name = ltrim($name, '\\');
    $pos = strrpos($name, '\\');
    return $pos === false ? $name : substr($name, $pos + 1);
}

function envtrap_interesting_file(string $name): bool
{
    if (str_ends_with($name, '.php')) {
        return true;
    }
    return $name === '.env' || preg_match('/^\.env\.(production|prod|staging)$/', $name) === 1;
}

function envtrap_is_config(string $rel): bool
{
    return preg_match('#(^|/)config/#', str_replace('\\', '/', $rel)) === 1;
}

function envtrap_in_tests(string $rel): bool
{
    return preg_match('#(^|/)tests/#', str_replace('\\', '/', $rel)) === 1;
}

function envtrap_line_at(string $code, int $pos): string
{
    $before = substr($code, 0, $pos);
    $start = strrpos($before, "\n");
    $start = $start === false ? 0 : $start + 1;
    $end = strpos($code, "\n", $pos);
    if ($end === false) {
        $end = strlen($code);
    }
    return envtrap_trim_snippet(substr($code, $start, $end - $start));
}

function envtrap_trim_snippet(string $line): string
{
    $line = trim($line);
    if (strlen($line) > 140) {
        return substr($line, 0, 137) . '...';
    }
    return $line;
}

/**
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function envtrap_finding(string $severity, string $rule, string $file, int $line, string $message, string $snippet): array
{
    return [
        'severity' => $severity,
        'rule' => $rule,
        'file' => str_replace('\\', '/', $file),
        'line' => $line,
        'message' => $message,
        'snippet' => $snippet,
    ];
}

/**
 * @param list<array<string,mixed>> $findings
 */
function envtrap_should_fail(array $findings, string $failOn): bool
{
    if ($failOn === 'never') {
        return false;
    }
    $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
    $threshold = $rank[$failOn];
    foreach ($findings as $finding) {
        if ($rank[$finding['severity']] >= $threshold) {
            return true;
        }
    }
    return false;
}

/**
 * @param array{scanned_files:int,counts:array{high:int,medium:int,low:int},findings:list<array<string,mixed>>} $report
 */
function envtrap_render(array $report, bool $color, string $failOn, bool $fail): void
{
    $c = static function (string $text, string $code) use ($color): string {
        return $color ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    };

    echo $c('envtrap', '1;36') . ' ' . ENVTRAP_VERSION . "\n";

    if ($report['findings'] === []) {
        echo $c('No findings.', '0;32') . ' Scanned ' . $report['scanned_files'] . " files.\n";
        return;
    }

    $sevColor = ['high' => '1;31', 'medium' => '1;33', 'low' => '0;34'];
    foreach ($report['findings'] as $finding) {
        $label = strtoupper($finding['severity']);
        echo $c(str_pad($label, 6), $sevColor[$finding['severity']])
            . '  ' . $c($finding['rule'], '0;2')
            . '  ' . $c($finding['file'] . ':' . $finding['line'], '0;36')
            . "\n";
        echo '        ' . $finding['message'] . "\n";
        if ($finding['snippet'] !== '') {
            echo '        ' . $c($finding['snippet'], '0;2') . "\n";
        }
    }

    $counts = $report['counts'];
    echo "\n" . $report['scanned_files'] . ' files, '
        . count($report['findings']) . ' findings ('
        . $counts['high'] . ' high, '
        . $counts['medium'] . ' medium, '
        . $counts['low'] . " low)\n";

    if ($fail) {
        echo $c('Exit 1', '1;31') . ": findings at or above {$failOn}.\n";
    } else {
        echo $c('Exit 0', '0;32') . ": nothing at or above {$failOn}.\n";
    }
}

function envtrap_self_test(string $script): int
{
    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        if (!$ok) {
            $failures[] = $message;
        }
    };

    $dirty = envtrap_make_tree([
        'app/Pay.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

// env('NOT_REAL')
/* getenv('NOT_REAL') */
$s = "call env('NOT_REAL') later";
$obj->env('NOT_REAL');
Helper::getenv('NOT_REAL');

$stripe = env('STRIPE_SECRET');
$host = getenv('MAIL_HOST');
$key = \Illuminate\Support\Env::get('APP_KEY');

dd($stripe);
dump($host);
ray($key);
var_dump($stripe);
print_r($host);

$q->whereRaw('id = ?', [$id]);
$q->whereRaw("json_col->'$.name' = ?", [$name]);
$q->whereRaw("id = {$id}");
$q->whereRaw('name = ' . $name);
DB::raw("select * from users where email = '$email'");
PHP,
        'app/Models/User.php' => <<<'PHP'
<?php

namespace App\Models;

class User
{
    protected $guarded = [
        // intentionally empty
    ];
}
PHP,
        'app/Models/Post.php' => <<<'PHP'
<?php

namespace App\Models;

class Post
{
    protected $guarded = array();
}
PHP,
        'app/Models/SafeModel.php' => <<<'PHP'
<?php

namespace App\Models;

class SafeModel
{
    protected $fillable = ['name'];
    protected $guarded = ['password', 'role'];
}
PHP,
        'app/Providers/AppServiceProvider.php' => <<<'PHP'
<?php

namespace App\Providers;

class AppServiceProvider
{
    public function boot(): void
    {
        Model::unguard();
    }
}
PHP,
        'app/Safe.php' => <<<'PHP'
<?php

// env('COMMENT_ONLY')
$s = 'env("STILL_A_STRING")';
$obj->dump('nope');
$obj->dd('nope');
config('services.stripe.secret');
function env($key, $default = null)
{
    return $default;
}
PHP,
        'config/services.php' => <<<'PHP'
<?php

return [
    'secret' => env('STRIPE_SECRET'),
    'host' => getenv('MAIL_HOST'),
    'key' => Env::get('APP_KEY'),
];
PHP,
        'resources/views/home.blade.php' => <<<'BLADE'
@env('local')
    <p>Local chrome</p>
@endenv
{{-- env('HIDDEN') --}}
<p>{{ env('APP_NAME') }}</p>
<p>{{ $user->env('display') }}</p>
BLADE,
        'routes/web.php' => <<<'PHP'
<?php

$host = getenv('PUSHER_HOST');
PHP,
        'tests/Feature/ExampleTest.php' => <<<'PHP'
<?php

env('FOO');
dd('leave me, I am a test');
PHP,
        '.env.production' => <<<'ENV'
APP_NAME=Acme
APP_ENV=local
APP_DEBUG=true
APP_KEY=
ENV,
        '.env' => "APP_NAME=Acme\nAPP_KEY=base64:not-a-real-key\n",
        '.gitignore' => "/vendor\n/node_modules\n",
    ]);

    $report = envtrap_scan($dirty, null);
    $seen = [];
    foreach ($report['findings'] as $finding) {
        $seen[] = $finding['severity'] . '|' . $finding['rule'] . '|' . $finding['file'];
    }
    sort($seen);

    $expected = [
        'high|dotenv-not-ignored|.env',
        'high|env-outside-config|app/Pay.php',
        'high|env-outside-config|app/Pay.php',
        'high|env-outside-config|app/Pay.php',
        'high|env-outside-config|resources/views/home.blade.php',
        'high|env-outside-config|routes/web.php',
        'high|production-app-env|.env.production',
        'high|production-debug|.env.production',
        'high|production-empty-key|.env.production',
        'high|raw-sql-interpolation|app/Pay.php',
        'high|raw-sql-interpolation|app/Pay.php',
        'high|raw-sql-interpolation|app/Pay.php',
        'high|unguarded-model|app/Models/Post.php',
        'high|unguarded-model|app/Models/User.php',
        'low|env-outside-config|tests/Feature/ExampleTest.php',
        'medium|debug-leftover|app/Pay.php',
        'medium|debug-leftover|app/Pay.php',
        'medium|debug-leftover|app/Pay.php',
        'medium|debug-leftover|app/Pay.php',
        'medium|debug-leftover|app/Pay.php',
        'medium|model-unguard|app/Providers/AppServiceProvider.php',
    ];
    sort($expected);
    $check($seen === $expected, "Dirty tree findings mismatch.\nExpected:\n" . implode("\n", $expected) . "\nActual:\n" . implode("\n", $seen));

    $bladeLines = [];
    foreach ($report['findings'] as $finding) {
        if ($finding['file'] === 'resources/views/home.blade.php') {
            $bladeLines[] = $finding['line'] . ':' . $finding['rule'];
        }
    }
    $check($bladeLines === ['5:env-outside-config'], 'Blade false positive. Got ' . implode(', ', $bladeLines));

    $snippets = array_map(static fn (array $f): string => $f['file'] . ' ' . $f['snippet'], $report['findings']);
    $blob = implode("\n", $snippets);
    $check(!str_contains($blob, 'NOT_REAL'), 'A comment or string was flagged: ' . $blob);
    $check(!str_contains($blob, 'not-a-real-key'), 'A secret value was printed in a snippet.');
    $check(!str_contains($blob, 'HIDDEN'), 'A Blade comment was flagged.');

    foreach ($report['findings'] as $finding) {
        if (str_starts_with($finding['file'], 'config/')) {
            $failures[] = 'config/ was flagged: ' . $finding['rule'] . ' ' . $finding['snippet'];
        }
        if ($finding['file'] === 'app/Safe.php' || $finding['file'] === 'app/Models/SafeModel.php') {
            $failures[] = 'Safe file was flagged: ' . $finding['file'] . ' ' . $finding['snippet'];
        }
        if ($finding['file'] === 'tests/Feature/ExampleTest.php' && $finding['rule'] === 'debug-leftover') {
            $failures[] = 'dd() inside tests/ should not be flagged.';
        }
    }

    $clean = envtrap_make_tree([
        'config/app.php' => "<?php\nreturn ['name' => env('APP_NAME', 'Laravel')];\n",
        'app/Models/User.php' => "<?php\nclass User { protected \$fillable = ['name']; }\n",
        'resources/views/home.blade.php' => "@env('local')\nok\n@endenv\n",
        '.env' => "APP_DEBUG=true\nAPP_KEY=base64:local-only\n",
        '.gitignore' => ".env\n/vendor\n",
        '.env.production' => "APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:placeholder-not-empty\n",
    ]);
    $cleanReport = envtrap_scan($clean, null);
    $check($cleanReport['findings'] === [], 'Clean tree should have zero findings, got ' . json_encode($cleanReport['findings']));

    $starIgnore = envtrap_make_tree([
        '.env' => "APP_KEY=base64:local-only\n",
        '.gitignore' => "*.env\n",
        'config/app.php' => "<?php\nreturn ['name' => env('APP_NAME')];\n",
    ]);
    $starReport = envtrap_scan($starIgnore, null);
    $check($starReport['findings'] === [], '*.env in .gitignore should cover .env. Got ' . json_encode($starReport['findings']));

    [$code, $stdout, $stderr] = envtrap_proc($script, [$dirty, '--json', '--no-color']);
    $check($code === 1, "Dirty JSON run should exit 1, got {$code}. stderr={$stderr}");
    $decoded = json_decode($stdout, true);
    $check(is_array($decoded), 'JSON stdout was not valid JSON: ' . $stdout);
    if (is_array($decoded)) {
        $check(($decoded['ok'] ?? null) === false, 'JSON ok should be false on a dirty tree.');
        $check(($decoded['version'] ?? null) === ENVTRAP_VERSION, 'JSON version mismatch.');
        $check(($decoded['counts']['high'] ?? null) === 14, 'Expected 14 high findings, got ' . ($decoded['counts']['high'] ?? 'null'));
    }

    [$code, $stdout, $stderr] = envtrap_proc($script, [$dirty, '--fail-on=never', '--no-color']);
    $check($code === 0, "fail-on=never should exit 0, got {$code}. stderr={$stderr}");
    $check(str_contains($stdout, 'env-outside-config'), 'Human report should list the rule.');

    [$code, $stdout, $stderr] = envtrap_proc($script, [$clean, '--no-color']);
    $check($code === 0, "Clean tree should exit 0, got {$code}. stdout={$stdout} stderr={$stderr}");
    $check(str_contains($stdout, 'No findings.'), 'Clean report should say No findings. Got: ' . $stdout);

    [$code, $stdout, $stderr] = envtrap_proc($script, ['--help']);
    $check($code === 0 && str_contains($stdout, 'config:cache'), 'Help text missing. stderr=' . $stderr);

    [$code, $stdout, $stderr] = envtrap_proc($script, ['--wat']);
    $check($code === 2, "Unknown option should exit 2, got {$code}");

    [$code] = envtrap_proc($script, [$dirty . '/does-not-exist']);
    $check($code === 2, 'Missing path should exit 2.');

    $lowOnly = envtrap_make_tree([
        'tests/Unit/EnvTest.php' => "<?php\nenv('FOO');\n",
        '.gitignore' => ".env\n",
    ]);
    [$code] = envtrap_proc($script, [$lowOnly, '--no-color']);
    $check($code === 0, 'Low findings should not fail the default high threshold.');
    [$code] = envtrap_proc($script, [$lowOnly, '--fail-on=low', '--no-color']);
    $check($code === 1, 'fail-on=low should fail when tests call env().');

    if ($failures !== []) {
        fwrite(STDERR, "envtrap self-test failed (" . count($failures) . "):\n - " . implode("\n - ", $failures) . "\n");
        return 1;
    }

    echo "envtrap self-test passed (" . ENVTRAP_VERSION . ").\n";
    return 0;
}

/**
 * @param array<string,string> $files
 */
function envtrap_make_tree(array $files): string
{
    $dir = sys_get_temp_dir() . '/envtrap-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        fwrite(STDERR, "Could not create temp directory {$dir}\n");
        exit(1);
    }
    foreach ($files as $rel => $content) {
        $path = $dir . '/' . $rel;
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            fwrite(STDERR, "Could not create {$parent}\n");
            exit(1);
        }
        file_put_contents($path, $content);
    }
    return $dir;
}

/**
 * @param list<string> $args
 * @return array{0:int,1:string,2:string}
 */
function envtrap_proc(string $script, array $args): array
{
    $command = array_merge([PHP_BINARY, $script], $args);
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $spec, $pipes);
    if (!is_resource($process)) {
        return [127, '', 'proc_open failed'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout, (string) $stderr];
}
