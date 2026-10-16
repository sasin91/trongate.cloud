<?php

/**
 * Tests for Build_commands: the build commands suggested from a
 * repository's root, their checks, and the Dockerfile they build with.
 *
 * Run: php tests/Build_commands_test.php (exits non-zero on a failure)
 */

require_once dirname(__DIR__) . '/modules/platform/manifests/Build_commands.php';

$failures = 0;

$test = function (string $name, callable $body) use (&$failures): void {
    try {
        $body();
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}\n     {$e->getMessage()}\n";
    }
};

$same = function ($expected, $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Expected:\n" . var_export($expected, true) . "\nGot:\n" . var_export($actual, true));
    }
};

$with_build = '{"scripts": {"build": "vite build"}}';

$test('npm with a lockfile installs exactly and builds', function () use ($same, $with_build) {
    $suggestion = Build_commands::suggest(['package.json', 'package-lock.json', 'composer.json'], $with_build, '');
    $same("npm ci\nnpm run build", $suggestion['commands']);
    $same(['package-lock.json'], $suggestion['found']);
});

$test('bun, pnpm and yarn are picked by their lockfiles', function () use ($same, $with_build) {
    $same("bun install --frozen-lockfile\nbun run build", Build_commands::suggest(['package.json', 'bun.lock'], $with_build, '')['commands']);
    $same("bun install --frozen-lockfile\nbun run build", Build_commands::suggest(['package.json', 'bun.lockb'], $with_build, '')['commands']);
    $same("pnpm install --frozen-lockfile\npnpm run build", Build_commands::suggest(['package.json', 'pnpm-lock.yaml'], $with_build, '')['commands']);
    $same("yarn install --frozen-lockfile\nyarn run build", Build_commands::suggest(['package.json', 'yarn.lock'], $with_build, '')['commands']);
    $same("yarn install --immutable\nyarn run build", Build_commands::suggest(['package.json', 'yarn.lock', '.yarnrc.yml'], $with_build, '')['commands']);
});

$test('packageManager in package.json wins over a stray lockfile', function () use ($same) {
    $package = '{"packageManager": "pnpm@9.1.0", "scripts": {"build": "x"}}';
    $suggestion = Build_commands::suggest(['package.json', 'package-lock.json', 'pnpm-lock.yaml'], $package, '');
    $same("pnpm install --frozen-lockfile\npnpm run build", $suggestion['commands']);
    $same(['pnpm-lock.yaml'], $suggestion['found']);
});

$test('no lockfile installs without one; no build script suggests nothing', function () use ($same, $with_build) {
    $same("npm install\nnpm run build", Build_commands::suggest(['package.json'], $with_build, '')['commands']);
    $same('', Build_commands::suggest(['package.json', 'package-lock.json'], '{"scripts": {"dev": "vite"}}', '')['commands']);
    $same('', Build_commands::suggest(['composer.json'], '', '{"require": {"php": "^8.4"}}')['commands']);
});

$test('composer.json ext-* the base lacks become PHP extensions', function () use ($same) {
    $composer = '{"require": {"php": "^8.4", "ext-imagick": "*", "ext-PDO_MySQL": "*", "ext-mbstring": "*", "ext-sqlsrv": "*", "vendor/pkg": "^1"}, "require-dev": {"ext-xdebug": "*"}}';
    $suggestion = Build_commands::suggest(['composer.json'], '', $composer);
    $same(['imagick', 'sqlsrv'], $suggestion['php_extensions']);
    $same(['composer.json'], $suggestion['found']);
});

$test('broken JSON is ignored', function () use ($same) {
    $same(['commands' => '', 'php_extensions' => [], 'found' => []], Build_commands::suggest(['package.json'], '{not json', '[1]'));
});

$test('commands are normalised and checked', function () use ($same) {
    $same("  npm ci\nnpm run build", Build_commands::normalise("\r\n  npm ci  \r\nnpm run build\n\n"));
    $same(null, Build_commands::error("npm ci\nnpm run build"));
    $same(null, Build_commands::error(''));
    $same(true, is_string(Build_commands::error("npm ci\x1b[0m")));
    $same(true, is_string(Build_commands::error(str_repeat("echo\n", Build_commands::MAX_LINES + 1))));
    $same(true, is_string(Build_commands::error(str_repeat('a', Build_commands::MAX_LINE_BYTES + 1))));
});

$test('tools follow the words in the commands', function () use ($same) {
    $same(['node'], Build_commands::tools('npm ci && npm run build'));
    $same(['node'], Build_commands::tools('pnpm install'));
    $same(['bun'], Build_commands::tools('bunx tailwindcss -o public/app.css'));
    $same(['node', 'bun'], Build_commands::tools("bun install\nnode build.js"));
    $same([], Build_commands::tools('php bin/build.php'));
    $same([], Build_commands::tools('./npm-run-all'));
});

$test('no commands keep the single-stage Dockerfile', function () use ($same) {
    $same(
        ['FROM base', 'RUN install-php-extensions imagick', 'COPY . ./', 'COPY Caddyfile /Caddyfile'],
        Build_commands::dockerfile('base', ['RUN install-php-extensions imagick'], ['COPY . ./'], ['COPY Caddyfile /Caddyfile'], "  \n"),
    );
});

$test('commands run in a build stage the image copies /app from', function () use ($same) {
    $lines = Build_commands::dockerfile('base', ['RUN install-php-extensions imagick'], ['COPY . ./'], ['COPY Caddyfile /Caddyfile'], "npm ci\nFROM evil\nnpm run build");

    $same('FROM base AS build', $lines[0]);
    $same(true, in_array('COPY --from=' . Build_commands::NODE_IMAGE . ' /usr/local/bin/node /usr/local/bin/node', $lines, true));
    $same(['FROM base AS build', 'FROM base'], array_values(array_filter($lines, fn(string $line) => str_starts_with($line, 'FROM '))));
    $same(['FROM base', 'RUN install-php-extensions imagick', 'COPY --from=build /app /app', 'COPY Caddyfile /Caddyfile'], array_slice($lines, -4));

    $run = array_values(array_filter($lines, fn(string $line) => str_contains($line, 'build-commands.sh')))[0];
    preg_match('/^RUN echo ([A-Za-z0-9+\/=]+) \| base64 -d/', $run, $match);
    $same("set -e\nnpm ci\nFROM evil\nnpm run build\n", base64_decode($match[1]));
});

exit($failures === 0 ? 0 : 1);
