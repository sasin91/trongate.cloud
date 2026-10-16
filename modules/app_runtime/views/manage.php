<h1>Manage Runtimes</h1>
<?= flashdata() ?>
<?php if (!empty($search_query)): ?>
    <p>Showing results for <strong><?= out($search_query) ?></strong></p>
<?php endif; ?>
<?php
echo '<p class="flex-row justify-between">';
if ((count($rows) > 9) || (!empty($search_query))) {
    $btn_attr = [
        'class' => 'alt',
        'mx-get' => 'app_runtime/search_modal',
        'mx-build-modal' => json_encode([
            'id' => 'search-modal',
            'modalHeading' => 'Search Runtimes',
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
            <th class="text-left">Always On</th>
            <th class="text-left">Trongate Env</th>
            <th class="text-left">Mail Capture</th>
            <th class="text-left">Updated At</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($rows as $row): 
                ?>
                <tr>
                <td><?= out($row->app_id) ?></td>
                <td><?= out($row->always_on) ?></td>
                <td><?= out($row->trongate_env) ?></td>
                <td><?= out($row->mail_capture) ?></td>
                <td><?= out($row->updated_at) ?></td>
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
    window.location.href = '<?= BASE_URL ?>app_runtime/set_per_page/' + selectedIndex;
}
</script>
