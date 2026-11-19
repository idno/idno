<?php

    $ok       = true;
    $messages = $vars['messages'];


    $site_title  = $vars['site_title'];
    $mysql_user  = $vars['mysql_user'];
    $mysql_host  = $vars['mysql_host'];
    $mysql_pass  = $vars['mysql_pass'];
    $mysql_name  = $vars['mysql_name'];
    $database    = ($vars['database'] ?? 'mysql') == 'sqlite' ? 'sqlite' : 'mysql';
    $sqlite_path = $vars['sqlite_path'] ?? '';
    $upload_path = $vars['upload_path'];

?>

<div id="form-div">


    <h2>
        Technical settings
    </h2>

    <?php

    if (!empty($messages)) {
        echo $this->__(
            [
            'messages' => $messages
            ]
        )->draw('pages/elements/messages');

    } else {

        $canary = '<p>Rewriting appears to be disabled. Usually this means "AllowOverride None" is set in apache2.conf ';
        $canary .= 'which prevents Idno\'s .htaccess from doing its thing. We tried to fetch a URL that should redirect ';
        $canary .= 'to idno.min.js</p>';
        $canary .= '<p>You can usually fix this by setting <code>AllowOverride All</code> in your Apache configuration.</p>';
        $canary .= '<p>If you think this is an error, you can continue, but you might have problems later on.</p>';

        echo $this->__(
            [
            'id' => 'canary',
            'messages' => $canary,
            'style' => 'display: none;'
            ]
        )->draw('pages/elements/messages');

        ?>
        <div id="success-block" class="alerts success message-success-block" style="display:none;">
            <p>
                Great! You have everything you need to get started.
            </p>
            <p>
                On this screen, we'll ask you how we should connect to your database, and where we should save
                uploaded files like user photos, pictures and audio.
            </p>
        </div>
        <?php

    }

    ?>

    <form action="" method="post">
        <div class="settings-group">
            <h3>1. What are you going to name your site?</h3>

            <p>
                <label class="control-label" for="site_title">
                    Don't worry: you can change this at any time. You can even leave it blank for now if
                    you need more time.
                </label>
            </p>

            <p>
                <input type="text" name="site_title" placeholder="" value="<?php echo htmlspecialchars($site_title ?? '') ?>"
                       class="profile-input" id="site_title">
            </p>
        </div>
        <div class="settings-group">
            <h3>
                2. Your database
            </h3>

            <fieldset class="control-label">
                <legend>Which database should Idno use?</legend>

                <p>
                    <label for="database_mysql">
                        <input type="radio" name="database" value="mysql" id="database_mysql"
                               <?php echo $database == 'mysql' ? 'checked' : '' ?>>
                        MySQL or MariaDB: a database server, best for a busy site
                    </label>
                </p>
                <p>
                    <label for="database_sqlite">
                        <input type="radio" name="database" value="sqlite" id="database_sqlite"
                               <?php echo $database == 'sqlite' ? 'checked' : '' ?>>
                        SQLite: one file on this server, no database server needed, best for a small site
                    </label>
                </p>
            </fieldset>

            <div id="mysql-settings">
                <p class="control-label">
                    Idno needs a single MySQL or MariaDB database, with a user that can connect to it. We recommend
                    that this is a user you have created just for Idno, rather than one you share with other
                    applications.
                    <br><br>
                    You should create your database before entering the details here. If you're using a shared host,
                    you may have an option called "MySQL Database Wizard" that will speed you through the process.
                </p>

                <p>
                    <label class="control-label">
                        MySQL database name<br>
                        <input type="text" name="mysql_name" placeholder="" value="<?php echo htmlspecialchars($mysql_name ?? '') ?>"
                               class="profile-input" data-needed="mysql">
                    </label>
                </p>

                <p>
                    <label class="control-label">
                        MySQL username<br>
                        <input type="text" name="mysql_user" placeholder="" value="<?php echo htmlspecialchars($mysql_user ?? '') ?>"
                               class="profile-input" data-needed="mysql">
                    </label>
                </p>

                <p>
                    <label class="control-label">
                        MySQL password<br>
                        <input type="password" name="mysql_pass" placeholder="" value="" class="profile-input" data-needed="mysql">
                    </label>
                </p>

                <p>
                    <label class="control-label">
                        MySQL server name<br>
                        <input type="text" name="mysql_host" placeholder="<?php echo htmlspecialchars($mysql_host ?? '') ?>"
                               value="localhost" class="profile-input" data-needed="mysql">
                    </label>
                </p>
            </div>

            <div id="sqlite-settings">
                <p class="control-label">
                    SQLite keeps your whole site in one file, so it needs no database server. It suits a small site
                    on one server with a local disk. The web server must be able to write to the folder the file is in:
                    SQLite keeps its journal files beside it. Idno creates the file, and the folder if it is missing.
                </p>

                <p>
                    <label class="control-label" for="sqlite_path">
                        The full path of the database file<br>
                        <input type="text" name="sqlite_path" id="sqlite_path" placeholder=""
                               value="<?php echo htmlspecialchars($sqlite_path) ?>" class="profile-input" data-needed="sqlite">
                    </label>
                </p>
            </div>
        </div>
        <div class="settings-group">
            <h3>
                3. Your upload directory
            </h3>

            <p>
                <label class="control-label" for="upload_path">
                    The full path to a folder that Idno can upload to. By default this is the "Uploads" folder -
                    you should leave this as-is unless you're an experienced system administrator. You need to make
                    sure the web server can save data to it. In your file manager, you should be able
                    to select the folder, and click to enable "group" write access.
                </label>
            </p>

            <p>
                <input type="text" name="upload_path" id="upload_path" placeholder=""
                       value="<?php echo htmlspecialchars($upload_path ?? '') ?>" class="profile-input" required>
            </p>
        </div>
        <div class="submit settings-group page-bottom">
            <input type="submit" class="btn btn-primary btn-lg btn-responsive" value="Onwards!">
        </div>
    </form>
    <script>
        // Show the settings of the chosen database, and require only those.
        function chooseDatabase() {
            var sqlite = document.getElementById('database_sqlite').checked;
            var groups = {mysql: document.getElementById('mysql-settings'), sqlite: document.getElementById('sqlite-settings')};
            groups.mysql.style.display = sqlite ? 'none' : 'block';
            groups.sqlite.style.display = sqlite ? 'block' : 'none';
            var fields = document.querySelectorAll('[data-needed]');
            for (var i = 0; i < fields.length; i++) {
                fields[i].required = fields[i].getAttribute('data-needed') === (sqlite ? 'sqlite' : 'mysql');
            }
        }
        document.getElementById('database_mysql').addEventListener('change', chooseDatabase);
        document.getElementById('database_sqlite').addEventListener('change', chooseDatabase);
        chooseDatabase();

        var request = new XMLHttpRequest();  
        request.open('GET', '../js/canary.js', true);
        request.onreadystatechange = function(){
            if (request.readyState !== 2){
                if (request.status !== 200) {  
                    document.getElementById('canary').style.display = 'block';
                    document.getElementById('success-block').style.display = 'none';
                }  
            } else {
                document.getElementById('success-block').style.display = 'block';
            }
        };
        request.send();
    </script>

</div>
