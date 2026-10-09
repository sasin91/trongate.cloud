<?php

require_once __DIR__ . '/Mail_capture.php';
require_once __DIR__ . '/Mail_message.php';

/**
 * The inbox's writes to app_mail (platform DB): one row per captured
 * message, with the summary the Mail tab lists, and the retention limits of
 * Mail_capture (newest KEEP_PER_APP, MAX_APP_BYTES, KEEP_DAYS).
 *
 * Framework-free: used by the inbox (server.php) over PDO.
 */
final class Mail_store {

    function __construct(private PDO $db) {
    }

    /**
     * Store a message for an app, then drop what is over its limits
     *
     * @param list<string> $recipients RCPT TO addresses
     */
    function store(int $app_id, string $mail_from, array $recipients, string $raw): int {
        $message = Mail_message::parse($raw);
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO app_mail (app_id, mail_from, recipients, from_header, subject, bytes, raw)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            SQL);
        $stmt->bindValue(1, $app_id, PDO::PARAM_INT);
        $stmt->bindValue(2, self::cut($mail_from, 320));
        $stmt->bindValue(3, self::cut(implode(', ', $recipients), 2000));
        $stmt->bindValue(4, self::cut($message['from'], 500));
        $stmt->bindValue(5, self::cut($message['subject'], 500));
        $stmt->bindValue(6, strlen($raw), PDO::PARAM_INT);
        $stmt->bindValue(7, $raw, PDO::PARAM_LOB);
        $stmt->execute();
        $id = (int) $this->db->lastInsertId();

        $this->trim_app($app_id);

        return $id;
    }

    /**
     * Keep an app's newest KEEP_PER_APP messages, at most MAX_APP_BYTES together
     */
    function trim_app(int $app_id): void {
        $stmt = $this->db->prepare('SELECT id, bytes FROM app_mail WHERE app_id = ? ORDER BY id DESC LIMIT ' . (Mail_capture::KEEP_PER_APP + 1));
        $stmt->bindValue(1, $app_id, PDO::PARAM_INT);
        $stmt->execute();
        $total = 0;
        $kept = 0;
        $cutoff = null;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $total += (int) $row['bytes'];

            // Always keep the newest one: MAX_MESSAGE_BYTES is below MAX_APP_BYTES
            if ($kept > 0 && ($kept >= Mail_capture::KEEP_PER_APP || $total > Mail_capture::MAX_APP_BYTES)) {
                $cutoff = (int) $row['id'];
                break;
            }

            $kept++;
        }

        if ($cutoff !== null) {
            $delete = $this->db->prepare('DELETE FROM app_mail WHERE app_id = ? AND id <= ?');
            $delete->bindValue(1, $app_id, PDO::PARAM_INT);
            $delete->bindValue(2, $cutoff, PDO::PARAM_INT);
            $delete->execute();
        }
    }

    /**
     * Drop messages older than KEEP_DAYS, for every app
     *
     * @return int how many
     */
    function expire(): int {
        return (int) $this->db->exec('DELETE FROM app_mail WHERE created_at < NOW() - INTERVAL ' . Mail_capture::KEEP_DAYS . ' DAY');
    }

    private static function cut(string $value, int $characters): string {
        return mb_substr($value, 0, $characters, 'UTF-8');
    }
}
