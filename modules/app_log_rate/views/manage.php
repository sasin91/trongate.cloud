<h1>Manage Log Rates</h1>
<?= flashdata() ?>
<?php
echo '<p class="flex-row justify-between">';
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
                <th colspan="5">
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
            <th class="text-left">Bucket</th>
            <th class="text-left">Total</th>
            <th class="text-left">Warnings</th>
            <th class="text-left">Errors</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($rows as $row): 
                ?>
                <tr>
                <td><?= out($row->app_id) ?></td>
                <td><?= out($row->bucket) ?></td>
                <td><?= out($row->total) ?></td>
                <td><?= out($row->warnings) ?></td>
                <td><?= out($row->errors) ?></td>
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
    window.location.href = '<?= BASE_URL ?>app_log_rate/set_per_page/' + selectedIndex;
}
</script>
