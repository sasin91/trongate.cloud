--TEST--
a missing table or column, however deep in the chain, says the database is behind (503)
--FILE--
<?php
require __DIR__ . '/setup.inc';
final class Db_failure extends PDOException {
    public function __construct(string $sqlstate) { parent::__construct("SQLSTATE[$sqlstate]"); $this->code = $sqlstate; }
}
$pdo = new Db_failure('42S02');
$report = Error_report::from_exception(new RuntimeException('query failed', 0, $pdo));
echo $report->status, ' ', $report->title, "\n";
$column = new Db_failure('42S22');
echo Error_report::from_exception($column)->status, "\n";
$other = new Db_failure('23000');
echo Error_report::from_exception($other)->status, "\n";
?>
--EXPECT--
503 Updating
503
500
