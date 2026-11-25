<?php

    $values = $vars['values'];
    $errors = $vars['errors'];
    $server_user = $vars['server_user'];

    // A field's value, escaped. The password is never among the values.
    $value = function ($name) use ($values) {
        return htmlspecialchars((string) ($values[$name] ?? ''));
    };
    // The problem with a field, if there is one
    $problem = function ($name) use ($errors) {
        return empty($errors[$name]) ? '' : $this->__(['problem' => $errors[$name]])->draw('pages/elements/problem');
    };

    ?>

<div id="form-div">


    <h2>
        Technical settings
    </h2>

    <?php

    if (!empty($errors)) {

        ?>
        <div class="alerts" role="alert">
            <div class="alert">
                <p>
                    Idno is not installed yet, and nothing was changed. Each problem is described beside
                    its field below. Correct them, enter the database password again, and continue.
                </p>
            </div>
        </div>
        <?php

        echo $problem('config');
        echo $problem('database');

    } else {

        ?>
        <div id="canary" class="alerts" hidden>
            <div class="alert">
                <p>
                    The web server does not seem to pass page addresses on to Idno. We asked it for
                    <code>js/canary.js</code>, which it should answer with one of Idno's own files, and it
                    answered with status <span id="canary-status">?</span>.
                </p>
                <p>
                    On Apache this usually means that <code>.htaccess</code> files are switched off: set
                    <code>AllowOverride All</code> for the site's folder and make sure mod_rewrite is on.
                    On nginx, compare your configuration with <code>warmup/webserver-configs/nginx.conf</code>.
                </p>
                <p>You can continue, but the site's pages will not open until this is fixed.</p>
            </div>
        </div>
        <p>
            On this screen, we'll ask you how to connect to your database, and where to save
            uploaded files like photos and audio.
        </p>
        <?php

    }

    ?>

    <form action="?stage=settings" method="post" autocomplete="off">
        <div class="settings-group">
            <h3>1. What are you going to name your site?</h3>

            <p>
                <label class="control-label" for="site_title">
                    Don't worry: you can change this at any time. You can even leave it blank for now if
                    you need more time.
                </label>
            </p>

            <?php echo $problem('site_title'); ?>
            <p>
                <input type="text" name="site_title" placeholder="" value="<?php echo $value('site_title') ?>"
                       class="profile-input" id="site_title">
            </p>
        </div>
        <div class="settings-group">
            <h3>
                2. Your MySQL settings
            </h3>

            <p class="control-label">
                Idno needs a single MySQL or MariaDB database, with a user that can connect to it and has
                all rights on it. We recommend that this is a user you have created just for Idno, rather
                than one you share with other applications.
                <br><br>
                Create the database before entering the details here: the installer creates the tables,
                not the database. An empty database is best; one that already holds an Idno site is left
                as it is.
            </p>

            <?php echo $problem('mysql_name'); ?>
            <p>
                <label class="control-label">
                    Database name<br>
                    <input type="text" name="mysql_name" placeholder="" value="<?php echo $value('mysql_name') ?>"
                           class="profile-input" required>
                </label>
            </p>

            <?php echo $problem('mysql_user'); ?>
            <p>
                <label class="control-label">
                    Database user name<br>
                    <input type="text" name="mysql_user" placeholder="" value="<?php echo $value('mysql_user') ?>"
                           class="profile-input" required>
                </label>
            </p>

            <?php echo $problem('mysql_pass'); ?>
            <p>
                <label class="control-label">
                    Database password<?php if (!empty($errors)) { ?> (enter it again)<?php } ?><br>
                    <input type="password" name="mysql_pass" placeholder="" value="" class="profile-input" autocomplete="new-password">
                </label>
            </p>

            <?php echo $problem('mysql_host'); ?>
            <p>
                <label class="control-label">
                    Database server<br>
                    <input type="text" name="mysql_host" placeholder="localhost"
                           value="<?php echo $value('mysql_host') ?>" class="profile-input" required>
                </label>
            </p>
            <p class="control-label">
                The name or address of the server the database is on: <code>localhost</code> when it is on
                the same machine as the site, which uses the server's default socket. To reach a port on
                the same machine, enter <code>127.0.0.1</code>.
            </p>

            <?php echo $problem('mysql_port'); ?>
            <p>
                <label class="control-label">
                    Database port<br>
                    <input type="text" name="mysql_port" inputmode="numeric" pattern="[0-9]*" placeholder="3306"
                           value="<?php echo $value('mysql_port') ?>" class="profile-input">
                </label>
            </p>
            <p class="control-label">
                Leave this empty unless your host gave you a port other than 3306.
            </p>
        </div>
        <div class="settings-group">
            <h3>
                3. Your upload folder
            </h3>

            <p>
                <label class="control-label" for="upload_path">
                    The full path of the folder where Idno keeps uploaded files. The installer creates it
                    if it can, and checks it by saving a file there. The folder must be writable by the
                    user the web server runs as<?php if ($server_user !== '') { ?> (<code><?php echo htmlspecialchars($server_user) ?></code>)<?php } ?>,
                    and is best kept outside the folder the web server serves.
                    <code><?php echo htmlspecialchars($vars['default_upload_path']) ?></code> also works: the web
                    server configuration that comes with Idno closes it to visitors.
                </label>
            </p>

            <?php echo $problem('upload_path'); ?>
            <p>
                <input type="text" name="upload_path" id="upload_path" placeholder=""
                       value="<?php echo $value('upload_path') ?>" class="profile-input" required>
            </p>
        </div>
        <div class="submit settings-group page-bottom">
            <input type="submit" class="btn btn-primary btn-lg btn-responsive" value="Onwards!">
        </div>
    </form>
    <script>
        (function () {
            var canary = document.getElementById('canary');
            if (!canary) {
                return;
            }
            var request = new XMLHttpRequest();
            request.open('GET', '../js/canary.js', true);
            request.onreadystatechange = function () {
                if (request.readyState === 4 && request.status !== 200) {
                    document.getElementById('canary-status').textContent = request.status || 'no answer';
                    canary.hidden = false;
                }
            };
            request.send();
        })();
    </script>

</div>
