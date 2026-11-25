<?php

    // One problem of the installer, beside the field it concerns: plain text, and the
    // commands that would fix it. See \Idno\Core\Installer::problem().
    $problem = $vars['problem'] ?? null;

if (!empty($problem['text'])) {

    ?>
    <div class="alerts" role="alert">
        <div class="alert">
            <p><?php echo htmlspecialchars($problem['text']); ?></p>
            <?php foreach (($problem['commands'] ?? []) as $command) { ?>
                <p><code><?php echo htmlspecialchars($command); ?></code></p>
            <?php } ?>
        </div>
    </div>
    <?php

}
