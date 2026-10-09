<?php

/**
 * What a Trongate upgrade changes in an app's repository (Trongate_upgrade::plan):
 * the file contents to commit and what the pull request tells the customer.
 */
final class Upgrade_plan {

    /** @var array<string, ?string> path => new content, null to delete */
    public array $changes = [];

    /** @var array<string, list<string>> kind => paths: added, replaced, merged, deleted, conflicts */
    public array $files = ['added' => [], 'replaced' => [], 'merged' => [], 'deleted' => [], 'conflicts' => []];

    /** @var array<string, string> path => why it stays as the app has it */
    public array $kept = [];

    /** @var array<string, list<string>> config path => settings added with the newer release's values */
    public array $settings = [];

    /** @var array<string, list<string>> config path => settings to copy by hand (not constant values) */
    public array $manual_settings = [];

    function replace(string $path, string $content, bool $new): void {
        $this->changes[$path] = $content;
        $this->files[$new ? 'added' : 'replaced'][] = $path;
    }

    function delete(string $path): void {
        $this->changes[$path] = null;
        $this->files['deleted'][] = $path;
    }

    function merge(string $path, string $content): void {
        $this->changes[$path] = $content;
        $this->files['merged'][] = $path;
    }

    /** Committed with conflict markers for the customer to resolve */
    function conflict(string $path, string $content): void {
        $this->changes[$path] = $content;
        $this->files['conflicts'][] = $path;
    }

    function keep(string $path, string $reason): void {
        $this->kept[$path] = $reason;
    }

    /**
     * @param list<string> $keys
     */
    function add_settings(string $path, string $content, array $keys): void {
        $this->changes[$path] = $content;
        $this->settings[$path] = $keys;
    }

    function manual_setting(string $path, string $key): void {
        $this->manual_settings[$path][] = $key;
    }

    function is_empty(): bool {
        return $this->changes === [] && $this->kept === [] && $this->manual_settings === [];
    }

    /**
     * SQL files the upgrade adds or changes: tables new stock modules need,
     * which the customer imports
     *
     * @return list<string>
     */
    function sql_files(): array {
        return array_values(array_filter(
            [...$this->files['added'], ...$this->files['replaced'], ...$this->files['merged']],
            fn(string $path) => str_ends_with(strtolower($path), '.sql'),
        ));
    }

    /**
     * Counts for the app page and the MCP tools
     *
     * @return array<string, int>
     */
    function counts(): array {
        return [
            ...array_map('count', $this->files),
            'kept' => count($this->kept),
            'settings' => array_sum(array_map('count', $this->settings)),
            'manual_settings' => array_sum(array_map('count', $this->manual_settings)),
            'sql' => count($this->sql_files()),
        ];
    }

    /**
     * The pull request description (Markdown)
     */
    function description(string $from, string $to, string $source): string {
        $list = fn(array $paths) => implode("\n", array_map(fn($path) => '- `' . $path . '`', $paths));
        $sections = [
            "Upgrades Trongate from **{$from}** to **{$to}** ([releases](https://github.com/{$source}/releases)).",
            'Framework files you never changed take the newer version. Files you changed are merged with it, and config files only get the settings the newer version adds, so your values stay as they are.',
        ];

        if ($this->files['conflicts'] !== []) {
            $sections[] = "### Resolve before merging\n\nBoth you and the newer Trongate changed these lines. Look for `<<<<<<< yours` and keep what you need:\n\n" . $list($this->files['conflicts']);
        }

        if (($sql = $this->sql_files()) !== []) {
            $sections[] = "### Import into your database\n\nThe newer Trongate adds or changes these SQL files. Import them before or right after this deploys:\n\n" . $list($sql);
        }

        if ($this->manual_settings !== []) {
            $lines = [];

            foreach ($this->manual_settings as $path => $keys) {
                $lines[] = '- `' . $path . '`: ' . implode(', ', array_map(fn($key) => "`{$key}`", $keys));
            }

            $sections[] = "### Settings to copy by hand\n\nThe newer Trongate sets these from code rather than a plain value, so they were not added:\n\n" . implode("\n", $lines);
        }

        if ($this->settings !== []) {
            $lines = [];

            foreach ($this->settings as $path => $keys) {
                $lines[] = '- `' . $path . '`: ' . implode(', ', array_map(fn($key) => "`{$key}`", $keys));
            }

            $sections[] = "### New settings\n\nAdded with the newer Trongate's defaults:\n\n" . implode("\n", $lines);
        }

        foreach (['merged' => 'Merged with your changes', 'replaced' => 'Updated', 'added' => 'Added', 'deleted' => 'Deleted'] as $kind => $title) {
            if ($this->files[$kind] !== []) {
                $sections[] = "<details><summary>{$title} (" . count($this->files[$kind]) . ")</summary>\n\n" . $list($this->files[$kind]) . "\n\n</details>";
            }
        }

        if ($this->kept !== []) {
            $lines = [];

            foreach ($this->kept as $path => $reason) {
                $lines[] = '- `' . $path . '`: ' . $reason;
            }

            $sections[] = "### Left as they are\n\n" . implode("\n", $lines);
        }

        $sections[] = 'Merging deploys as usual: the new version only takes traffic after its home page answers, and a failed deploy undoes itself.';
        $sections[] = '_Opened by [trongate.cloud](https://trongate.cloud), an independent service not affiliated with or endorsed by the Trongate project._';

        return implode("\n\n", $sections) . "\n";
    }
}
