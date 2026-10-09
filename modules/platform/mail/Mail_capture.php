<?php

require_once __DIR__ . '/../../../config/platform.php';

/**
 * Mail capture: what customer apps send through Trongate's email module
 * (config/trongate_email.php) goes to the platform's own SMTP inbox
 * (server.php, Deployment trongate-cloud-mail) instead of the internet, and
 * shows up on the app's Mail tab. Nothing is ever delivered.
 *
 * On by default (app.mail_capture). At every apply the worker points the
 * app's trongate_email.php at the inbox (rules()); each app signs in with
 * its own password (password()), which is how the inbox knows whose mail it
 * is, so one app can never put mail in (or read) another app's mailbox.
 * Turned off, the app's own SMTP settings are used as written.
 *
 * Framework-free: loaded by the worker, the inbox and the web app.
 */
final class Mail_capture {

    const CONFIG_FILE = 'trongate_email.php';

    /** Config_rule key prefix of the settings in CONFIG_FILE */
    const CONFIG_PREFIX = 'config.trongate_email.';

    /** Largest message the inbox accepts (SMTP SIZE) */
    const MAX_MESSAGE_BYTES = 2 * 1024 * 1024;

    /** Messages kept per app: the newest KEEP_PER_APP, at most MAX_APP_BYTES together, for KEEP_DAYS */
    const KEEP_PER_APP = 200;
    const MAX_APP_BYTES = 16 * 1024 * 1024;
    const KEEP_DAYS = 7;

    /** password(): "tc{app id}." then this many hex characters of HMAC */
    const PASSWORD_HASH_LENGTH = 40;

    /**
     * Whether the app's mail is captured (app.mail_capture, on unless turned off)
     */
    static function enabled(array $app): bool {
        return !array_key_exists('mail_capture', $app) || $app['mail_capture'] === null || (int) $app['mail_capture'] === 1;
    }

    /**
     * The app's SMTP password for the inbox, derived from APP_KEY like
     * Manifests::database_password(). It carries the app id, so the inbox
     * finds the app without a lookup and whatever the username is.
     */
    static function password(int $app_id): string {
        if ($app_id <= 0) {
            throw new InvalidArgumentException('An app id is required for its mail password');
        }

        return "tc{$app_id}." . self::signature($app_id);
    }

    /**
     * The app a password belongs to, or null when it is not one of password()'s
     */
    static function app_id(string $password): ?int {
        if (!preg_match('/^tc([1-9][0-9]{0,9})\.([0-9a-f]{' . self::PASSWORD_HASH_LENGTH . '})$/', $password, $match)) {
            return null;
        }

        $app_id = (int) $match[1];

        return hash_equals(self::signature($app_id), $match[2]) ? $app_id : null;
    }

    /**
     * What the platform sets in config/trongate_email.php while capture is
     * on: the inbox's host and port, no TLS (it never leaves the cluster)
     * and the app's password. smtp_user is also the From address, so the
     * app's own stays unless it is missing or empty; then the app gets a
     * noreply address on its own host, as Trongate refuses to send without one.
     *
     * @param array<string, string> $files the app's config/*.php: file name => source
     * @return list<Config_rule>
     * @throws Config_error when trongate_email.php is not valid PHP
     */
    static function rules(array $app, array $files): array {
        require_once __DIR__ . '/../manifests/Config_rule.php';
        require_once __DIR__ . '/../manifests/Config_editor.php';

        if (!self::enabled($app)) {
            return [];
        }

        $rule = fn(string $key, string|int $value) => new Config_rule(self::CONFIG_PREFIX . $key, $value, self::CONFIG_FILE);
        $rules = [
            $rule('smtp_host', PLATFORM_MAIL_HOST),
            $rule('smtp_port', PLATFORM_MAIL_PORT),
            $rule('smtp_pass', self::password((int) $app['id'])),
            $rule('smtp_secure', ''),
        ];

        $settings = isset($files[self::CONFIG_FILE]) ? Config_editor::settings(self::CONFIG_FILE, $files[self::CONFIG_FILE]) : [];
        $user = $settings[self::CONFIG_PREFIX . 'smtp_user'] ?? null;

        // A literal empty string, or no setting at all; an expression (getenv()) is the app's
        if ($user === null || ($user['literal'] && trim((string) $user['value']) === '')) {
            $rules[] = $rule('smtp_user', self::from_address($app));
        }

        return $rules;
    }

    /**
     * From address for an app that has none: noreply@ its platform host
     */
    static function from_address(array $app): string {
        return 'noreply@' . strtolower($app['slug'] . '.' . PLATFORM_APPS_DOMAIN);
    }

    private static function signature(int $app_id): string {
        $key = defined('APP_KEY') ? (string) constant('APP_KEY') : (string) getenv('APP_KEY');

        if ($key === '') {
            throw new RuntimeException('APP_KEY is required to derive mail passwords');
        }

        return substr(hash_hmac('sha256', "mail:{$app_id}", $key), 0, self::PASSWORD_HASH_LENGTH);
    }
}
