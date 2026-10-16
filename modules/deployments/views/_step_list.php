<?php
/**
 * The deploy's steps (Deploy_steps::steps). Rendered in the "What happens"
 * card, and swapped into #deploy-steps by the log poller while the
 * deployment runs, so the current step follows it.
 */
?>
<ol id="deploy-steps" class="deploy-steps">
    <?php foreach ($steps as $step): ?>
        <li class="deploy-step deploy-step-<?= htmlspecialchars($step['state']) ?>">
            <h4><?= htmlspecialchars($step['title']) ?></h4>
            <?php foreach ($step['text'] as $line): ?>
                <p><?= htmlspecialchars($line) ?></p>
            <?php endforeach; ?>
            <?php if ($step['config']): ?>
                <div class="deploy-config">
                    <table>
                        <thead>
                            <tr><th>File</th><th>Setting</th><th>Value</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($step['config'] as $row): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($row['file']) ?></code></td>
                                    <td><code><?= htmlspecialchars($row['setting']) ?></code></td>
                                    <td><?= htmlspecialchars($row['value']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>
