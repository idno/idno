<div id="form-div">

    <h2>Saving the configuration file</h2>
    <p>
        You're almost there! Unfortunately, Idno couldn't save the configuration file. This is common: it usually
        means your server is more secure!
    </p>
    <p>
        To continue:
    </p>
    <ul>
        <li>Rename the file called <code>htaccess.dist</code> in /warmup/webserver-configs/htaccess.dist of your Idno installation to
            <code>.htaccess</code> in the root of your Idno install.</li>
        <li>
            Make sure your <code>Uploads</code> directory is set to allow your web server to save files to it.
        </li>
        <li>
            Create a new file called <code>config.ini</code> in /configuration/ of your Idno installation,
            with the following contents.
        </li>
    </ul>
    <p>
        Once you've saved your <code>config.ini</code> file, create the database tables: from the root of
        your Idno installation, run <code>php bin/console idno:upgrade</code>. Then just reload this page to continue.
    </p>

    <textarea class="installation"><?php echo htmlspecialchars($vars['ini_file']); ?></textarea>

</div>
