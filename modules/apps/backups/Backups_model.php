<?php

require_once __DIR__ . '/traits/App_backup_records.php';

/**
 * @property-read Db $db
 */
class Backups_model extends Model {
    use App_backup_records;
}
