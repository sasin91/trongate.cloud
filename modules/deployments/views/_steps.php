<?php
/**
 * The deploy as a timeline (Deploy_steps::steps): each step's heading,
 * which the log poller replaces as the deployment moves on, and what the
 * platform does in it.
 */
?>
<ol class="steps">
    <?php foreach ($steps as $step): ?>
        <li class="step">
            <?php require __DIR__ . '/_step_head.php'; ?>
            <div class="step-body">
                <details class="step-about">
                    <summary>What happens</summary>
                    <?php foreach ($step['text'] as $line): ?>
                        <p><?= htmlspecialchars($line) ?></p>
                    <?php endforeach; ?>
                    <?php if ($step['config']): ?>
                        <div class="step-config">
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
                </details>
            </div>
        </li>
    <?php endforeach; ?>
</ol>
