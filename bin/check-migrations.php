<?php

/**
 * CI check for db/migrations (modules/platform/worker/Migrations.php):
 *
 *   php bin/check-migrations.php {fresh database} {base database}
 *
 * {fresh database} has this commit's db/schema.sql: it must already record
 * every migration as applied. {base database} has the base branch's
 * db/schema.sql: after applying the pending migrations to it, its tables and
 * columns must match {fresh database}, so a migration does what the schema
 * says. Connects as DB_HOST/DB_PORT/DB_USER/DB_PASSWORD.
 */

require __DIR__ . '/../modules/platform/worker/Migrations.php';

[, $fresh, $base] = $argv + [null, null, null];

if ($fresh === null || $base === null) {
    fwrite(STDERR, "Usage: php bin/check-migrations.php {fresh database} {base database}\n");
    exit(2);
}

$db = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', (int) (getenv('DB_PORT') ?: 3306)),
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$db->exec("USE `{$fresh}`");
$unrecorded = Migrations::apply($db, fn() => null);

if ($unrecorded !== []) {
    fwrite(STDERR, 'db/schema.sql does not record these migrations as applied: ' . implode(', ', $unrecorded) . "\n");
    exit(1);
}

$db->exec("USE `{$base}`");
echo 'Applied to the base schema: ' . implode(', ', Migrations::apply($db, fn() => null) ?: ['nothing']) . "\n";

$columns = function (string $database) use ($db): array {
    $sql = <<<'SQL'
        SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
        ORDER BY TABLE_NAME, COLUMN_NAME
        SQL;
    $stmt = $db->prepare($sql);
    $stmt->execute([$database]);

    return array_map(fn(array $row) => implode(' | ', array_map('strval', $row)), $stmt->fetchAll(PDO::FETCH_NUM));
};

$expected = $columns($fresh);
$actual = $columns($base);
$missing = array_diff($expected, $actual);
$extra = array_diff($actual, $expected);

if ($missing !== [] || $extra !== []) {
    fwrite(STDERR, "The base schema plus db/migrations differs from db/schema.sql\n");

    foreach ($missing as $line) {
        fwrite(STDERR, "  missing: {$line}\n");
    }

    foreach ($extra as $line) {
        fwrite(STDERR, "  extra:   {$line}\n");
    }

    exit(1);
}

echo "db/migrations bring the base schema to db/schema.sql\n";
