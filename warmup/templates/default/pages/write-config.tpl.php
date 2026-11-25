<?php

    $config_path = $vars['config_path'];
    $server_user = $vars['server_user'];

    ?>
<div id="form-div">

    <h2>Saving the configuration file</h2>

    <?php if (!empty($vars['missing'])) { ?>

        <div class="alerts" role="alert">
            <div class="alert">
                <p>
                    There is no configuration file that Idno can read at
                    <code><?php echo htmlspecialchars($config_path) ?></code> yet.
                </p>
                <p>
                    Go back one page in your browser for its contents, save the file, and continue from
                    there. If the file is there, check that the web server's
                    user<?php if ($server_user !== '') { ?> (<code><?php echo htmlspecialchars($server_user) ?></code>)<?php } ?>
                    may read it.
                </p>
            </div>
        </div>
        <p>
            <a href="?stage=settings">Start the settings again</a>
        </p>

    <?php } else { ?>

        <p>
            You're almost there! Your settings are right: the database is ready and the upload folder
            works. But Idno is not allowed to save its configuration file, because the web server's
            user<?php if ($server_user !== '') { ?> (<code><?php echo htmlspecialchars($server_user) ?></code>)<?php } ?>
            may not write to the <code>configuration</code> folder. That is a sensible way to run a server;
            it means you save the file yourself.
        </p>
        <p>
            To continue:
        </p>
        <ul>
            <li>
                Create the file <code><?php echo htmlspecialchars($config_path) ?></code> with exactly the
                contents below. It holds the database password, so keep it to yourself.
            </li>
            <li>
                Make sure the web server's user can read it.
            </li>
            <?php if (empty($vars['htaccess'])) { ?>
                <li>
                    If your web server is Apache, copy <code>warmup/webserver-configs/htaccess.dist</code>
                    to <code><?php echo htmlspecialchars($vars['htaccess_path']) ?></code>.
                </li>
            <?php } ?>
        </ul>

        <textarea class="installation" readonly spellcheck="false" aria-label="Contents of config.ini"><?php echo htmlspecialchars($vars['ini_file']); ?></textarea>

        <form action="?stage=finish" method="post">
            <input type="hidden" name="site_title" value="<?php echo htmlspecialchars($vars['site_title']) ?>">
            <div class="submit page-bottom">
                <input type="submit" class="btn btn-primary btn-lg btn-responsive" value="I've saved the file. Continue">
            </div>
        </form>

    <?php } ?>

</div>
