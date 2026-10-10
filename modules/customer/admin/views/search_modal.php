<?php
/**
 * Search modal form for filtering customers.
 *
 * Loaded via MX into a modal when the Search button is clicked.
 */
$searchable_columns = [
    'email' => 'Email',
    'github_login' => 'Github Login',
];

$form_attr = ['id' => 'search-form'];
echo form_open('customer-admin/submit_search', $form_attr);
?>
    <div class="form-group">
        <?= form_label('Search Query') ?>
        <?= form_input('search_query', '', ['placeholder' => 'Enter search term...', 'autocomplete' => 'off']) ?>
    </div>
    <div class="form-group">
        <?= form_label('Search In') ?>
        <?= form_dropdown('search_column', $searchable_columns) ?>
    </div>
<?= form_close() ?>
