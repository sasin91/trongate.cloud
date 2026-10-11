<?php

/**
 * The Database tab's Diagram view: an app's tables as cards and their
 * relations as lines, laid out here so the view only draws what it gets.
 *
 * Two kinds of relation. Declared: a FOREIGN KEY constraint. Inferred: a
 * Trongate relation, which Flo's module relations builder makes as a plain
 * `{singular}_id` column with no constraint (or an associated_a_and_b
 * junction table of such columns), found by name: `job_id` points at
 * `jobs` (or `job`) when that table has an `id` primary key.
 *
 * Layout: tables that are pointed at stand left of the tables pointing at
 * them, one column per step. Tables with no relations get a column of
 * their own at the right. Framework-free, like App_database.
 *
 * @phpstan-type Column array{name: string, column_type: string, key: bool}
 * @phpstan-type Table array{rows: int|null, columns: list<Column>}
 * @phpstan-type Relation array{table: string, column: string, parent: string, parent_column: string, declared: bool}
 * @phpstan-type Card_column array{name: string, type: string, marker: string}
 * @phpstan-type Card array{name: string, x: int, y: int, rows: string, columns: list<Card_column>, more: int, current: bool}
 * @phpstan-type Wire array{path: string, parent_mark: string, child_mark: string, declared: bool, dim: bool}
 */
class Schema_diagram {

    /** Card size and spacing, in pixels (CSS in database.css uses the same numbers) */
    const CARD_WIDTH = 230;
    const HEAD_HEIGHT = 34;
    const ROW_HEIGHT = 24;
    const COLUMN_GAP = 80;
    const CARD_GAP = 28;
    const PADDING = 28;
    const HEADING_HEIGHT = 24;

    /** Columns listed on a card; keys and foreign keys always are, the rest show as "+ n more" */
    const MAX_CARD_COLUMNS = 12;

    /** Stock Trongate tables, hidden unless asked for */
    const TRONGATE_PREFIX = 'trongate_';

    /**
     * Relations between the tables: every declared foreign key, then every
     * inferred Trongate relation on a column no constraint already covers.
     *
     * @param array<string, Table> $tables
     * @param list<array{table: string, column: string, parent: string, parent_column: string}> $foreign_keys one row per constrained column
     * @return list<Relation>
     */
    static function relations(array $tables, array $foreign_keys): array {
        $relations = [];
        $constrained = [];

        foreach ($foreign_keys as $foreign_key) {
            if (!isset($tables[$foreign_key['table']], $tables[$foreign_key['parent']])) {
                continue;
            }

            $relations[] = $foreign_key + ['declared' => true];
            $constrained[$foreign_key['table'] . '.' . $foreign_key['column']] = true;
        }

        foreach ($tables as $name => $table) {
            foreach ($table['columns'] as $column) {
                if ($column['key'] || isset($constrained[$name . '.' . $column['name']])) {
                    continue;
                }

                $parent = self::inferred_parent($name, $column['name'], $tables);

                if ($parent !== null) {
                    $relations[] = ['table' => $name, 'column' => $column['name'], 'parent' => $parent, 'parent_column' => 'id', 'declared' => false];
                }
            }
        }

        return $relations;
    }

    /**
     * The table a Trongate-style `{singular}_id` column of $table points at, or null
     *
     * @param array<string, Table> $tables
     */
    static function inferred_parent(string $table, string $column, array $tables): ?string {
        if (!str_ends_with($column, '_id') || strlen($column) <= 3) {
            return null;
        }

        $singular = substr($column, 0, -3);
        $candidates = [$singular . 's', $singular . 'es', $singular];

        if (str_ends_with($singular, 'y')) {
            array_unshift($candidates, substr($singular, 0, -1) . 'ies');
        }

        // Trongate's own tables leave out the prefix (trongate_users.user_level_id), and come first
        if (str_starts_with($table, self::TRONGATE_PREFIX)) {
            $prefixed = [];

            foreach ($candidates as $candidate) {
                $prefixed[] = self::TRONGATE_PREFIX . $candidate;
            }

            $candidates = array_merge($prefixed, $candidates);
        }

        foreach ($candidates as $candidate) {
            if (isset($tables[$candidate]) && self::has_id_key($tables[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param Table $table
     */
    static function has_id_key(array $table): bool {
        foreach ($table['columns'] as $column) {
            if ($column['name'] === 'id' && $column['key']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The diagram of the whole database, or of one table and its neighbours
     * when $around names a table.
     *
     * @param array<string, Table> $tables base tables only, no views
     * @param list<Relation> $relations from relations()
     * @param string $current the table open in the Database tab, highlighted ('' for none)
     * @return array{width: int, height: int, cards: list<Card>, wires: list<Wire>, headings: list<array{text: string, x: int, y: int}>, tables: int, relations: int, inferred: int, hidden: int}
     */
    static function layout(array $tables, array $relations, string $current, bool $around, bool $show_trongate): array {
        $shown = self::shown_tables($tables, $show_trongate);
        $hidden = count($tables) - count($shown);
        $relations = self::relations_between($relations, $shown);

        if ($around && isset($shown[$current])) {
            $columns = self::columns_around($current, $relations);
            $headings = [$current . ' points to', '', 'Points to ' . $current];
        } else {
            $around = false;
            $columns = self::columns_by_depth(array_keys($shown), $relations);
            $headings = [];
        }

        $diagram = self::place($columns, $shown, $relations, $current, $around, $headings);
        $diagram['tables'] = count(array_merge(...$columns));
        $diagram['relations'] = count($diagram['wires']);
        $diagram['inferred'] = self::inferred_count($diagram['wires']);
        $diagram['hidden'] = $hidden;

        return $diagram;
    }

    /**
     * @param list<Wire> $wires
     */
    private static function inferred_count(array $wires): int {
        $count = 0;

        foreach ($wires as $wire) {
            if (!$wire['declared']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, Table> $tables
     * @return array<string, Table>
     */
    private static function shown_tables(array $tables, bool $show_trongate): array {
        if ($show_trongate) {
            return $tables;
        }

        $shown = [];

        foreach ($tables as $name => $table) {
            if (!str_starts_with($name, self::TRONGATE_PREFIX)) {
                $shown[$name] = $table;
            }
        }

        return $shown;
    }

    /**
     * @param list<Relation> $relations
     * @param array<string, Table> $shown
     * @return list<Relation>
     */
    private static function relations_between(array $relations, array $shown): array {
        $between = [];

        foreach ($relations as $relation) {
            if (isset($shown[$relation['table']], $shown[$relation['parent']])) {
                $between[] = $relation;
            }
        }

        return $between;
    }

    /**
     * Columns of table names, left to right: a table stands one column right
     * of the right-most table it points at, then each table moves as far
     * right as the tables pointing at it allow. Tables with no relations
     * (self references aside) go in a last column of their own.
     *
     * @param list<string> $names
     * @param list<Relation> $relations
     * @return list<list<string>>
     */
    private static function columns_by_depth(array $names, array $relations): array {
        $links = [];

        foreach ($relations as $relation) {
            if ($relation['table'] !== $relation['parent']) {
                $links[] = [$relation['table'], $relation['parent']];
            }
        }

        $related = [];

        foreach ($links as [$child, $parent]) {
            $related[$child] = true;
            $related[$parent] = true;
        }

        $order = self::link_order(array_values(array_intersect($names, array_keys($related))), $links);
        $place = array_flip($order);
        $forward = [];

        foreach ($links as [$child, $parent]) {
            if ($place[$parent] < $place[$child]) {
                $forward[] = [$child, $parent]; // links back up a cycle are left out of the layout
            }
        }

        // One column right of the right-most table it points at
        $depth = [];

        foreach ($order as $name) {
            $depth[$name] = 0;

            foreach ($forward as [$child, $parent]) {
                if ($child === $name) {
                    $depth[$name] = max($depth[$name], $depth[$parent] + 1);
                }
            }
        }

        // Then as far right as the tables pointing at it allow, so lines stay short
        foreach (array_reverse($order) as $name) {
            $nearest_child = null;

            foreach ($forward as [$child, $parent]) {
                if ($parent === $name) {
                    $nearest_child = min($nearest_child ?? $depth[$child], $depth[$child]);
                }
            }

            if ($nearest_child !== null) {
                $depth[$name] = max($depth[$name], $nearest_child - 1);
            }
        }

        $columns = [];

        foreach ($depth as $name => $column) {
            $columns[$column][] = $name;
        }

        ksort($columns);
        $columns = array_values($columns);
        $unrelated = array_values(array_diff($names, array_keys($depth)));

        if ($unrelated !== []) {
            $columns[] = $unrelated;
        }

        return self::ordered($columns, $links);
    }

    /**
     * The related tables, each after the tables it points at. In a cycle the
     * table with fewest parents still waiting goes first, then by name.
     *
     * @param list<string> $names
     * @param list<array{string, string}> $links [child, parent]
     * @return list<string>
     */
    private static function link_order(array $names, array $links): array {
        sort($names);
        $order = [];
        $waiting = array_flip($names);

        while ($waiting !== []) {
            $next = (string) array_key_first($waiting);
            $fewest = PHP_INT_MAX;

            foreach (array_keys($waiting) as $name) {
                $name = (string) $name; // a table named like a number became an int key
                $parents = self::waiting_parents($name, $links, $waiting);

                if ($parents < $fewest) {
                    $next = $name;
                    $fewest = $parents;
                }
            }

            $order[] = $next;
            unset($waiting[$next]);
        }

        return $order;
    }

    /**
     * @param list<array{string, string}> $links [child, parent]
     * @param array<string, int> $waiting
     */
    private static function waiting_parents(string $name, array $links, array $waiting): int {
        $count = 0;

        foreach ($links as [$child, $parent]) {
            if ($child === $name && isset($waiting[$parent])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Three columns: what $table points at, $table, what points at $table.
     * A table on both sides shows once, on the left.
     *
     * @param list<Relation> $relations
     * @return list<list<string>>
     */
    private static function columns_around(string $table, array $relations): array {
        $parents = [];
        $children = [];

        foreach ($relations as $relation) {
            if ($relation['table'] === $table && $relation['parent'] !== $table) {
                $parents[$relation['parent']] = true;
            }
        }

        foreach ($relations as $relation) {
            if ($relation['parent'] === $table && $relation['table'] !== $table && !isset($parents[$relation['table']])) {
                $children[$relation['table']] = true;
            }
        }

        $parents = array_keys($parents);
        $children = array_keys($children);
        sort($parents);
        sort($children);

        return [$parents, [$table], $children];
    }

    /**
     * Sorts each column by where its tables' neighbours in earlier columns
     * stand, so lines cross less; the first column, and ties, by name.
     *
     * @param list<list<string>> $columns
     * @param list<array{string, string}> $links
     * @return list<list<string>>
     */
    private static function ordered(array $columns, array $links): array {
        $position = [];

        foreach ($columns as $i => $names) {
            $sorted = [];

            foreach ($names as $name) {
                $sorted[] = [self::neighbour_position($name, $links, $position), $name];
            }

            sort($sorted); // by position, then name
            $columns[$i] = array_column($sorted, 1);

            foreach ($columns[$i] as $place => $name) {
                $position[$name] = $place;
            }
        }

        return $columns;
    }

    /**
     * Average place of the already placed tables $name is linked with; tables with none go last
     *
     * @param list<array{string, string}> $links
     * @param array<string, int> $position
     */
    private static function neighbour_position(string $name, array $links, array $position): float {
        $places = [];

        foreach ($links as [$child, $parent]) {
            if ($child === $name && isset($position[$parent])) {
                $places[] = $position[$parent];
            }

            if ($parent === $name && isset($position[$child])) {
                $places[] = $position[$child];
            }
        }

        return $places === [] ? PHP_FLOAT_MAX : array_sum($places) / count($places);
    }

    /**
     * Cards with pixel positions, and the lines between them
     *
     * @param list<list<string>> $columns
     * @param array<string, Table> $tables
     * @param list<Relation> $relations
     * @param list<string> $headings one per column, '' for none
     * @return array{width: int, height: int, cards: list<Card>, wires: list<Wire>, headings: list<array{text: string, x: int, y: int}>}
     */
    private static function place(array $columns, array $tables, array $relations, string $current, bool $around, array $headings): array {
        $card_columns = [];

        foreach ($columns as $names) {
            foreach ($names as $name) {
                $card_columns[$name] = self::card_columns($name, $tables[$name], $relations);
            }
        }

        $top = $headings === [] ? self::PADDING : self::PADDING + self::HEADING_HEIGHT;
        $heights = [];

        foreach ($columns as $i => $names) {
            $heights[$i] = self::stack_height($names, $card_columns);
        }

        $tallest = $heights === [] ? 0 : max($heights);
        $cards = [];
        $placed = [];
        $heading_rows = [];

        foreach ($columns as $i => $names) {
            $x = self::PADDING + $i * (self::CARD_WIDTH + self::COLUMN_GAP);
            $y = $top + intdiv($tallest - $heights[$i], 2); // centred on the tallest column

            if (($headings[$i] ?? '') !== '' && $names !== []) {
                $heading_rows[] = ['text' => $headings[$i], 'x' => $x, 'y' => $y - self::HEADING_HEIGHT];
            }

            foreach ($names as $name) {
                $shown = $card_columns[$name];
                $cards[] = [
                    'name' => $name,
                    'x' => $x,
                    'y' => $y,
                    'rows' => self::rows_label($tables[$name]['rows']),
                    'columns' => $shown['columns'],
                    'more' => $shown['more'],
                    'current' => $name === $current,
                ];
                $placed[$name] = ['x' => $x, 'y' => $y, 'rows' => array_column($shown['columns'], 'name')];
                $y += self::card_height($shown) + self::CARD_GAP;
            }
        }

        $wires = [];

        foreach ($relations as $relation) {
            if (isset($placed[$relation['table']], $placed[$relation['parent']])) {
                $wires[] = self::wire($relation, $placed, $current, $around);
            }
        }

        $last_column = max(0, count($columns) - 1);

        return [
            'width' => 2 * self::PADDING + $last_column * (self::CARD_WIDTH + self::COLUMN_GAP) + self::CARD_WIDTH,
            'height' => $top + $tallest + self::PADDING,
            'cards' => $cards,
            'wires' => $wires,
            'headings' => $heading_rows,
        ];
    }

    /**
     * "1 row", "1,284 rows"; '' when unknown. InnoDB's count is an estimate.
     */
    private static function rows_label(?int $rows): string {
        if ($rows === null) {
            return '';
        }

        return number_format($rows) . ($rows === 1 ? ' row' : ' rows');
    }

    /**
     * The columns a card lists: primary key and relation columns always,
     * others in table order up to MAX_CARD_COLUMNS.
     *
     * @param Table $table
     * @param list<Relation> $relations
     * @return array{columns: list<Card_column>, more: int}
     */
    private static function card_columns(string $name, array $table, array $relations): array {
        $linked = [];

        foreach ($relations as $relation) {
            if ($relation['table'] === $name) {
                $linked[$relation['column']] = true;
            }
        }

        $must = 0;

        foreach ($table['columns'] as $column) {
            if ($column['key'] || isset($linked[$column['name']])) {
                $must++;
            }
        }

        $room = max(0, self::MAX_CARD_COLUMNS - $must);
        $shown = [];
        $more = 0;

        foreach ($table['columns'] as $column) {
            $marker = '';

            if ($column['key']) {
                $marker = 'key';
            } elseif (isset($linked[$column['name']])) {
                $marker = 'link';
            }

            if ($marker === '' && $room === 0) {
                $more++;
                continue;
            }

            if ($marker === '') {
                $room--;
            }

            $shown[] = ['name' => $column['name'], 'type' => $column['column_type'], 'marker' => $marker];
        }

        return ['columns' => $shown, 'more' => $more];
    }

    /**
     * @param array{columns: list<Card_column>, more: int} $shown
     */
    private static function card_height(array $shown): int {
        $rows = count($shown['columns']) + ($shown['more'] > 0 ? 1 : 0);

        return self::HEAD_HEIGHT + $rows * self::ROW_HEIGHT;
    }

    /**
     * @param list<string> $names
     * @param array<string, array{columns: list<Card_column>, more: int}> $card_columns
     */
    private static function stack_height(array $names, array $card_columns): int {
        $height = 0;

        foreach ($names as $name) {
            $height += self::card_height($card_columns[$name]) + self::CARD_GAP;
        }

        return max(0, $height - self::CARD_GAP);
    }

    /**
     * One line, from the parent's key row to the child's column row: a bar
     * at the parent end, a crow's foot at the child end.
     *
     * @param Relation $relation
     * @param array<string, array{x: int, y: int, rows: list<string>}> $placed
     * @return Wire
     */
    private static function wire(array $relation, array $placed, string $current, bool $around): array {
        $parent = $placed[$relation['parent']];
        $child = $placed[$relation['table']];
        $parent_y = self::row_middle($parent, $relation['parent_column']);
        $child_y = self::row_middle($child, $relation['column']);

        if ($relation['parent'] === $relation['table']) {
            $path = self::loop_path($parent['x'] + self::CARD_WIDTH, $parent_y, $child_y);
            $parent_mark = self::bar_path($parent['x'] + self::CARD_WIDTH, $parent_y, 1);
            $child_mark = self::foot_path($child['x'] + self::CARD_WIDTH, $child_y, 1);
        } elseif ($parent['x'] < $child['x']) {
            $path = self::curve_path($parent['x'] + self::CARD_WIDTH, $parent_y, $child['x'], $child_y);
            $parent_mark = self::bar_path($parent['x'] + self::CARD_WIDTH, $parent_y, 1);
            $child_mark = self::foot_path($child['x'], $child_y, -1);
        } else {
            // A cycle can leave the parent right of the child
            $path = self::curve_path($child['x'] + self::CARD_WIDTH, $child_y, $parent['x'], $parent_y);
            $parent_mark = self::bar_path($parent['x'], $parent_y, -1);
            $child_mark = self::foot_path($child['x'] + self::CARD_WIDTH, $child_y, 1);
        }

        $touches_current = $relation['table'] === $current || $relation['parent'] === $current;

        return [
            'path' => $path,
            'parent_mark' => $parent_mark,
            'child_mark' => $child_mark,
            'declared' => $relation['declared'],
            'dim' => $around && !$touches_current,
        ];
    }

    /**
     * Middle of a column's row on a card; the header's middle when the column is not listed
     *
     * @param array{x: int, y: int, rows: list<string>} $card
     */
    private static function row_middle(array $card, string $column): int {
        $row = array_search($column, $card['rows'], true);

        if ($row === false) {
            return $card['y'] + intdiv(self::HEAD_HEIGHT, 2);
        }

        return $card['y'] + self::HEAD_HEIGHT + $row * self::ROW_HEIGHT + intdiv(self::ROW_HEIGHT, 2);
    }

    private static function curve_path(int $x1, int $y1, int $x2, int $y2): string {
        $bend = intdiv($x2 - $x1, 2);

        return 'M' . $x1 . ' ' . $y1 . ' C' . ($x1 + $bend) . ' ' . $y1 . ' ' . ($x2 - $bend) . ' ' . $y2 . ' ' . $x2 . ' ' . $y2;
    }

    private static function loop_path(int $x, int $y1, int $y2): string {
        $out = $x + 40;

        return 'M' . $x . ' ' . $y1 . ' C' . $out . ' ' . $y1 . ' ' . $out . ' ' . $y2 . ' ' . $x . ' ' . $y2;
    }

    /**
     * The "one" mark: a short upright bar just outside the card edge at $x
     *
     * @param int $side 1 when the line leaves to the right of $x, -1 to the left
     */
    private static function bar_path(int $x, int $y, int $side): string {
        $at = $x + 7 * $side;

        return 'M' . $at . ' ' . ($y - 6) . ' V' . ($y + 6);
    }

    /**
     * The "many" mark: three prongs meeting 10px out from the card edge at $x
     *
     * @param int $side 1 when the line leaves to the right of $x, -1 to the left
     */
    private static function foot_path(int $x, int $y, int $side): string {
        $tip = $x + 10 * $side;

        return 'M' . $tip . ' ' . $y . ' L' . $x . ' ' . ($y - 6)
            . ' M' . $tip . ' ' . $y . ' L' . $x . ' ' . $y
            . ' M' . $tip . ' ' . $y . ' L' . $x . ' ' . ($y + 6);
    }
}
