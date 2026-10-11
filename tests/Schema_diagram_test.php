<?php

/**
 * Tests for Schema_diagram (the Database tab's Diagram view): which relations
 * it finds, and where it puts the tables.
 *
 * Run: php tests/Schema_diagram_test.php (exits non-zero on a failure)
 */

define('APPPATH', dirname(__DIR__) . '/');

require_once APPPATH . 'modules/platform/live/Schema_diagram.php';

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

/** A table: 'id' is the primary key; other names are int columns */
$table = function (string ...$columns): array {
    $list = [];

    foreach ($columns as $column) {
        $list[] = ['name' => $column, 'column_type' => $column === 'id' ? 'int unsigned' : 'int', 'key' => $column === 'id'];
    }

    return ['rows' => 10, 'columns' => $list];
};

$recruiter = [
    'applicants' => $table('id', 'email'),
    'companies' => $table('id', 'name'),
    'interviews' => $table('id', 'applicant_id', 'job_id', 'user_id'),
    'jobs' => $table('id', 'company_id', 'title'),
    'notes' => $table('id', 'applicant_id', 'user_id'),
    'sessions' => $table('id', 'data'),
    'trongate_users' => $table('id', 'code', 'user_level_id'),
    'trongate_user_levels' => $table('id', 'level_title'),
    'users' => $table('id', 'company_id', 'email'),
];

$declared = [
    ['table' => 'interviews', 'column' => 'applicant_id', 'parent' => 'applicants', 'parent_column' => 'id'],
    ['table' => 'interviews', 'column' => 'job_id', 'parent' => 'jobs', 'parent_column' => 'id'],
    ['table' => 'interviews', 'column' => 'user_id', 'parent' => 'users', 'parent_column' => 'id'],
    ['table' => 'jobs', 'column' => 'company_id', 'parent' => 'companies', 'parent_column' => 'id'],
    ['table' => 'users', 'column' => 'company_id', 'parent' => 'companies', 'parent_column' => 'id'],
];

$names = function (array $diagram, int $x): array {
    $found = [];

    foreach ($diagram['cards'] as $card) {
        if ($card['x'] === $x) {
            $found[] = $card['name'];
        }
    }

    return $found;
};

$column_x = fn(int $i) => Schema_diagram::PADDING + $i * (Schema_diagram::CARD_WIDTH + Schema_diagram::COLUMN_GAP);

$test('Trongate _id columns become inferred relations, constraints stay declared', function () use ($same, $recruiter, $declared) {
    $relations = Schema_diagram::relations($recruiter, $declared);
    $inferred = [];

    foreach ($relations as $relation) {
        if (!$relation['declared']) {
            $inferred[] = $relation['table'] . '.' . $relation['column'] . ' -> ' . $relation['parent'];
        }
    }

    $same(5, count($relations) - count($inferred));
    $same(['notes.applicant_id -> applicants', 'notes.user_id -> users', 'trongate_users.user_level_id -> trongate_user_levels'], $inferred);
});

$test('inferred parents follow plural spellings and need an id key', function () use ($same, $table) {
    $tables = ['categories' => $table('id'), 'boxes' => $table('id'), 'staff' => $table('id'), 'logs' => ['rows' => 1, 'columns' => [['name' => 'uuid', 'column_type' => 'char(36)', 'key' => true]]]];

    $same('categories', Schema_diagram::inferred_parent('things', 'category_id', $tables));
    $same('boxes', Schema_diagram::inferred_parent('things', 'box_id', $tables));
    $same('staff', Schema_diagram::inferred_parent('things', 'staff_id', $tables));
    $same(null, Schema_diagram::inferred_parent('things', 'log_id', $tables));
    $same(null, Schema_diagram::inferred_parent('things', '_id', $tables));
    $same(null, Schema_diagram::inferred_parent('things', 'status', $tables));
    $same('trongate_user_levels', Schema_diagram::inferred_parent('trongate_users', 'user_level_id', ['trongate_user_levels' => $table('id')]));
    $same(null, Schema_diagram::inferred_parent('users', 'user_level_id', ['trongate_user_levels' => $table('id')]));
    $same('trongate_users', Schema_diagram::inferred_parent('trongate_tokens', 'user_id', ['users' => $table('id'), 'trongate_users' => $table('id')]));
});

$test('pointed-at tables stand left, unrelated tables last, Trongate tables hidden', function () use ($same, $recruiter, $declared, $names, $column_x) {
    $diagram = Schema_diagram::layout($recruiter, Schema_diagram::relations($recruiter, $declared), 'users', false, false);

    $same(['companies'], $names($diagram, $column_x(0)));
    $same(['jobs', 'users', 'applicants'], $names($diagram, $column_x(1)));
    $same(['interviews', 'notes'], $names($diagram, $column_x(2)));
    $same(['sessions'], $names($diagram, $column_x(3)));
    $same(2, $diagram['hidden']);
    $same(7, $diagram['tables']);
    $same(7, $diagram['relations']);
    $same(2, $diagram['inferred']);
});

$test('around a table: its parents left, its children right', function () use ($same, $recruiter, $declared, $names, $column_x) {
    $diagram = Schema_diagram::layout($recruiter, Schema_diagram::relations($recruiter, $declared), 'users', true, false);

    $same(['companies'], $names($diagram, $column_x(0)));
    $same(['users'], $names($diagram, $column_x(1)));
    $same(['interviews', 'notes'], $names($diagram, $column_x(2)));
    $same(['users points to', 'Points to users'], array_column($diagram['headings'], 'text'));
});

$test('a cycle and a self reference still lay out', function () use ($same, $table, $names, $column_x) {
    $tables = ['a' => $table('id', 'b_id'), 'b' => $table('id', 'a_id'), 'c' => $table('id', 'c_id')];
    $diagram = Schema_diagram::layout($tables, Schema_diagram::relations($tables, []), '', false, false);

    $same(3, $diagram['tables']);
    $same(3, $diagram['relations']);
    $same(['a'], $names($diagram, $column_x(0)));
    $same(['b'], $names($diagram, $column_x(1)));
    $same(['c'], $names($diagram, $column_x(2)));
});

$test('a card lists keys and relation columns even past the column cap', function () use ($same) {
    $columns = [['name' => 'id', 'column_type' => 'int', 'key' => true]];

    for ($i = 1; $i <= 20; $i++) {
        $columns[] = ['name' => 'c' . $i, 'column_type' => 'int', 'key' => false];
    }

    $columns[] = ['name' => 'owner_id', 'column_type' => 'int', 'key' => false];
    $tables = ['things' => ['rows' => null, 'columns' => $columns], 'owners' => ['rows' => 3, 'columns' => [['name' => 'id', 'column_type' => 'int', 'key' => true]]]];
    $diagram = Schema_diagram::layout($tables, Schema_diagram::relations($tables, []), '', false, false);
    $card = $diagram['cards'][1];

    $same('things', $card['name']);
    $same(Schema_diagram::MAX_CARD_COLUMNS, count($card['columns']));
    $same(['name' => 'owner_id', 'type' => 'int', 'marker' => 'link'], $card['columns'][11]);
    $same(10, $card['more']);
    $same('', $card['rows']);
});

exit($failures === 0 ? 0 : 1);
