<?php

require_once APPPATH . 'modules/platform/live/App_database.php';
require_once APPPATH . 'modules/platform/live/Schema_diagram.php';

/**
 * The Database tab's Diagram view (?view=diagram): the app's tables and the
 * relations between them, drawn by views/_diagram.php from the plain values
 * built here. ?around=1 shows only the open table and its neighbours,
 * ?trongate=1 adds Trongate's own tables.
 */
trait App_database_diagram {

    /**
     * Values for views/_diagram.php
     *
     * @param array<string, array{name: string, view: bool, rows: int|null}> $tables App_database::tables()
     * @return array{diagram: array<string, mixed>, diagram_cards: list<array<string, mixed>>, diagram_all_url: string, diagram_around_url: string|null, diagram_trongate_url: string, diagram_around: bool, diagram_trongate: bool, diagram_summary: string}
     * @throws PDOException
     */
    function _diagram_data(object $app, App_database $database, array $tables, string $current): array {
        $around = $current !== '' && !empty($_GET['around']);
        $show_trongate = !empty($_GET['trongate']);

        $schema = $database->schema_tables($tables);
        $relations = Schema_diagram::relations($schema, $database->schema_foreign_keys());
        $diagram = Schema_diagram::layout($schema, $relations, $current, $around, $show_trongate);

        return [
            'diagram' => $diagram,
            'diagram_cards' => $this->_diagram_cards($app, $diagram['cards']),
            'diagram_all_url' => $this->_diagram_url($app, $current, false, $show_trongate),
            'diagram_around_url' => $current === '' ? null : $this->_diagram_url($app, $current, true, $show_trongate),
            'diagram_trongate_url' => $this->_diagram_url($app, $current, $around, !$show_trongate),
            'diagram_around' => $around,
            'diagram_trongate' => $show_trongate,
            'diagram_summary' => $this->_diagram_summary($diagram, $current, $around),
        ];
    }

    /**
     * The cards with the link that opens each table's data
     *
     * @param list<array<string, mixed>> $cards Schema_diagram::layout() cards
     * @return list<array<string, mixed>>
     */
    function _diagram_cards(object $app, array $cards): array {
        $linked = [];

        foreach ($cards as $card) {
            $card['href'] = $this->_database_url($app, ['table' => $card['name']]);
            $linked[] = $card;
        }

        return $linked;
    }

    function _diagram_url(object $app, string $current, bool $around, bool $show_trongate): string {
        $query = ['view' => 'diagram'];

        if ($current !== '') {
            $query['table'] = $current;
        }

        if ($around) {
            $query['around'] = 1;
        }

        if ($show_trongate) {
            $query['trongate'] = 1;
        }

        return $this->_database_url($app, $query);
    }

    /**
     * The status bar line, like "7 tables · 7 relations, 2 without a constraint"
     *
     * @param array{tables: int, relations: int, inferred: int, hidden: int} $diagram
     */
    function _diagram_summary(array $diagram, string $current, bool $around): string {
        $tables = $diagram['tables'] === 1 ? '1 table' : $diagram['tables'] . ' tables';
        $relations = $diagram['relations'] === 1 ? '1 relation' : $diagram['relations'] . ' relations';

        if ($diagram['inferred'] > 0) {
            $relations .= ', ' . $diagram['inferred'] . ' without a constraint';
        }

        if ($around) {
            return $current . ' · ' . $relations;
        }

        return $tables . ' · ' . $relations;
    }
}
