<h1>Manage Scheduled Jobs</h1>
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
                <th colspan="4">
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
                <th class="text-left">Script</th>
            <th class="text-left">Last Queued At</th>
            <th class="text-left">Last Finished At</th>
            <th class="text-left">Last Failed At</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($rows as $row): 
                ?>
                <tr>
                <td><?= out($row->script) ?></td>
                <td><?= out($row->last_queued_at) ?></td>
                <td><?= out($row->last_finished_at) ?></td>
                <td><?= out($row->last_failed_at) ?></td>
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
    window.location.href = '<?= BASE_URL ?>scheduled_jobs/set_per_page/' + selectedIndex;
}
</script>
