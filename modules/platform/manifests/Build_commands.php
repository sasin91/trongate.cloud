<?php

/**
 * An app's build commands (app_runtime.build_commands): shell lines the deploy runs
 * in the image build, after composer and the app's files are in /app, such
 * as `npm ci` and `npm run build`. They run in a build stage that has Node
 * or Bun when the commands name them; the app image takes only /app from
 * it, so the tools never ship (see dockerfile()).
 *
 * suggest() reads the repository's root files while an app is created and
 * prefills the commands for the package manager its lockfile names, plus
 * the PHP extensions composer.json requires (ext-*, what `pie install`
 * would install) that the base image lacks.
 *
 * Framework-free: the panel and the worker both load it.
 */
final class Build_commands {

    const MAX_BYTES = 4000;
    const MAX_LINES = 40;
    const MAX_LINE_BYTES = 500;

    /** Where the build stage copies Node and Bun from */
    const NODE_IMAGE = 'node:24-bookworm-slim';
    const BUN_IMAGE = 'oven/bun:1-debian';

    /** Words in the commands that need Node (pnpm and yarn come from corepack) */
    const NODE_WORDS = ['node', 'npm', 'npx', 'pnpm', 'yarn', 'corepack'];
    const BUN_WORDS = ['bun', 'bunx'];

    /**
     * Extensions the app base image already has (docker/app-base/Dockerfile),
     * PHP's own ones included, so composer.json requiring them suggests nothing
     */
    const BASE_EXTENSIONS = [
        'bcmath', 'core', 'ctype', 'curl', 'date', 'dom', 'exif', 'fileinfo', 'filter', 'gd',
        'hash', 'iconv', 'intl', 'json', 'libxml', 'mbstring', 'mysqlnd', 'openssl', 'pcre',
        'pdo', 'pdo_mysql', 'pdo_sqlite', 'phar', 'posix', 'random', 'readline', 'redis',
        'reflection', 'session', 'simplexml', 'sodium', 'spl', 'sqlite3', 'standard',
        'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib',
    ];

    /** Lockfile => package manager, in the order they win */
    const LOCKFILES = [
        'bun.lock' => 'bun',
        'bun.lockb' => 'bun',
        'pnpm-lock.yaml' => 'pnpm',
        'yarn.lock' => 'yarn',
        'package-lock.json' => 'npm',
    ];

    /**
     * What the new-app wizard prefills, from the repository's root: its
     * file names and the contents of package.json and composer.json (''
     * when there is none)
     *
     * @param list<string> $root_files
     * @return array{commands: string, php_extensions: list<string>, found: list<string>}
     */
    static function suggest(array $root_files, string $package_json, string $composer_json): array {
        $found = [];
        $commands = [];
        $package = self::json_object($package_json);

        if ($package !== null) {
            $manager = self::package_manager($root_files, $package);
            $lockfile = self::lockfile($root_files, $manager);
            $found[] = $lockfile ?? 'package.json';

            // Only a build script makes installing worthwhile for a PHP app
            if (is_string($package['scripts']['build'] ?? null)) {
                $commands[] = self::install_command($manager, $lockfile !== null, in_array('.yarnrc.yml', $root_files, true));
                $commands[] = $manager === 'npm' ? 'npm run build' : "{$manager} run build";
            }
        }

        $extensions = self::required_extensions(self::json_object($composer_json) ?? []);

        if ($extensions !== []) {
            $found[] = 'composer.json';
        }

        return [
            'commands' => implode("\n", $commands),
            'php_extensions' => $extensions,
            'found' => $found,
        ];
    }

    /**
     * package.json's "packageManager" (e.g. pnpm@9.1.0) when it names one we
     * know, else the first lockfile's, else npm
     *
     * @param list<string> $root_files
     */
    static function package_manager(array $root_files, array $package): string {
        $declared = strtolower((string) ($package['packageManager'] ?? ''));
        $name = explode('@', $declared)[0];

        if (in_array($name, ['npm', 'pnpm', 'yarn', 'bun'], true)) {
            return $name;
        }

        foreach (self::LOCKFILES as $file => $manager) {
            if (in_array($file, $root_files, true)) {
                return $manager;
            }
        }

        return 'npm';
    }

    /**
     * The lockfile of $manager in the root, or null
     *
     * @param list<string> $root_files
     */
    static function lockfile(array $root_files, string $manager): ?string {
        foreach (self::LOCKFILES as $file => $file_manager) {
            if ($file_manager === $manager && in_array($file, $root_files, true)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Install exactly the locked versions when there is a lockfile
     */
    static function install_command(string $manager, bool $locked, bool $yarn_berry): string {
        if (!$locked) {
            return "{$manager} install";
        }

        if ($manager === 'npm') {
            return 'npm ci';
        }

        if ($manager === 'yarn' && $yarn_berry) {
            return 'yarn install --immutable';
        }

        return "{$manager} install --frozen-lockfile";
    }

    /**
     * composer.json's "require" ext-* entries the base image lacks, lowercase
     *
     * @return list<string>
     */
    static function required_extensions(array $composer): array {
        $require = $composer['require'] ?? [];

        if (!is_array($require)) {
            return [];
        }

        $extensions = [];

        foreach (array_keys($require) as $package) {
            $name = strtolower((string) $package);

            if (!str_starts_with($name, 'ext-')) {
                continue;
            }

            $extension = substr($name, 4);

            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $extension) && !in_array($extension, self::BASE_EXTENSIONS, true)) {
                $extensions[] = $extension;
            }
        }

        return array_values(array_unique($extensions));
    }

    /**
     * The commands as stored: LF line ends, each line trimmed on the right,
     * no blank lines at either end
     */
    static function normalise(string $commands): string {
        $lines = preg_split('/\r\n|\r|\n/', $commands) ?: [];
        $lines = array_map('rtrim', $lines);

        return trim(implode("\n", $lines), "\n");
    }

    /**
     * The app's extra PHP extensions (app_runtime.php_extensions, a JSON
     * list): lowercase, unique, only names install-php-extensions takes
     *
     * @return list<string>
     */
    static function php_extensions(array $app): array {
        $names = $app['php_extensions'] ?? [];

        if (is_string($names)) {
            $names = json_decode($names, true) ?: [];
        }

        $clean = [];

        foreach ((array) $names as $name) {
            $name = strtolower(trim((string) $name));

            if (preg_match('/^[a-z0-9_]{1,40}$/', $name)) {
                $clean[$name] = $name;
            }
        }

        return array_values($clean);
    }

    /**
     * Why the commands cannot be saved, or null
     */
    static function error(string $commands): ?string {
        $commands = self::normalise($commands);

        if (strlen($commands) > self::MAX_BYTES) {
            return 'Build commands can be at most ' . self::MAX_BYTES . ' characters.';
        }

        if (preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $commands)) {
            return 'Build commands cannot contain control characters.';
        }

        $lines = $commands === '' ? [] : explode("\n", $commands);

        if (count($lines) > self::MAX_LINES) {
            return 'Build commands can be at most ' . self::MAX_LINES . ' lines.';
        }

        foreach ($lines as $line) {
            if (strlen($line) > self::MAX_LINE_BYTES) {
                return 'Each build command line can be at most ' . self::MAX_LINE_BYTES . ' characters.';
            }
        }

        return null;
    }

    /**
     * The tools the commands call: node (with npm, npx, pnpm and yarn) and bun
     *
     * @return list<string>
     */
    static function tools(string $commands): array {
        $words = preg_split('/[^a-z0-9_-]+/', strtolower($commands)) ?: [];
        $tools = [];

        if (array_intersect($words, self::NODE_WORDS) !== []) {
            $tools[] = 'node';
        }

        if (array_intersect($words, self::BUN_WORDS) !== []) {
            $tools[] = 'bun';
        }

        return $tools;
    }

    /**
     * The app's Dockerfile. $setup are the lines that install its PHP
     * extensions and composer packages and copy its files into /app on
     * $base. Without build commands that is the whole image. With them,
     * a build stage adds the tools and runs the commands, and the image
     * is $base plus the extensions and the built /app.
     *
     * The commands reach the shell base64-encoded, so no line of them can
     * become a Dockerfile instruction.
     *
     * @param list<string> $extension_lines what installs the extensions on $base
     * @param list<string> $app_lines composer and COPY lines that fill /app
     * @param list<string> $final_lines lines for the image only (e.g. its Caddyfile)
     * @return list<string>
     */
    static function dockerfile(string $base, array $extension_lines, array $app_lines, array $final_lines, string $commands): array {
        $commands = self::normalise($commands);

        if ($commands === '') {
            return array_merge(["FROM {$base}"], $extension_lines, $app_lines, $final_lines);
        }

        $lines = array_merge(["FROM {$base} AS build"], $extension_lines, $app_lines, self::tool_lines(self::tools($commands)));
        $lines[] = 'ENV CI=true COREPACK_ENABLE_DOWNLOAD_PROMPT=0';
        $lines[] = 'RUN echo ' . base64_encode("set -e\n{$commands}\n") . ' | base64 -d > /tmp/build-commands.sh'
            . ' && bash /tmp/build-commands.sh && rm /tmp/build-commands.sh';

        // Same base and extension lines as the build stage: BuildKit runs them once
        $lines[] = "FROM {$base}";
        $lines = array_merge($lines, $extension_lines);
        $lines[] = 'COPY --from=build /app /app';

        return array_merge($lines, $final_lines);
    }

    /**
     * Lines that put Node (npm, npx and corepack's pnpm and yarn) and Bun
     * into the build stage
     *
     * @param list<string> $tools
     * @return list<string>
     */
    static function tool_lines(array $tools): array {
        $lines = [];

        if (in_array('node', $tools, true)) {
            $lines[] = 'COPY --from=' . self::NODE_IMAGE . ' /usr/local/bin/node /usr/local/bin/node';
            $lines[] = 'COPY --from=' . self::NODE_IMAGE . ' /usr/local/lib/node_modules /usr/local/lib/node_modules';
            $lines[] = 'RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm'
                . ' && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx'
                . ' && ln -s ../lib/node_modules/corepack/dist/corepack.js /usr/local/bin/corepack'
                . ' && corepack enable pnpm yarn';
        }

        if (in_array('bun', $tools, true)) {
            $lines[] = 'COPY --from=' . self::BUN_IMAGE . ' /usr/local/bin/bun /usr/local/bin/bun';
            $lines[] = 'RUN ln -s bun /usr/local/bin/bunx';
        }

        return $lines;
    }

    /**
     * A JSON object as an array, or null when it is not one
     */
    static function json_object(string $json): ?array {
        if (trim($json) === '') {
            return null;
        }

        $value = json_decode($json, true);

        return is_array($value) && !array_is_list($value) ? $value : null;
    }
}
