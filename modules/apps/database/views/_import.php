<?php
/**
 * The Import view of views/index.php: upload a .sql file and follow it
 * being checked and run (App_database_import). The panel itself is
 * views/_import_status.php, which the poller below fetches again while
 * the worker is busy; once it is not, #db-import-poller-stop removes the poller.
 *
 * Expects (Database::_workspace_data, App_database_import::_import_data):
 * $import_status_url, $import_polling, and what _import_status.php lists.
 */
?>
<div class="db-import">
    <?php require __DIR__ . '/_import_status.php'; ?>

    <?php if ($import_polling): ?>
    <input type="hidden" id="db-import-poller"
           mx-get="<?= htmlspecialchars($import_status_url) ?>"
           mx-trigger="every 2s"
           mx-target="none"
           mx-select-oob='[
               {"select": "#db-import-status", "target": "#db-import-status", "swap": "innerHTML"},
               {"select": "#db-import-poller-stop", "target": "#db-import-poller", "swap": "delete"}
           ]'>
    <?php endif; ?>
</div>
