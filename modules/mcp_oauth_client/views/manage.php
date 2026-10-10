<h1>Manage Assistant Clients</h1>
<?= flashdata() ?>
<?php if (!empty($search_query)): ?>
    <p>Showing results for <strong><?= out($search_query) ?></strong></p>
<?php endif; ?>
<?php
echo '<p class="flex-row justify-between">';
if ((count($rows) > 9) || (!empty($search_query))) {
    $btn_attr = [
        'class' => 'alt',
        'mx-get' => 'mcp_oauth_client/search_modal',
        'mx-build-modal' => json_encode([
            'id' => 'search-modal',
            'modalHeading' => 'Search Assistant Clients',
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
                <th class="text-left">Client Id</th>
            <th class="text-left">Provider</th>
            <th class="text-left">Client Name</th>
            <th class="text-left">Created At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($rows as $row): 
                $link_attr = [
                    'mx-get' => 'mcp_oauth_client/show/'.$row->id,
                    'mx-select' => '.detail-grid',
                    'mx-build-modal' => json_encode([
                        'id' => 'record-preview-modal',
                        'width' => '640px',
                        'modalHeading' => 'Record Preview',
                        'modalFooter' => '<button class="alt" onclick="closeModal()">Cancel</button><button onclick="window.location.href=\''.BASE_URL.segment(1).'/show/'.$row->id.'\'">View Record</button>'
                    ])
                ];
                ?>
                <tr>
                <td><?= anchor('#', out($row->client_id), $link_attr) ?></td>
                <td><?= out($row->provider) ?></td>
                <td><?= out($row->client_name) ?></td>
                <td><?= out($row->created_at) ?></td>                    <td>
                        <div class="actions">
                            <a href="mcp_oauth_client/show/<?= $row->id ?>" class="button alt button-round"><i class="tg tg-eye"></i></a>
                        </div>
                    </td>
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
    window.location.href = '<?= BASE_URL ?>mcp_oauth_client/set_per_page/' + selectedIndex;
}
</script>
