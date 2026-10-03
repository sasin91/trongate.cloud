<?php foreach ($branches as $branch): ?>
    <option value="<?= htmlspecialchars($branch['name']) ?>">
        <?= htmlspecialchars($branch['name']) ?>
    </option>
<?php endforeach; ?>