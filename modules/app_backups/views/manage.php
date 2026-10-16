<h1>Manage Deleted app backups</h1>
<?= flashdata() ?>
<?php if (!empty($search_query)): ?>
    <p>Showing results for <strong><?= out($search_query) ?></strong></p>
<?php endif; ?>
<?php
echo '<p class="flex-row justify-between">';
if ((count($rows) > 9) || (!empty($search_query))) {
    $btn_attr = [
        'class' => 'alt',
        'mx-get' => 'app_backups/search_modal',
        'mx-build-modal' => json_encode([
            'id' => 'search-modal',
            'modalHeading' => 'Search Deleted app backups',
            'modalFooter' => '<button class="alt" onclick="closeModal()">Cancel</button><button form="search-form">Search</button>'
        ])
    ];
    echo form_button('search_btn', 'Search <i class="tg tg-search"></i>', $btn_attr);
}
echo '</p>';
if (empty($rows)) {
    echo '<p>There are currently no records to display.</p>';
    return;
}
echo Modules::run('pagination/display', $pagination_data);
?>

<div class="table-container">
    <table class="records-table">
        <thead>
            <tr>
                <th colspan="8">
                    <div>
                        <div>&nbsp;</div>
                        <div>Records Per Page: <?php
                        $dropdown_attr['onchange'] = 'setPerPage()';
                        echo form_dropdown('per_page', $per_page_options, $selected_per_page, $dropdown_attr); 
                        ?></div>
                    </div>                    
                </th>
            </tr>
            <tr>
                <th class="text-left">App Id</th>
            <th class="text-left">Status</th>
            <th class="text-left">Database Bytes</th>
            <th class="text-left">Files Bytes</th>
            <th class="text-left">Error</th>
            <th class="text-left">Created At</th>
            <th class="text-left">Ready At</th>
            <th class="text-left">Expires At</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($rows as $row): 
                ?>
                <tr>
                <td><?= out($row->app_id) ?></td>
                <td><?= out($row->status) ?></td>
                <td><?= out($row->database_bytes) ?></td>
                <td><?= out($row->files_bytes) ?></td>
                <td><?= out($row->error) ?></td>
                <td><?= out($row->created_at) ?></td>
                <td><?= out($row->ready_at) ?></td>
                <td><?= out($row->expires_at) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php 
if(count($rows)>9) {
    unset($pagination_data['include_showing_statement']);
    echo Modules::run('pagination/display', $pagination_data);
}
?>

<script>
function setPerPage() {
    const selectedIndex = document.querySelector('select[name="per_page"]').value;
    window.location.href = '<?= BASE_URL ?>app_backups/set_per_page/' + selectedIndex;
}
</script>
