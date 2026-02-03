<?php foreach($server_types as $type): ?>
<option value="<?= $type['id'] ?>">[<?= $type['architecture'] ?>] <?= $type['name'] ?> - <?= $type['vcpus'] ?> vCPU, <?= number_format($type['memory']/1024, 0) ?>GB RAM - €<?= $type['price_monthly'] ?>/mo</option>
<?php endforeach; ?>
