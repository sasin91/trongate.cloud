<div class="card related-records">
    <div class="card-heading"><?= out($heading) ?></div>
    <div class="card-body">
        <?php if (count($rows) === 0): ?>
            <p class="associated-items__empty"><?= out($empty_message) ?></p>
        <?php else: ?>
            <div class="table-container">
                <table class="records-table">
                    <thead>
                        <tr>
                            <?php foreach ($columns as $column): ?>
                                <th class="text-left"><?= out($column) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= anchor($row['url'], out($row['link_text'])) ?></td>
                                <?php foreach ($row['cells'] as $cell): ?>
                                    <td><?= out($cell) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
