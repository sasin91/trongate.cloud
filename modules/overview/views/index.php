<h1>Overview</h1>
<p class="ovw-counts">
    <a href="customer-admin/manage"><strong><?= $counts['customers'] ?></strong> customers</a>
    <a href="project/manage"><strong><?= $counts['projects'] ?></strong> projects</a>
    <a href="app/manage"><strong><?= $counts['apps'] ?></strong> apps</a>
    <a href="tasks/manage"<?= $counts['failed_tasks'] > 0 ? ' class="ovw-bad"' : '' ?>><strong><?= $counts['failed_tasks'] ?></strong> failed tasks today</a>
</p>
<div id="overview-live" mx-get="overview/live" mx-trigger="every 10s">
<?php include __DIR__ . '/_live.php'; ?>
</div>
