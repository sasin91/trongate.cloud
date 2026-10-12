<?php

require_once __DIR__ . '/App_database.php';

/**
 * The Database tab's Schema view: what differs between the schema.sql in an
 * app's repository and the tables its database has now. Read-only; it never
 * writes SQL to fix a difference.
 *
 * Both sides become the same shape (a Schema): per table its columns, keys
 * and foreign keys, each written as one normalized definition, so they
 * compare as plain strings. schema.sql is read by parse(), which follows
 * CREATE TABLE, ALTER TABLE (as phpMyAdmin dumps write keys and
 * AUTO_INCREMENT) and DROP TABLE, and skips everything else (INSERTs, SETs,
 * views). The database side comes from information_schema
 * (App_database::schema_definition, then from_database()).
 *
 * Keys and foreign keys are matched by what they cover, not by name, so a
 * key named differently in the dump is not a difference. Framework-free,
 * like App_database.
 *
 * @phpstan-type Column_parts array{type: string, nullable: bool|null, default: string|null, auto_increment: bool, on_update: string|null, generated: bool}
 * @phpstan-type Item array{name: string, definition: string, parts?: Column_parts}
 * @phpstan-type Table array{name: string, columns: array<string, Item>, keys: array<string, Item>, foreign_keys: array<string, Item>, fk_columns: list<string>}
 * @phpstan-type Schema array<string, Table>
 * @phpstan-type Difference array{table: string, kind: string, name: string, schema: string|null, database: string|null}
 */
class Schema_diff {

    /** Column types whose defaults are numbers */
    const NUMBER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'bit'];

    /** Type names MariaDB stores as another */
    const TYPE_ALIASES = [
        'integer' => 'int',
        'int1' => 'tinyint',
        'int2' => 'smallint',
        'int3' => 'mediumint',
        'int4' => 'int',
        'int8' => 'bigint',
        'bool' => 'tinyint',
        'boolean' => 'tinyint',
        'dec' => 'decimal',
        'numeric' => 'decimal',
        'fixed' => 'decimal',
        'real' => 'double',
        'json' => 'longtext',
    ];

    /** Column names listed for a table only one side has */
    const SUMMARY_COLUMNS = 6;

    // --- schema.sql ------------------------------------------------------------

    /**
     * The tables schema.sql creates, after its ALTERs and DROPs. skipped
     * counts ALTER TABLE changes it could not follow.
     *
     * $explicit_timestamps: the server's explicit_defaults_for_timestamp.
     * When it is off, MariaDB makes TIMESTAMP columns NOT NULL unless they
     * say NULL, and gives the first one a CURRENT_TIMESTAMP default.
     *
     * @return array{tables: Schema, skipped: int}
     */
    static function parse(string $sql, bool $explicit_timestamps = true): array {
        $tables = [];
        $skipped = 0;

        foreach (App_database::statements($sql) as $statement) {
            $tokens = self::tokens($statement);
            $keyword = self::word_at($tokens, 0);

            if ($keyword === 'CREATE') {
                $table = self::create_table($tokens, $explicit_timestamps);

                if ($table !== null) {
                    $tables[$table['name']] = $table;
                }
            } elseif ($keyword === 'ALTER') {
                $skipped += self::alter_table($tables, $tokens, $explicit_timestamps);
            } elseif ($keyword === 'DROP') {
                self::drop_tables($tables, $tokens);
            }
        }

        return ['tables' => $tables, 'skipped' => $skipped];
    }

    /**
     * CREATE [OR REPLACE] [TEMPORARY] TABLE [IF NOT EXISTS] name (definitions) ...
     * Null for any other CREATE, and for CREATE TABLE ... LIKE or ... SELECT.
     *
     * @param list<array{0: string, 1: string}> $tokens
     * @return Table|null
     */
    static function create_table(array $tokens, bool $explicit_timestamps): ?array {
        $i = 1;

        while (in_array(self::word_at($tokens, $i), ['OR', 'REPLACE', 'TEMPORARY'], true)) {
            $i++;
        }

        if (self::word_at($tokens, $i) !== 'TABLE') {
            return null;
        }

        $i++;

        if (self::word_at($tokens, $i) === 'IF') {
            $i += 3;
        }

        [$name, $i] = self::table_name($tokens, $i);

        if ($name === null || ($tokens[$i][0] ?? '') !== 'group') {
            return null;
        }

        $table = self::empty_table($name);

        foreach (self::split_on_commas(self::tokens($tokens[$i][1])) as $definition) {
            $table = self::add_definition($table, $definition, $explicit_timestamps);
        }

        if (!$explicit_timestamps) {
            $table = self::first_timestamp_default($table);
        }

        return $table;
    }

    /**
     * Apply ALTER TABLE name change, change, ... to the parsed tables. Returns
     * how many changes it could not follow.
     *
     * @param Schema $tables
     * @param list<array{0: string, 1: string}> $tokens
     */
    static function alter_table(array &$tables, array $tokens, bool $explicit_timestamps): int {
        $i = 1;

        while (in_array(self::word_at($tokens, $i), ['ONLINE', 'IGNORE'], true)) {
            $i++;
        }

        if (self::word_at($tokens, $i) !== 'TABLE') {
            return 0;
        }

        $i++;

        if (self::word_at($tokens, $i) === 'IF') {
            $i += 2;
        }

        [$name, $i] = self::table_name($tokens, $i);

        if ($name === null || !isset($tables[$name])) {
            return 0;
        }

        $skipped = 0;
        $table = $tables[$name];

        foreach (self::split_on_commas(array_slice($tokens, $i)) as $change) {
            $result = self::alter_change($table, $change, $explicit_timestamps);

            if ($result === null) {
                $skipped++;
            } else {
                $table = $result;
            }
        }

        unset($tables[$name]);
        $tables[$table['name']] = $table;

        return $skipped;
    }

    /**
     * One change of an ALTER TABLE; the table unchanged for table options
     * (AUTO_INCREMENT=5, ENGINE=...), null when it is not understood
     *
     * @param Table $table
     * @param list<array{0: string, 1: string}> $change
     * @return Table|null
     */
    static function alter_change(array $table, array $change, bool $explicit_timestamps): ?array {
        $action = self::word_at($change, 0);
        $rest = array_slice($change, 1);

        if ($action === 'ADD') {
            if (self::word_at($rest, 0) === 'COLUMN') {
                $rest = array_slice($rest, 1);
            }

            if (self::word_at($rest, 0) === 'IF') {
                $rest = array_slice($rest, 3);
            }

            return self::add_definition($table, $rest, $explicit_timestamps);
        }

        if ($action === 'MODIFY') {
            return self::replace_column($table, $rest, null, $explicit_timestamps);
        }

        if ($action === 'CHANGE') {
            $rest = self::without_column_word($rest);
            $old_name = self::identifier($rest[0] ?? ['', '']);

            return self::replace_column($table, array_slice($rest, 1), $old_name, $explicit_timestamps);
        }

        if ($action === 'DROP') {
            return self::drop_from_table($table, $rest);
        }

        if ($action === 'RENAME' && in_array(self::word_at($rest, 0), ['TO', 'AS'], true)) {
            [$new_name] = self::table_name($rest, 1);
            $table['name'] = $new_name ?? $table['name'];

            return $table;
        }

        if (self::is_table_option($action)) {
            return $table;
        }

        return null;
    }

    /**
     * MODIFY [COLUMN] definition, or CHANGE's new definition in place of $old_name
     *
     * @param Table $table
     * @param list<array{0: string, 1: string}> $definition
     * @return Table|null
     */
    static function replace_column(array $table, array $definition, ?string $old_name, bool $explicit_timestamps): ?array {
        $definition = self::without_column_word($definition);
        $new_name = self::identifier($definition[0] ?? ['', '']);
        $old_key = strtolower($old_name ?? $new_name);

        if ($new_name === '' || !isset($table['columns'][$old_key])) {
            return null;
        }

        $columns = [];

        foreach ($table['columns'] as $key => $column) {
            if ($key === $old_key) {
                $columns[strtolower($new_name)] = $column;
            } else {
                $columns[$key] = $column;
            }
        }

        $table['columns'] = $columns;

        return self::add_column($table, $definition, $explicit_timestamps);
    }

    /**
     * DROP [COLUMN] name, DROP PRIMARY KEY, DROP KEY|INDEX name, DROP FOREIGN KEY name
     *
     * @param Table $table
     * @param list<array{0: string, 1: string}> $rest
     * @return Table|null
     */
    static function drop_from_table(array $table, array $rest): ?array {
        $what = self::word_at($rest, 0);

        if ($what === 'PRIMARY') {
            unset($table['keys'][self::primary_signature($table)]);

            return $table;
        }

        if (in_array($what, ['KEY', 'INDEX', 'FOREIGN', 'CONSTRAINT'], true)) {
            $name_at = $what === 'FOREIGN' ? 2 : 1;

            if (self::word_at($rest, $name_at) === 'IF') {
                $name_at += 2;
            }

            return self::drop_named($table, self::identifier($rest[$name_at] ?? ['', '']));
        }

        $rest = self::without_column_word($rest);

        if (self::word_at($rest, 0) === 'IF') {
            $rest = array_slice($rest, 2);
        }

        $name = strtolower(self::identifier($rest[0] ?? ['', '']));

        if (!isset($table['columns'][$name])) {
            return null;
        }

        unset($table['columns'][$name]);

        return $table;
    }

    /**
     * The table without the key or foreign key of this name
     *
     * @param Table $table
     * @return Table
     */
    static function drop_named(array $table, string $name): array {
        foreach (['keys', 'foreign_keys'] as $list) {
            foreach ($table[$list] as $signature => $item) {
                if (strcasecmp($item['name'], $name) === 0) {
                    unset($table[$list][$signature]);
                }
            }
        }

        return $table;
    }

    /**
     * DROP TABLE [IF EXISTS] a, b
     *
     * @param Schema $tables
     * @param list<array{0: string, 1: string}> $tokens
     */
    static function drop_tables(array &$tables, array $tokens): void {
        $i = 1;

        if (self::word_at($tokens, $i) === 'TEMPORARY') {
            $i++;
        }

        if (self::word_at($tokens, $i) !== 'TABLE') {
            return;
        }

        $i++;

        if (self::word_at($tokens, $i) === 'IF') {
            $i += 2;
        }

        foreach (self::split_on_commas(array_slice($tokens, $i)) as $name_tokens) {
            [$name] = self::table_name($name_tokens, 0);
            unset($tables[(string) $name]);
        }
    }

    /**
     * One definition inside CREATE TABLE (...) or after ALTER TABLE ... ADD:
     * a column, a key, or a foreign key
     *
     * @param Table $table
     * @param list<array{0: string, 1: string}> $definition
     * @return Table
     */
    static function add_definition(array $table, array $definition, bool $explicit_timestamps): array {
        $first = self::word_at($definition, 0);

        if ($first === 'CONSTRAINT') {
            // CONSTRAINT [name] PRIMARY KEY | UNIQUE | FOREIGN KEY | CHECK
            $name = '';
            $definition = array_slice($definition, 1);

            if (!in_array(self::word_at($definition, 0), ['PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK'], true)) {
                $name = self::identifier($definition[0] ?? ['', '']);
                $definition = array_slice($definition, 1);
            }

            return self::add_constraint($table, $definition, $name);
        }

        if (in_array($first, ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FULLTEXT', 'SPATIAL', 'FOREIGN'], true)) {
            return self::add_constraint($table, $definition, '');
        }

        if (in_array($first, ['CHECK', 'PERIOD'], true)) {
            return $table;
        }

        return self::add_column($table, $definition, $explicit_timestamps);
    }

    /**
     * A key or a foreign key; CHECK constraints are left out
     *
     * @param Table $table
     * @param list<array{0: string, 1: string}> $definition
     * @return Table
     */
    static function add_constraint(array $table, array $definition, string $name): array {
        $first = self::word_at($definition, 0);

        if ($first === 'FOREIGN') {
            return self::add_foreign_key($table, $definition, $name);
        }

        if ($first === 'CHECK') {
            return $table;
        }

        $kind = match ($first) {
            'PRIMARY' => 'PRIMARY KEY',
            'UNIQUE' => 'UNIQUE KEY',
            'FULLTEXT' => 'FULLTEXT KEY',
            'SPATIAL' => 'SPATIAL KEY',
            default => 'KEY',
        };

        // The key's own name, if any, is the last name before its (columns)
        $columns = null;

        foreach (array_slice($definition, 1) as $token) {
            if ($token[0] === 'group') {
                $columns = self::key_columns($token[1]);
                break;
            }

            if ($token[0] === 'name' || ($token[0] === 'word' && !in_array(strtoupper($token[1]), ['KEY', 'INDEX', 'USING', 'BTREE', 'HASH', 'IF', 'NOT', 'EXISTS'], true))) {
                $name = self::identifier($token);
            }
        }

        if ($columns === null) {
            return $table;
        }

        if ($kind === 'PRIMARY KEY') {
            $name = 'PRIMARY';
            $table = self::columns_not_null($table, $columns);
        }

        return self::add_key($table, $kind, $name, $columns);
    }

    /**
     * FOREIGN KEY [name] (columns) REFERENCES table (columns) [ON DELETE x] [ON UPDATE x]
     *
     * @param Table $table
     * @param list<array{0: string, 1: string}> $definition
     * @return Table
     */
    static function add_foreign_key(array $table, array $definition, string $name): array {
        $columns = null;
        $parent = null;
        $parent_columns = null;
        $on_delete = 'RESTRICT';
        $on_update = 'RESTRICT';
        $count = count($definition);

        for ($i = 2; $i < $count; $i++) {
            $token = $definition[$i];
            $word = self::word_at($definition, $i);

            if ($token[0] === 'group' && $columns === null) {
                $columns = self::key_columns($token[1]);
            } elseif ($token[0] === 'name' && $columns === null) {
                $name = self::identifier($token);
            } elseif ($word === 'REFERENCES') {
                [$parent, $i] = self::table_name($definition, $i + 1);
                $parent_columns = self::key_columns($definition[$i][1] ?? '');
            } elseif ($word === 'ON' && self::word_at($definition, $i + 1) === 'DELETE') {
                [$on_delete, $i] = self::reference_option($definition, $i + 2);
            } elseif ($word === 'ON' && self::word_at($definition, $i + 1) === 'UPDATE') {
                [$on_update, $i] = self::reference_option($definition, $i + 2);
            }
        }

        if ($columns === null || $parent === null || $parent_columns === null) {
            return $table;
        }

        return self::add_foreign_key_item($table, $name, $columns, $parent, $parent_columns, $on_delete, $on_update);
    }

    /**
     * CASCADE, SET NULL, SET DEFAULT, RESTRICT or NO ACTION (which InnoDB treats as RESTRICT)
     *
     * @param list<array{0: string, 1: string}> $tokens
     * @return array{0: string, 1: int} the option and the index of its last token
     */
    static function reference_option(array $tokens, int $i): array {
        $word = self::word_at($tokens, $i);

        if ($word === 'SET' || $word === 'NO') {
            $option = $word . ' ' . self::word_at($tokens, $i + 1);

            return [$option === 'NO ACTION' ? 'RESTRICT' : $option, $i + 1];
        }

        return [$word, $i];
    }

    /**
     * A column: name type [attributes]. An inline PRIMARY KEY or UNIQUE adds that key too.
     *
     * @param Table $table
     * @param list<array{0: string, 1: string}> $definition
     * @return Table
     */
    static function add_column(array $table, array $definition, bool $explicit_timestamps): array {
        $name = self::identifier($definition[0] ?? ['', '']);

        if ($name === '' || ($definition[1][0] ?? '') !== 'word') {
            return $table;
        }

        [$type, $i] = self::column_type($definition, 1);
        $column = [
            'type' => $type,
            'nullable' => null,
            'default' => null,
            'auto_increment' => false,
            'on_update' => null,
            'generated' => false,
        ];
        $count = count($definition);

        for (; $i < $count; $i++) {
            $word = self::word_at($definition, $i);

            if ($word === 'NOT' && self::word_at($definition, $i + 1) === 'NULL') {
                $column['nullable'] = false;
                $i++;
            } elseif ($word === 'NULL') {
                $column['nullable'] = true;
            } elseif ($word === 'DEFAULT') {
                [$column['default'], $i] = self::default_value($definition, $i + 1, $type);
            } elseif ($word === 'AUTO_INCREMENT') {
                $column['auto_increment'] = true;
            } elseif ($word === 'ON' && self::word_at($definition, $i + 1) === 'UPDATE') {
                [$column['on_update'], $i] = self::default_value($definition, $i + 2, $type);
            } elseif ($word === 'PRIMARY' || ($word === 'KEY' && self::word_at($definition, $i - 1) !== 'UNIQUE')) {
                $column['nullable'] = false;
                $table = self::add_key($table, 'PRIMARY KEY', 'PRIMARY', [strtolower($name)]);
            } elseif ($word === 'UNIQUE') {
                $table = self::add_key($table, 'UNIQUE KEY', $name, [strtolower($name)]);
            } elseif ($word === 'AS' || $word === 'GENERATED') {
                $column['generated'] = true;
            } elseif (in_array($word, ['COMMENT', 'COLLATE', 'CHARSET'], true)) {
                $i++;
            } elseif ($word === 'CHARACTER') {
                $i += 2;
            } elseif ($word === 'REFERENCES' || $word === 'CHECK') {
                // Inline REFERENCES and CHECK are not compared
                break;
            }
        }

        if ($column['nullable'] === null) {
            $column['nullable'] = !self::timestamp_not_null($column['type'], $explicit_timestamps);
        }

        $table['columns'][strtolower($name)] = [
            'name' => $name,
            'definition' => self::column_definition($column),
            'parts' => $column,
        ];

        return $table;
    }

    /**
     * Without explicit_defaults_for_timestamp, a TIMESTAMP that does not say NULL is NOT NULL
     */
    static function timestamp_not_null(string $type, bool $explicit_timestamps): bool {
        return !$explicit_timestamps && preg_match('/^timestamp(\(\d\))?$/', $type) === 1;
    }

    /**
     * Without explicit_defaults_for_timestamp, MariaDB gives the table's first
     * TIMESTAMP column with neither NULL nor a DEFAULT a CURRENT_TIMESTAMP
     * default and ON UPDATE, and later ones a zero date default.
     *
     * @param Table $table
     * @return Table
     */
    static function first_timestamp_default(array $table): array {
        $first = true;

        foreach ($table['columns'] as $key => $column) {
            $parts = $column['parts'] ?? null;

            if ($parts === null || !self::timestamp_not_null($parts['type'], false) || $parts['nullable']) {
                continue;
            }

            if ($parts['default'] === null && $first) {
                $parts['default'] = 'current_timestamp()';
                $parts['on_update'] = 'current_timestamp()';
            } elseif ($parts['default'] === null) {
                $parts['default'] = "'0000-00-00 00:00:00'";
            }

            $first = false;
            $table['columns'][$key]['parts'] = $parts;
            $table['columns'][$key]['definition'] = self::column_definition($parts);
        }

        return $table;
    }

    /**
     * A column type from its tokens: the name, its (arguments) and UNSIGNED / ZEROFILL
     *
     * @param list<array{0: string, 1: string}> $tokens
     * @return array{0: string, 1: int} the normalized type and the index after it
     */
    static function column_type(array $tokens, int $i): array {
        $type = strtolower($tokens[$i][1]);
        $i++;

        // DOUBLE PRECISION, CHARACTER VARYING, NATIONAL VARCHAR, LONG VARCHAR
        $second = self::word_at($tokens, $i);

        if (in_array($second, ['PRECISION', 'VARYING', 'VARCHAR', 'CHAR'], true)) {
            $type .= ' ' . strtolower($second);
            $i++;
        }

        if (($tokens[$i][0] ?? '') === 'group') {
            $type .= '(' . $tokens[$i][1] . ')';
            $i++;
        }

        while (in_array(self::word_at($tokens, $i), ['UNSIGNED', 'SIGNED', 'ZEROFILL'], true)) {
            $type .= ' ' . strtolower($tokens[$i][1]);
            $i++;
        }

        return [self::normalize_type($type), $i];
    }

    /**
     * A type as information_schema.COLUMNS.COLUMN_TYPE writes it, without
     * integer display widths: int(11) and INT both become int
     */
    static function normalize_type(string $type): string {
        $type = trim((string) preg_replace('/\s+/', ' ', $type));

        if (!preg_match('/^([a-z0-9_ ]+?)\s*(\((.*)\))?((?:\s+(?:unsigned|signed|zerofill))*)$/is', $type, $match)) {
            return $type;
        }

        $name = strtolower(trim($match[1]));
        $arguments = trim($match[3]);
        $attributes = strtolower(trim($match[4]));

        $name = match ($name) {
            'double precision' => 'double',
            'character varying', 'national varchar', 'nvarchar' => 'varchar',
            'national char', 'nchar' => 'char',
            'long varchar', 'long' => 'mediumtext',
            default => self::TYPE_ALIASES[$name] ?? $name,
        };

        $arguments = (string) preg_replace("/'\s*,\s*'/", "','", $arguments);
        $arguments = (string) preg_replace('/^(\d+)\s*,\s*(\d+)$/', '$1,$2', $arguments);

        if (in_array($name, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)) {
            $arguments = '';
        }

        if ($name === 'decimal' && $arguments === '') {
            $arguments = '10,0';
        } elseif ($name === 'decimal' && ctype_digit($arguments)) {
            $arguments .= ',0';
        }

        // SIGNED is the default, so it is left out
        $attribute_words = preg_split('/\s+/', $attributes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $attributes = implode(' ', array_diff($attribute_words, ['signed']));

        $normalized = $name . ($arguments === '' ? '' : '(' . $arguments . ')');

        if ($attributes !== '') {
            $normalized .= ' ' . $attributes;
        }

        return $normalized;
    }

    /**
     * A DEFAULT (or ON UPDATE) value: a 'string', a number, NULL, a function
     * such as CURRENT_TIMESTAMP, or an (expression)
     *
     * @param list<array{0: string, 1: string}> $tokens
     * @return array{0: string, 1: int} the normalized value and the index of its last token
     */
    static function default_value(array $tokens, int $i, string $type): array {
        $token = $tokens[$i] ?? ['word', ''];

        if ($token[0] === 'string') {
            return [self::normalize_default($token[1], true, $type), $i];
        }

        if ($token[0] === 'group') {
            return ['(' . $token[1] . ')', $i];
        }

        $value = $token[1];
        $next = $tokens[$i + 1] ?? null;

        if ($next !== null && $next[0] === 'group') {
            $value .= '(' . $next[1] . ')';
            $i++;
        } elseif ($next !== null && $next[0] === 'string' && in_array(strtolower($value), ['b', 'x'], true)) {
            $value = strtolower($value) . "'" . $next[1] . "'";
            $i++;
        }

        return [self::normalize_default($value, false, $type), $i];
    }

    /**
     * A default as information_schema.COLUMNS.COLUMN_DEFAULT writes it:
     * 'text' quoted, numbers bare (0.50 as 0.5), NULL, current_timestamp()
     */
    static function normalize_default(string $value, bool $is_string, string $type): string {
        $base_type = strtolower((string) preg_replace('/[\s(].*$/s', '', $type));
        $is_number_type = in_array($base_type, self::NUMBER_TYPES, true);

        if ($is_number_type && is_numeric($value)) {
            return self::number_text($value);
        }

        if ($is_string) {
            return "'" . str_replace("'", "''", $value) . "'";
        }

        $lower = strtolower($value);

        if ($lower === 'null') {
            return 'NULL';
        }

        if (in_array($lower, ['current_timestamp', 'current_timestamp()', 'now()', 'localtime', 'localtime()', 'localtimestamp', 'localtimestamp()'], true)) {
            return 'current_timestamp()';
        }

        if (preg_match('/^current_timestamp\((\d)\)$|^now\((\d)\)$/', $lower, $match)) {
            return 'current_timestamp(' . ($match[1] !== '' ? $match[1] : $match[2]) . ')';
        }

        if (is_numeric($value)) {
            return "'" . $value . "'";
        }

        if (in_array($lower, ['true', 'false'], true)) {
            return $lower === 'true' ? '1' : '0';
        }

        return $value;
    }

    /**
     * 0.50 as 0.5, 1.00 as 1, -0 as 0
     */
    static function number_text(string $value): string {
        $value = trim($value);

        if (str_contains($value, '.') && !str_contains(strtolower($value), 'e')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        if ($value === '' || $value === '-0' || $value === '-') {
            return '0';
        }

        return $value;
    }

    // --- The database ----------------------------------------------------------

    /**
     * The same shape as parse() gives, from information_schema rows
     * (App_database::schema_definition)
     *
     * @param list<array{table: string, name: string, column_type: string, nullable: bool, default: string|null, extra: string}> $columns
     * @param list<array{table: string, name: string, non_unique: bool, index_type: string, column: string, sub_part: string|null}> $keys one row per column, in order
     * @param list<array{table: string, name: string, column: string, parent: string, parent_column: string, on_delete: string, on_update: string}> $foreign_keys one row per column, in order
     * @return Schema
     */
    static function from_database(array $columns, array $keys, array $foreign_keys): array {
        $tables = [];

        foreach ($columns as $row) {
            $tables[$row['table']] ??= self::empty_table($row['table']);
            $tables[$row['table']] = self::add_database_column($tables[$row['table']], $row);
        }

        $tables = self::add_database_keys($tables, $keys);

        return self::add_database_foreign_keys($tables, $foreign_keys);
    }

    /**
     * @param Table $table
     * @param array{table: string, name: string, column_type: string, nullable: bool, default: string|null, extra: string} $row
     * @return Table
     */
    static function add_database_column(array $table, array $row): array {
        $type = self::normalize_type($row['column_type']);
        $extra = strtolower($row['extra']);
        $on_update = null;

        if (preg_match('/on update (\S+)/', $extra, $match)) {
            $on_update = self::normalize_default($match[1], false, $type);
        }

        $column = [
            'type' => $type,
            'nullable' => $row['nullable'],
            'default' => self::database_default($row['default'], $type),
            'auto_increment' => str_contains($extra, 'auto_increment'),
            'on_update' => $on_update,
            'generated' => str_contains($extra, 'generated') || str_contains($extra, 'virtual') || str_contains($extra, 'persistent') || str_contains($extra, 'stored'),
        ];

        $table['columns'][strtolower($row['name'])] = [
            'name' => $row['name'],
            'definition' => self::column_definition($column),
        ];

        return $table;
    }

    /**
     * COLUMN_DEFAULT as parse() writes defaults. MariaDB writes a string
     * default quoted ('abc'), a NULL default as NULL, and no default as SQL NULL.
     */
    static function database_default(?string $default, string $type): ?string {
        if ($default === null) {
            return null;
        }

        if (strlen($default) >= 2 && $default[0] === "'" && str_ends_with($default, "'")) {
            $text = str_replace("''", "'", substr($default, 1, -1));

            return self::normalize_default($text, true, $type);
        }

        return self::normalize_default($default, false, $type);
    }

    /**
     * @param Schema $tables
     * @param list<array{table: string, name: string, non_unique: bool, index_type: string, column: string, sub_part: string|null}> $rows
     * @return Schema
     */
    static function add_database_keys(array $tables, array $rows): array {
        $grouped = [];

        foreach ($rows as $row) {
            $id = $row['table'] . "\0" . $row['name'];
            $grouped[$id] ??= ['table' => $row['table'], 'name' => $row['name'], 'kind' => self::database_key_kind($row), 'columns' => []];
            $grouped[$id]['columns'][] = strtolower($row['column']) . ($row['sub_part'] === null ? '' : '(' . $row['sub_part'] . ')');
        }

        foreach ($grouped as $key) {
            if (isset($tables[$key['table']])) {
                $tables[$key['table']] = self::add_key($tables[$key['table']], $key['kind'], $key['name'], $key['columns']);
            }
        }

        return $tables;
    }

    /**
     * @param array{name: string, non_unique: bool, index_type: string} $row
     */
    static function database_key_kind(array $row): string {
        if ($row['name'] === 'PRIMARY') {
            return 'PRIMARY KEY';
        }

        $index_type = strtoupper($row['index_type']);

        if ($index_type === 'FULLTEXT' || $index_type === 'SPATIAL') {
            return $index_type . ' KEY';
        }

        return $row['non_unique'] ? 'KEY' : 'UNIQUE KEY';
    }

    /**
     * @param Schema $tables
     * @param list<array{table: string, name: string, column: string, parent: string, parent_column: string, on_delete: string, on_update: string}> $rows
     * @return Schema
     */
    static function add_database_foreign_keys(array $tables, array $rows): array {
        $grouped = [];

        foreach ($rows as $row) {
            $id = $row['table'] . "\0" . $row['name'];
            $grouped[$id] ??= ['table' => $row['table'], 'name' => $row['name'], 'columns' => [], 'parent' => $row['parent'], 'parent_columns' => [], 'on_delete' => $row['on_delete'], 'on_update' => $row['on_update']];
            $grouped[$id]['columns'][] = strtolower($row['column']);
            $grouped[$id]['parent_columns'][] = strtolower($row['parent_column']);
        }

        foreach ($grouped as $key) {
            if (!isset($tables[$key['table']])) {
                continue;
            }

            $on_delete = strtoupper($key['on_delete']) === 'NO ACTION' ? 'RESTRICT' : strtoupper($key['on_delete']);
            $on_update = strtoupper($key['on_update']) === 'NO ACTION' ? 'RESTRICT' : strtoupper($key['on_update']);
            $tables[$key['table']] = self::add_foreign_key_item($tables[$key['table']], $key['name'], $key['columns'], $key['parent'], $key['parent_columns'], $on_delete, $on_update);
        }

        return $tables;
    }

    // --- The differences -------------------------------------------------------

    /**
     * Every difference, table by table in name order: a table only one side
     * has, then per table its columns (in schema.sql's order), keys and
     * foreign keys that are missing, extra or defined differently.
     * schema or database is null on the side that does not have it.
     *
     * A plain KEY only the database has, on exactly a foreign key's columns,
     * is the index MariaDB adds for that foreign key, not a difference.
     *
     * @param Schema $schema parse()'s tables
     * @param Schema $database from_database()
     * @return list<Difference>
     */
    static function differences(array $schema, array $database): array {
        $names = array_unique([...array_keys($schema), ...array_keys($database)]);
        sort($names);
        $differences = [];

        foreach ($names as $name) {
            $name = (string) $name;

            if (!isset($database[$name])) {
                $differences[] = self::table_difference($name, self::table_summary($schema[$name]), null);
            } elseif (!isset($schema[$name])) {
                $differences[] = self::table_difference($name, null, self::table_summary($database[$name]));
            } else {
                $differences = [...$differences, ...self::table_differences($schema[$name], $database[$name])];
            }
        }

        return $differences;
    }

    /**
     * Differences inside one table both sides have
     *
     * @param Table $schema
     * @param Table $database
     * @return list<Difference>
     */
    static function table_differences(array $schema, array $database): array {
        $differences = [
            ...self::item_differences($schema['name'], 'Column', $schema['columns'], $database['columns']),
            ...self::item_differences($schema['name'], 'Key', $schema['keys'], self::without_foreign_key_indexes($database['keys'], $schema)),
            ...self::item_differences($schema['name'], 'Foreign key', $schema['foreign_keys'], $database['foreign_keys']),
        ];

        return $differences;
    }

    /**
     * Items (columns, keys or foreign keys) one side lacks or defines differently
     *
     * @param array<string, Item> $schema
     * @param array<string, Item> $database
     * @return list<Difference>
     */
    static function item_differences(string $table, string $kind, array $schema, array $database): array {
        $differences = [];

        foreach ($schema as $id => $item) {
            $live = $database[$id] ?? null;

            if ($live === null) {
                $differences[] = ['table' => $table, 'kind' => $kind, 'name' => $item['name'], 'schema' => $item['definition'], 'database' => null];
            } elseif ($live['definition'] !== $item['definition']) {
                $differences[] = ['table' => $table, 'kind' => $kind, 'name' => $item['name'], 'schema' => $item['definition'], 'database' => $live['definition']];
            }
        }

        foreach ($database as $id => $item) {
            if (!isset($schema[$id])) {
                $differences[] = ['table' => $table, 'kind' => $kind, 'name' => $item['name'], 'schema' => null, 'database' => $item['definition']];
            }
        }

        return $differences;
    }

    /**
     * The database's keys without the plain KEYs MariaDB added for schema.sql's foreign keys
     *
     * @param array<string, Item> $keys
     * @param Table $schema
     * @return array<string, Item>
     */
    static function without_foreign_key_indexes(array $keys, array $schema): array {
        foreach ($schema['fk_columns'] as $columns) {
            $signature = 'KEY (' . $columns . ')';

            if (isset($keys[$signature]) && !isset($schema['keys'][$signature])) {
                unset($keys[$signature]);
            }
        }

        return $keys;
    }

    /**
     * @return Difference
     */
    static function table_difference(string $name, ?string $schema, ?string $database): array {
        return ['table' => $name, 'kind' => 'Table', 'name' => $name, 'schema' => $schema, 'database' => $database];
    }

    /**
     * "4 columns: id, name, email, created_at" for a table only one side has
     *
     * @param Table $table
     */
    static function table_summary(array $table): string {
        $names = array_column($table['columns'], 'name');
        $count = count($names);
        $listed = array_slice($names, 0, self::SUMMARY_COLUMNS);
        $summary = $count . ($count === 1 ? ' column' : ' columns');

        if ($listed === []) {
            return $summary;
        }

        $summary .= ': ' . implode(', ', $listed);

        if ($count > count($listed)) {
            $summary .= ', …';
        }

        return $summary;
    }

    // --- Building tables -------------------------------------------------------

    /**
     * @return Table
     */
    static function empty_table(string $name): array {
        return ['name' => $name, 'columns' => [], 'keys' => [], 'foreign_keys' => [], 'fk_columns' => []];
    }

    /**
     * A column's normalized definition: type, NULL or NOT NULL, DEFAULT, AUTO_INCREMENT, ON UPDATE
     *
     * @param array{type: string, nullable: bool|null, default: string|null, auto_increment: bool, on_update: string|null, generated: bool} $column
     */
    static function column_definition(array $column): string {
        $definition = $column['type'];
        $definition .= $column['nullable'] ? ' NULL' : ' NOT NULL';

        // A NULL default is what a nullable column has anyway
        if ($column['default'] !== null && $column['default'] !== 'NULL') {
            $definition .= ' DEFAULT ' . $column['default'];
        }

        if ($column['auto_increment']) {
            $definition .= ' AUTO_INCREMENT';
        }

        if ($column['on_update'] !== null) {
            $definition .= ' ON UPDATE ' . $column['on_update'];
        }

        if ($column['generated']) {
            $definition .= ' GENERATED';
        }

        return $definition;
    }

    /**
     * A key under its signature (kind and columns), so names do not matter when comparing
     *
     * @param Table $table
     * @param list<string> $columns lower-case, with any (prefix length)
     * @return Table
     */
    static function add_key(array $table, string $kind, string $name, array $columns): array {
        $signature = $kind . ' (' . implode(', ', $columns) . ')';
        $label = $kind === 'PRIMARY KEY' ? 'PRIMARY' : $name;

        if ($label === '') {
            $label = $columns[0] ?? '';
        }

        $table['keys'][$signature] = ['name' => $label, 'definition' => $signature];

        return $table;
    }

    /**
     * @param Table $table
     * @param list<string> $columns
     * @param list<string> $parent_columns
     * @return Table
     */
    static function add_foreign_key_item(array $table, string $name, array $columns, string $parent, array $parent_columns, string $on_delete, string $on_update): array {
        $signature = '(' . implode(', ', $columns) . ') REFERENCES ' . $parent . ' (' . implode(', ', $parent_columns) . ')';
        $definition = $signature;

        if ($on_delete !== 'RESTRICT') {
            $definition .= ' ON DELETE ' . $on_delete;
        }

        if ($on_update !== 'RESTRICT') {
            $definition .= ' ON UPDATE ' . $on_update;
        }

        $table['foreign_keys'][$signature] = ['name' => $name === '' ? implode(', ', $columns) : $name, 'definition' => $definition];
        $table['fk_columns'][] = implode(', ', $columns);

        return $table;
    }

    /**
     * The table with these columns NOT NULL (a primary key's columns always are)
     *
     * @param Table $table
     * @param list<string> $columns
     * @return Table
     */
    static function columns_not_null(array $table, array $columns): array {
        foreach ($columns as $column) {
            $key = (string) preg_replace('/\(\d+\)$/', '', $column);
            $parts = $table['columns'][$key]['parts'] ?? null;

            if ($parts === null || $parts['nullable'] === false) {
                continue;
            }

            $parts['nullable'] = false;

            if ($parts['default'] === 'NULL') {
                $parts['default'] = null;
            }

            $table['columns'][$key]['parts'] = $parts;
            $table['columns'][$key]['definition'] = self::column_definition($parts);
        }

        return $table;
    }

    /**
     * The signature of the table's primary key, or '' when it has none
     *
     * @param Table $table
     */
    static function primary_signature(array $table): string {
        foreach (array_keys($table['keys']) as $signature) {
            if (str_starts_with($signature, 'PRIMARY KEY')) {
                return $signature;
            }
        }

        return '';
    }

    /**
     * A key's (`a`, `b`(10) DESC) as lower-case column names, with any prefix length
     *
     * @return list<string>
     */
    static function key_columns(string $inside): array {
        $columns = [];

        foreach (self::split_on_commas(self::tokens($inside)) as $part) {
            if ($part === []) {
                continue;
            }

            $column = strtolower(self::identifier($part[0]));

            if (($part[1][0] ?? '') === 'group' && ctype_digit(trim($part[1][1]))) {
                $column .= '(' . trim($part[1][1]) . ')';
            }

            $columns[] = $column;
        }

        return $columns;
    }

    // --- Tokens ----------------------------------------------------------------

    /**
     * SQL as tokens: [type, text]. Types: name (a `quoted` or "quoted" name,
     * unquoted), string (a 'string', unescaped), group (what is inside a
     * pair of parentheses, as written), word, and punct (, . =). Comments
     * and whitespace are dropped.
     *
     * @return list<array{0: string, 1: string}>
     */
    static function tokens(string $sql): array {
        $tokens = [];
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if (ctype_space($char)) {
                $i++;
            } elseif ($char === '#' || ($char === '-' && substr($sql, $i, 2) === '--' && ctype_space($sql[$i + 2] ?? ' '))) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;
            } elseif ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
            } elseif ($char === "'" || $char === '"' || $char === '`') {
                [$text, $i] = self::quoted($sql, $i);
                $tokens[] = [$char === "'" ? 'string' : 'name', $text];
            } elseif ($char === '(') {
                [$text, $i] = self::group($sql, $i);
                $tokens[] = ['group', $text];
            } elseif ($char === ',' || $char === '.' || $char === '=' || $char === ')' || $char === ';') {
                $tokens[] = ['punct', $char];
                $i++;
            } else {
                preg_match('/[^\s,()\'"`=;]+/A', $sql, $match, 0, $i);
                $word = $match[0] ?? $char;
                $i += strlen($word);

                $tokens = [...$tokens, ...self::word_tokens($word)];
            }
        }

        return $tokens;
    }

    /**
     * A bare word as tokens: a dotted name (db.table) is name . name, a number stays whole
     *
     * @return list<array{0: string, 1: string}>
     */
    static function word_tokens(string $word): array {
        if (is_numeric($word)) {
            return [['word', $word]];
        }

        $tokens = [];

        foreach (preg_split('/(\.)/', $word, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            if ($piece === '.') {
                $tokens[] = ['punct', '.'];
            } else {
                $tokens[] = ['word', $piece];
            }
        }

        return $tokens;
    }

    /**
     * A quoted string or name from $start: its text unescaped, and the index after it
     *
     * @return array{0: string, 1: int}
     */
    static function quoted(string $sql, int $start): array {
        $quote = $sql[$start];
        $length = strlen($sql);
        $text = '';

        for ($i = $start + 1; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === '\\' && $quote !== '`') {
                $text .= $sql[$i + 1] ?? '';
                $i++;
            } elseif ($char === $quote && ($sql[$i + 1] ?? '') === $quote) {
                $text .= $quote;
                $i++;
            } elseif ($char === $quote) {
                return [$text, $i + 1];
            } else {
                $text .= $char;
            }
        }

        return [$text, $length];
    }

    /**
     * What is inside the parentheses that open at $start (nested ones and
     * quotes included), and the index after the closing one
     *
     * @return array{0: string, 1: int}
     */
    static function group(string $sql, int $start): array {
        $depth = 0;
        $length = strlen($sql);

        for ($i = $start; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'" || $char === '"' || $char === '`') {
                [, $after] = self::quoted($sql, $i);
                $i = $after - 1;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return [trim(substr($sql, $start + 1, $i - $start - 1)), $i + 1];
                }
            }
        }

        return [trim(substr($sql, $start + 1)), $length];
    }

    /**
     * Tokens split at top-level commas (a comma inside a group is part of it)
     *
     * @param list<array{0: string, 1: string}> $tokens
     * @return list<list<array{0: string, 1: string}>>
     */
    static function split_on_commas(array $tokens): array {
        $parts = [[]];

        foreach ($tokens as $token) {
            if ($token === ['punct', ',']) {
                $parts[] = [];
            } else {
                $parts[count($parts) - 1][] = $token;
            }
        }

        return array_values(array_filter($parts, fn(array $part) => $part !== []));
    }

    /**
     * A word token in upper case, or '' when the token there is not a word
     *
     * @param list<array{0: string, 1: string}> $tokens
     */
    static function word_at(array $tokens, int $i): string {
        if (($tokens[$i][0] ?? '') !== 'word') {
            return '';
        }

        return strtoupper($tokens[$i][1]);
    }

    /**
     * A name token's text, or a bare word's
     *
     * @param array{0: string, 1: string} $token
     */
    static function identifier(array $token): string {
        if ($token[0] === 'name' || $token[0] === 'word') {
            return $token[1];
        }

        return '';
    }

    /**
     * The table name at $i (db.table gives table), and the index after it
     *
     * @param list<array{0: string, 1: string}> $tokens
     * @return array{0: string|null, 1: int}
     */
    static function table_name(array $tokens, int $i): array {
        $name = self::identifier($tokens[$i] ?? ['', '']);

        if ($name === '') {
            return [null, $i];
        }

        if (($tokens[$i + 1] ?? null) === ['punct', '.']) {
            $table = self::identifier($tokens[$i + 2] ?? ['', '']);

            return [$table === '' ? null : $table, $i + 3];
        }

        return [$name, $i + 1];
    }

    /**
     * Tokens without a leading COLUMN keyword
     *
     * @param list<array{0: string, 1: string}> $tokens
     * @return list<array{0: string, 1: string}>
     */
    static function without_column_word(array $tokens): array {
        if (self::word_at($tokens, 0) === 'COLUMN') {
            return array_slice($tokens, 1);
        }

        return $tokens;
    }

    /**
     * ALTER TABLE changes that only set table options (AUTO_INCREMENT=5, ENGINE=InnoDB, ...)
     */
    static function is_table_option(string $word): bool {
        return in_array($word, ['AUTO_INCREMENT', 'ENGINE', 'DEFAULT', 'CHARSET', 'CHARACTER', 'COLLATE', 'COMMENT', 'ROW_FORMAT', 'ALGORITHM', 'LOCK', 'FORCE', 'ORDER', 'CONVERT', 'KEY_BLOCK_SIZE', 'PACK_KEYS', 'STATS_PERSISTENT', 'ENABLE', 'DISABLE'], true);
    }
}
