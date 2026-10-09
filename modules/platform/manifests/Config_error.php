<?php

/**
 * Why the platform could not set its values in an app's config files, and
 * what gets the app deploying again. Thrown by Config_editor and the deploy
 * tasks; the worker stores to_array() on the failed deployment, which the
 * deployment page and the MCP tools show.
 *
 * In every case the app keeps running its last good deployment.
 */
final class Config_error extends RuntimeException {

    // $config_file and $config_line: Exception's own $file and $line are where it was thrown

    /** The app's own file is wrong: fix it in the repository and push */
    const FIX_FILE = 'fix_file';

    /** The deployment cannot be applied again: deploy the app */
    const REDEPLOY = 'redeploy';

    /** Our edit failed its own checks: nothing for the customer to do */
    const PLATFORM = 'platform';

    function __construct(
        public readonly string $reason,
        public readonly string $action,
        public readonly ?string $config_file = null,
        public readonly ?int $config_line = null,
        ?Throwable $previous = null,
    ) {
        $where = $config_file === null ? '' : 'config/' . $config_file . ($config_line !== null ? " line {$config_line}" : '') . ': ';

        parent::__construct($where . $reason . ' ' . $this->hint(), 0, $previous);
    }

    /**
     * What to do about it, in a sentence
     */
    function hint(): string {
        return match ($this->action) {
            self::FIX_FILE => 'Fix the file in your repository and push; your app keeps running its previous deployment until then.',
            self::REDEPLOY => 'Deploy the app again; your app keeps running its current deployment until then.',
            default => 'This is a problem on our side, not in your code; your app keeps running its previous deployment.',
        };
    }

    /**
     * @return array{reason: string, action: string, hint: string, file: ?string, line: ?int}
     */
    function to_array(): array {
        return [
            'reason' => $this->reason,
            'action' => $this->action,
            'hint' => $this->hint(),
            'file' => $this->config_file,
            'line' => $this->config_line,
        ];
    }
}
