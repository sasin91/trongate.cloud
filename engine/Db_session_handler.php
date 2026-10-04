<?php
/**
 * PHP sessions in the `php_session` table, so every app replica sees the same
 * session (CSRF tokens, the login token, flashdata). File sessions live on one
 * pod's disk, and a POST landing on the other pod failed its CSRF check.
 *
 * Shares the Db module's persistent connection (same DSN and options).
 * No row locking: concurrent requests in one session write last-wins, which
 * the session data here (tokens, flashdata) tolerates.
 */
class Db_session_handler implements SessionHandlerInterface {

    // Unchanged sessions are written at most this often, to keep them alive
    const TOUCH_INTERVAL_SECONDS = 60;

    private ?PDO $dbh = null;
    private array $read = [];

    public function open(string $path, string $name): bool {
        $config = $GLOBALS['databases']['default'];
        $dsn = 'mysql:host=' . $config['host'] . ';port=' . ($config['port'] ?? '3306') . ';dbname=' . $config['database'] . ';charset=utf8mb4';

        $this->dbh = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        return true;
    }

    public function close(): bool {
        $this->dbh = null;
        return true;
    }

    public function read(string $id): string|false {
        $stmt = $this->dbh->prepare('SELECT data, updated_at FROM php_session WHERE id = ? AND updated_at > ?');
        $stmt->execute([$id, time() - $this->max_lifetime()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->read[$id] = $row === false ? null : $row;

        return $row === false ? '' : $row['data'];
    }

    public function write(string $id, string $data): bool {
        $read = $this->read[$id] ?? null;

        if ($read !== null && $read['data'] === $data && (int) $read['updated_at'] > time() - self::TOUCH_INTERVAL_SECONDS) {
            return true;
        }

        $stmt = $this->dbh->prepare(
            'INSERT INTO php_session (id, data, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)'
        );

        return $stmt->execute([$id, $data, time()]);
    }

    public function destroy(string $id): bool {
        unset($this->read[$id]);
        return $this->dbh->prepare('DELETE FROM php_session WHERE id = ?')->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false {
        $stmt = $this->dbh->prepare('DELETE FROM php_session WHERE updated_at < ?');
        $stmt->execute([time() - $max_lifetime]);

        return $stmt->rowCount();
    }

    private function max_lifetime(): int {
        return (int) ini_get('session.gc_maxlifetime');
    }
}
