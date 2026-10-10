<?php
/**
 * PHP sessions in the shared Valkey (deploy/k8s/cache), for customer apps
 * (Manifests::sessions_ini) and the platform panel (start_platform()).
 *
 * Every client signs in as its own ACL user that only reaches keys under its
 * own prefix ("{user}:"). The worker creates the users (Platform_task::
 * ensure_session_user, and worker/server.php for the panel); passwords are
 * derived from APP_KEY, so the worker and the panel agree without a secret.
 */
class Valkey_sessions {

    /** The panel's ACL user; customer apps are tc_p{id} */
    const PLATFORM_USER = 'tc_platform';

    // phpredis gives each session key a TTL of session.gc_maxlifetime, renewed
    // on every request. PHP's default of 24 minutes signed people out of a
    // Trongate admin login after a short break, such as waiting on a deploy.
    // One day, like a Trongate login token without "remember me".
    const LIFETIME_SECONDS = 86400;

    // Key commands and connection basics only. SCAN and RANDOMKEY take no key,
    // so key patterns would not stop them listing other users' session ids;
    // FUNCTION would reach server-wide Lua libraries.
    const ACL_COMMANDS = [
        '-@all', '+@connection', '+@read', '+@write', '+@keyspace', '+@transaction',
        '-@dangerous', '-scan', '-randomkey', '-function',
    ];

    static function key_prefix(string $user): string {
        return "{$user}:";
    }

    /**
     * Stable password for a session user, derived from APP_KEY
     */
    static function password(string $user): string {
        $key = defined('APP_KEY') ? (string) constant('APP_KEY') : (string) getenv('APP_KEY');

        if ($key === '') {
            throw new RuntimeException('APP_KEY is required to derive session passwords');
        }

        return substr(hash_hmac('sha256', 'sessions:' . $user, $key), 0, 48);
    }

    /**
     * Arguments for "ACL SETUSER {user}": replaces whatever the user had
     *
     * @return list<string>
     */
    static function acl(string $user, string $password): array {
        return array_merge(
            ['reset', 'on', '>' . $password, '~' . self::key_prefix($user) . '*', 'resetchannels'],
            self::ACL_COMMANDS
        );
    }

    /**
     * Create or refresh a session user and persist it (ACL SAVE, to the
     * users.acl on Valkey's volume). Idempotent. Returns its password.
     * Worker only: $valkey signs in as the worker's ACL admin user.
     */
    static function set_user(Valkey $valkey, string $user): string {
        $password = self::password($user);

        $valkey->command('ACL', 'SETUSER', $user, ...self::acl($user, $password));
        $valkey->command('ACL', 'SAVE');

        return $password;
    }

    /**
     * session.save_path for the phpredis session handler. persistent=1 reuses
     * the connection across requests; without it every request reconnects and
     * throughput collapses (benchmarks 2026-10-04).
     */
    static function save_path(string $host, int $port, string $user, string $password): string {
        foreach ([$host, $user, $password] as $value) {
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
                throw new InvalidArgumentException('Unsafe value for the sessions save_path');
            }
        }

        return "tcp://{$host}:{$port}"
            . '?persistent=1&timeout=2&read_timeout=2'
            . '&prefix=' . self::key_prefix($user) . 'sess:'
            . "&auth[]={$user}&auth[]={$password}";
    }

    /**
     * Point the panel's sessions at Valkey. Skipped when the platform already
     * did (this repo deployed as a customer app gets its own ini).
     */
    static function start_platform(): void {
        if (ini_get('session.save_handler') !== 'redis') {
            $user = self::PLATFORM_USER;
            ini_set('session.save_handler', 'redis');
            ini_set('session.save_path', self::save_path(VALKEY_HOST, VALKEY_PORT, $user, self::password($user)));
            ini_set('session.gc_maxlifetime', (string) self::LIFETIME_SECONDS);
        }

        session_start();
    }
}
