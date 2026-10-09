<?php

require_once __DIR__ . '/../manifests/Config_editor.php';
require_once __DIR__ . '/Upgrade_plan.php';

/**
 * Plans a Trongate upgrade of an app's repository: the files the framework
 * ships (engine/, its stock modules, public/ assets, config/) moved from the
 * release the app is on to a newer one, the way a person ports upstream
 * changes by hand.
 *
 * Trongate is copied into every app rather than installed as a package, and
 * v2 releases (2.YYYY.MMDD) stay backwards compatible, so an upgrade is a
 * three-way merge per framework file: base = the release the app is on,
 * yours = the app's copy, theirs = the newer release.
 *
 * - A file the app never changed takes the newer release's copy (or is
 *   deleted when the release deleted it); one the release never changed
 *   stays as the app has it. A file both changed is merged line by line
 *   (diff3); what does not merge keeps conflict markers for the customer.
 * - config/*.php is never merged as text: settings the newer release adds
 *   are appended with Config_editor, keeping every value the app set.
 * - Files outside the framework (the app's own modules) are never read.
 *
 * Framework-free and given trees (path => git blob SHA) plus a reader for
 * blob contents, so it is tested without GitHub (tests/Trongate_upgrade_test.php).
 */
final class Trongate_upgrade {

    /** The framework's repository on GitHub */
    const SOURCE = 'trongate/trongate-framework';

    /** v2 release tags: 2.YYYY.MMDD, with or without a leading v */
    const TAG_PATTERN = '/^v?(2\.\d{4}\.\d{4})$/';

    /** Newest releases compared when finding the one an app is on */
    const MAX_RELEASES = 20;

    /** Share of a release's engine/ files an app must hold unchanged to count as on it */
    const MIN_ENGINE_MATCH = 0.5;

    /** Paths the framework ships that are the app's own once copied: docs and CI */
    const IGNORED_PATTERN = '#^(\.github/|[^/]+\.(md|txt)$)#i';

    /** config/*.php: settings are added (Config_editor), never merged as text */
    const CONFIG_PATTERN = '#^config/([A-Za-z0-9_-]+\.php)$#';

    /** Larger files are not merged as text */
    const MAX_MERGE_BYTES = 1024 * 1024;

    /**
     * @param array<string, string> $base release the app is on: path => blob SHA
     * @param array<string, string> $target newer release: path => blob SHA
     * @param array<string, string> $app the app's branch: path => blob SHA
     * @param Closure(string, string): string $read (side 'base'|'target'|'app', path) => content
     */
    function __construct(
        private array $base,
        private array $target,
        private array $app,
        private Closure $read,
    ) {
    }

    /**
     * v2 release versions among tag names, newest first: version => tag
     *
     * @param list<string> $tags
     * @return array<string, string>
     */
    static function releases(array $tags): array {
        $releases = [];

        foreach ($tags as $tag) {
            if (preg_match(self::TAG_PATTERN, $tag, $match)) {
                $releases[$match[1]] = $tag;
            }
        }

        uksort($releases, fn(string $a, string $b) => strcmp($b, $a));

        return $releases;
    }

    /**
     * The git blob SHA of a file's content, as trees list them
     */
    static function blob_sha(string $content): string {
        return sha1('blob ' . strlen($content) . "\0" . $content);
    }

    /**
     * The release an app is on: the one with the most framework files the
     * app holds unchanged (the newest of equals), or null when none has at
     * least MIN_ENGINE_MATCH of its engine/ files in the app
     *
     * @param array<string, string> $app path => blob SHA
     * @param array<string, array<string, string>> $releases version => (path => blob SHA), newest first
     */
    static function closest(array $app, array $releases): ?string {
        $best = null;
        $best_score = -1;

        foreach ($releases as $version => $tree) {
            $engine = $matched_engine = $score = 0;

            foreach ($tree as $path => $sha) {
                if (self::ignored($path)) {
                    continue;
                }

                $same = ($app[$path] ?? null) === $sha;
                $score += (int) $same;

                if (str_starts_with($path, 'engine/')) {
                    $engine++;
                    $matched_engine += (int) $same;
                }
            }

            if ($engine > 0 && $matched_engine / $engine >= self::MIN_ENGINE_MATCH && $score > $best_score) {
                $best = (string) $version;
                $best_score = $score;
            }
        }

        return $best;
    }

    static function ignored(string $path): bool {
        return (bool) preg_match(self::IGNORED_PATTERN, $path);
    }

    function plan(): Upgrade_plan {
        $plan = new Upgrade_plan();
        $paths = array_keys($this->base + $this->target);
        sort($paths);

        foreach ($paths as $path) {
            $path = (string) $path;

            if (self::ignored($path)) {
                continue;
            }

            if (preg_match(self::CONFIG_PATTERN, $path, $match)) {
                $this->plan_config($plan, $path, $match[1]);
                continue;
            }

            $this->plan_file($plan, $path);
        }

        return $plan;
    }

    private function plan_file(Upgrade_plan $plan, string $path): void {
        $base = $this->base[$path] ?? null;
        $target = $this->target[$path] ?? null;
        $app = $this->app[$path] ?? null;

        if ($app === $target || $base === $target) {
            return;
        }

        if ($app === $base) {
            $target === null ? $plan->delete($path) : $plan->replace($path, $this->read('target', $path), $base === null);
            return;
        }

        if ($app === null) {
            $plan->keep($path, 'You deleted this file; the newer Trongate changed it.');
            return;
        }

        if ($target === null) {
            $plan->keep($path, 'The newer Trongate deleted this file; you changed it, so it stays.');
            return;
        }

        $merged = self::merge3(
            $base === null ? '' : $this->read('base', $path),
            $this->read('app', $path),
            $this->read('target', $path),
        );

        if ($merged === null) {
            $plan->keep($path, 'Binary or large file changed by you and by the newer Trongate; compare it by hand.');
        } elseif ($merged['conflicts']) {
            $plan->conflict($path, $merged['content']);
        } else {
            $plan->merge($path, $merged['content']);
        }
    }

    /**
     * A config file: new in the release (added unless the app deleted it),
     * or settings the release added (appended with the release's values)
     */
    private function plan_config(Upgrade_plan $plan, string $path, string $file): void {
        $base = $this->base[$path] ?? null;
        $target = $this->target[$path] ?? null;
        $app = $this->app[$path] ?? null;

        if ($target === null || $app === $target || $base === $target) {
            return;
        }

        if ($app === null) {
            if ($base === null) {
                $plan->replace($path, $this->read('target', $path), true);
            }

            return;
        }

        try {
            $known = $this->config_settings('app', $path) + ($base === null ? [] : $this->config_settings('base', $path));

            // A constant may be defined in any config file; don't define it twice
            foreach ($this->app as $other => $sha) {
                if ($other !== $path && preg_match(self::CONFIG_PATTERN, $other)) {
                    $known += array_filter($this->config_settings('app', $other), fn($key) => !str_contains($key, '.'), ARRAY_FILTER_USE_KEY);
                }
            }

            $rules = [];

            foreach ($this->config_settings('target', $path) as $key => $setting) {
                if (isset($known[$key])) {
                    continue;
                }

                if ($setting['literal']) {
                    $rules[] = new Config_rule($key, $setting['value'], $file);
                } else {
                    $plan->manual_setting($path, $key);
                }
            }

            if ($rules !== []) {
                $edited = Config_editor::apply([$file => $this->read('app', $path)], $rules);
                $plan->add_settings($path, $edited[$file], array_map(fn(Config_rule $rule) => $rule->key, $rules));
            }
        } catch (Config_error $e) {
            $plan->keep($path, "Could not read it to add the newer Trongate's settings: {$e->getMessage()}");
        }
    }

    /**
     * @return array<string, array{literal: bool, value: string|int|float|bool|null}>
     */
    private function config_settings(string $side, string $path): array {
        return Config_editor::settings(basename($path), $this->read($side, $path));
    }

    private function read(string $side, string $path): string {
        return ($this->read)($side, $path);
    }

    /**
     * Three-way merge with diff3: null for binary or large files, else the
     * merged text and whether it holds conflict markers
     *
     * @return ?array{content: string, conflicts: bool}
     */
    static function merge3(string $base, string $yours, string $theirs): ?array {
        foreach ([$base, $yours, $theirs] as $text) {
            if (strlen($text) > self::MAX_MERGE_BYTES || str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8')) {
                return null;
            }
        }

        $dir = sys_get_temp_dir() . '/tc-merge-' . bin2hex(random_bytes(8));

        if (!mkdir($dir, 0700)) {
            throw new RuntimeException('Could not create a directory for merging');
        }

        try {
            foreach (['base' => $base, 'yours' => $yours, 'theirs' => $theirs] as $name => $text) {
                file_put_contents("{$dir}/{$name}", $text);
            }

            $process = proc_open(
                ['diff3', '--merge', '--label', 'yours', '--label', 'before', '--label', 'newer Trongate', "{$dir}/yours", "{$dir}/base", "{$dir}/theirs"],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            if (!is_resource($process)) {
                throw new RuntimeException('Could not run diff3');
            }

            $content = (string) stream_get_contents($pipes[1]);
            $error = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            if ($status > 1) {
                throw new RuntimeException("diff3 failed: {$error}");
            }

            return ['content' => $content, 'conflicts' => $status === 1];
        } finally {
            foreach (['base', 'yours', 'theirs'] as $name) {
                @unlink("{$dir}/{$name}");
            }

            @rmdir($dir);
        }
    }
}
