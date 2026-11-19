<?php

    $title = 'Requirements';

    $ok = true;
    $sslrequired = $vars['ssl-required']; // Only warn for now, later we might want to make SSL a requirement

?>

    <div id="form-div">
        <h2>Requirements</h2>
        <p>
            Idno needs a few system components and PHP extensions in order to run properly. We'll check them now.
        </p>
        <p>
            (If you have everything you need, there's a "continue" button at the bottom of the page.)
        </p>

        <div class="components">

            <?php
                $phpversion = Idno\Core\Installer::checkPHPVersion();
            if ($phpversion == 'ok') {
                $class = 'success';
                $text = 'You are running PHP version ' . phpversion() . '.';
            } else if ($phpversion == 'warn') {
                $class = 'warning';
                $text = 'You are running Idno using a very old version of PHP (' . phpversion() . '), which is no longer actively supported. Although Idno will currently still install, some features may not work, so you should upgrade soon. You may need to ask your server administrator to upgrade PHP for you.';
            } else {
                $class = 'failure';
                $text = 'You are running PHP version ' . phpversion() . ', which cannot run Idno. You may need to ask your server administrator to upgrade PHP for you.';
                $ok = false;
            }

            ?>
            <div class="component <?php echo $class?>">

                <h3>PHP version</h3>
                <p>
                    <?php echo $text?>
                </p>

            </div>
            
            <?php

            if (Idno\Common\Page::isSSL()) {
                $class = 'success';
                $text = 'You are running Idno on a secure site.';
            } else {
                if ($sslrequired) {
                    $class = 'failure';
                    $ok = false;
                } else {
                    $class = 'warning';
                }
                $text = 'Your site doesn\'t seem to be loaded over HTTPS. We strongly recommend using HTTPS to make your site secure and protect your privacy.';
            }
            ?>
            <div class="component <?php echo $class ?>">
                <h3>Secure site</h3>
                <p>
                    <?php echo $text?>
                </p>
            </div>

            <?php

            if (function_exists('apache_get_modules')) {
                if (Idno\Core\Installer::rewriteAvailable()) {
                    $class = 'success';
                    $text = 'mod_rewrite is installed and enabled.';
                } else {
                    $class = 'failure';
                    $text = 'mod_rewrite is not installed. Idno cannot process page URLs without it.';
                    $ok = false;
                }
                ?>
                <div class="component <?php echo $class?>">

                    <h3>Apache mod_rewrite</h3>
                    <p>
                        <?php echo $text?>
                    </p>

                    </div>
                    <?php
            } else {
                ?>
                <div class="component">

                    <h3>Apache mod_rewrite</h3>
                    <p>
                        We couldn't detect if mod_rewrite was installed. Idno cannot process page URLs without it. Proceed with caution.
                    </p>

                </div>
                <?php
            }

                $extensions = Idno\Core\Installer::requiredModules();
                asort($extensions);
            foreach($extensions as $extension) {
                if (extension_loaded($extension)) {
                    $class = 'success';
                    $text = 'This extension is installed.';
                } else {
                    $class = 'failure';
                    $text = 'This extension is not installed.';
                    $ok = false;
                }
                ?>
                <div class="component <?php echo $class?>">

                    <h3><?php echo $extension?> for PHP</h3>
                    <p>
                        <?php echo $text?>
                    </p>

                    </div>
                    <?php
            }

                // Idno runs on MySQL, MariaDB or SQLite: one of their PHP modules is enough.
                $database_modules = Idno\Core\Installer::databaseModules();
            if (!Idno\Core\Installer::databaseModuleAvailable()) {
                $ok = false;
            }
            foreach($database_modules as $extension) {
                if (extension_loaded($extension)) {
                    $class = 'success';
                    $text = 'This extension is installed.';
                } else {
                    $class = Idno\Core\Installer::databaseModuleAvailable() ? 'warning' : 'failure';
                    $text = Idno\Core\Installer::databaseModuleAvailable()
                        ? 'This extension is not installed, which is fine: the other database extension is.'
                        : 'This extension is not installed. Idno needs at least one of ' . implode(' and ', $database_modules) . '.';
                }
                ?>
                <div class="component <?php echo $class?>">

                    <h3><?php echo htmlspecialchars($extension) ?> for PHP</h3>
                    <p>
                        <?php echo htmlspecialchars($text) ?>
                    </p>

                    </div>
                    <?php
            }

            ?>

        </div>

<?php

if ($ok) {

    ?>
    <div class="submit page-bottom">
        <p>
            <a class="btn btn-primary btn-lg btn-responsive" href="?stage=settings">Hooray! Let's get you set up.</a>
        </p>
        <p>
            <small><a href="http://docs.idno.co/">Want to get set up manually? Here's our documentation.</a></small>
        </p>
    </div>
    <?php

} else {

    ?>
    <div class="explainer page-bottom">
        <h2>
            We can't move on quite yet.
        </h2>
        <p>
            Unfortunately it looks like you need to install a few things. If you need to, ask your system administrator.
            Scroll up for more details, and <a href="https://idno.co">check out our website</a> for more services
            and information.
        </p>
        <p>
            <small><a href="http://docs.idno.co/">Want more information? Here's our documentation.</a></small>
        </p>
    </div>
    <?php

}

?>

    </div>
