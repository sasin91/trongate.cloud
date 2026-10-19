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
                            <?php foreach ($columns as $label): ?>
                                <th class="text-left"><?= out($label) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php foreach ($columns as $column => $label): ?>
                                    <?php if ($column === $link_column): ?>
                                        <td><?= anchor($url_path . $row->id, out($row->$column)) ?></td>
                                    <?php else: ?>
                                        <td><?= out($row->$column) ?></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
