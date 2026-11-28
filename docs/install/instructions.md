# Installing Idno

There are three ways to install Idno. They end in the same place: a configuration file, a database
with Idno's tables in it, and one administrator account.

* **In your browser** (the usual way): upload the files, open your site, and follow four steps.
* **From the command line**: one command, which can also run unattended. See [Command line installation](cliinstaller.md).
* **By hand**: write the configuration yourself (or set environment variables, as a container does), then create the administrator with a command. See "By hand" below.

Before you start, check the [system requirements](requirements.md). The installer checks them too, and tells you how to fix what is missing.

## On shared hosting, in short

1. Download the Idno package and upload its contents to the folder your domain or subdomain points at. Idno has to be at the top of a domain or subdomain (`notes.example.com`), not in a folder of one (`example.com/notes`). Make sure the hidden file `.htaccess` is uploaded too.
2. In your hosting control panel, switch on HTTPS for the domain (often called "SSL" or "Let's Encrypt") and choose PHP 8.4 or later.
3. Open `https://your-domain/` in your browser and follow the steps. If the first page says a folder cannot be written to, open that folder's permissions in the file manager and allow the owner and the group to write.

You do not need to create a database unless you want to use MySQL or MariaDB: the installer's first choice keeps the site in a file on your server.

## In your browser

Open your site's address. A site that has not been set up shows the installer at every address.

**Step 1: Checking your server.** Idno checks the PHP version and extensions, that the web server can write to the folders `configuration`, `data` and `Uploads`, that page addresses reach Idno (URL rewriting), that the web server does not hand out the files in `Uploads` (see "Where uploaded files go"), that the site is at the top of its domain, and that the page was loaded over HTTPS. Anything that needs fixing is explained, with the control-panel step or the command that fixes it; "Check again" repeats the checks. HTTPS is required, except when you are trying Idno on your own computer (`localhost`, `127.0.0.1`, or a name ending in `.localhost` or `.test`).

**Step 2: Your site.** The site's name (you can change it later) and its domain. The domain is read from the address you opened; check it, because it is written to the configuration as the site's address (`url`), which background tasks and the links in the site's mail are built from.

**Step 3: Where should Idno keep your site?** Either a file on this server (SQLite: nothing else to set up, right for a personal site or a small group), or a MySQL or MariaDB database you have already created (MySQL 8.0 or later, MariaDB 10.6 or later; its name, user, password and server, which may be written `host:port`). "Save and set up the site" writes `configuration/config.ini`, then creates Idno's tables; a page says so while that runs.

**Step 4: Create your account.** Your name, a username, your email address and a password of at least 10 characters, typed twice. This is the site's administrator. You are then signed in, on a page that says what to do next: write a first post, review the site's settings, set up email, and, if you want to connect with the Fediverse, turn on background tasks.

Things to know:

* **Nothing is written until step 3 is complete**, and a configuration file that exists is never replaced. Reloading a page, going back, or submitting twice does no harm.
* **Until it has been used, the web installer answers whoever reaches the site first.** Anyone who opens a site with no configuration file can set it up and become its administrator, so install your site as soon as you have uploaded it. Where the server shows that an earlier site was there (see the next point), it asks for a code from the server instead. When Idno cannot connect to a MySQL or MariaDB database, the page says only that (anyone can reach it); the reason, such as a refused password or a server that did not answer, is in the web server's error log.
* **Only the browser that completed step 3 can do step 4.** It is remembered for thirty days, also after you close it. Everyone else sees "This site is being set up". If you cannot use that browser any more, run `php bin/console idno:setup:link` on the server and open the link it prints (it works once), or create the account there: `php bin/console idno:admin:create`.
* **Once the site has an administrator, none of this exists any more.** The installer's addresses are ordinary missing pages, and nobody becomes an administrator by registering.
* **The SQLite database is always `data/idno.sqlite`, and uploads always go to `Uploads/`**, when you install in the browser: the installer of a site that is not set up can be reached by anyone, so it takes no path. To put them elsewhere, install from the command line or by hand.
* **A code from the server, where an earlier site was.** If the server holds what an earlier Idno site left (a database file that is not empty under `data/`, uploaded files under `Uploads/`, or any file in `configuration/` besides the shipped `.htaccess`), the installer sets up nothing, a new site on another database included, until you type the code it writes to `configuration/install-code` (read it on the server, for example `sudo cat configuration/install-code` from Idno's folder). The code shows that you can reach the server's files: a site whose configuration file was lost would otherwise be anyone's to take over, on your server and your address, beside your data. It lasts an hour and works once; nothing but the code is written until it is given. A server as shipped asks for no code. The installer sees only this server's disk: a site whose data is all elsewhere (a MySQL or MariaDB server, an `s3://` upload path) leaves nothing here, so the installer cannot tell it from a new site. If its `configuration/config.ini` is lost, put it back before the site is reachable again, and keep a copy of that file. Putting back the lost `configuration/config.ini` is simpler still.
* **A database that already holds an Idno site is never overwritten, and only someone who can reach the server's files can take it over.** The installer says so and writes a code to the file `configuration/install-code` in Idno's folder (readable only by the web server's user; the web server never serves it). Read it on the server, for example `sudo cat configuration/install-code` from Idno's folder, type it in, check the site's address, and tick the box to use the site. The code lasts an hour and works once; a new one is written when it has expired. Nothing but that file is written until the code is given. This keeps a visitor from taking over a site whose configuration file was lost (after a deploy that left out `configuration/`, say) and pointing it at an address of theirs. A site from Idno 1.x is then upgraded (see [Upgrading](upgrade.md)), and its own accounts sign in as before. With a shell, `php bin/console idno:install --use-existing` does the same without a code; restoring the lost `configuration/config.ini` is simpler still.
* **Email is not set up by the installer.** Until you enter a mail server under Site configuration, Email, the site cannot send mail, including password reset messages.
* MongoDB is no longer supported (see [Upgrading](upgrade.md) to move a MongoDB site).

## By hand

Create `configuration/config.ini` yourself. For SQLite:

    database = "sqlite"
    dbname = "data/idno.sqlite"
    filesystem = "local"
    uploadpath = "/full/path/to/Uploads/"
    url = "https://example.com/"

For MySQL or MariaDB (create the database first):

    database = "MySQL"
    dbname = "Your database name"
    dbhost = "Your database host (eg 'localhost')"
    dbuser = "Your database username"
    dbpass = "Your database password"
    filesystem = "local"
    uploadpath = "/full/path/to/Uploads/"
    url = "https://example.com/"

A non-standard database port is `dbport = "3307"`. Then, from the Idno folder:

    php bin/console idno:upgrade
    php bin/console idno:admin:create

The first creates the tables (opening the site in a browser does the same). The second asks for the administrator's username, email address and password; it works only while the site has no administrator. If you would rather create the account in a browser, `php bin/console idno:setup:link` prints a one-time link to the "Create your account" step. Until the site has an account, it shows "This site is being set up" to everyone, and nobody can register.

### Environment variables (containers)

Every `config.ini` setting can be an environment variable named `IDNO_` plus the setting in upper case (the older `KNOWN_` prefix still works; `IDNO_` wins):

    IDNO_DATABASE = "MySQL"
    IDNO_DBNAME = "IdnoDBName"
    IDNO_DBUSER = "IdnoDBUser"
    IDNO_DBPASS = "IdnoDBPassword"
    IDNO_DBHOST = "your.database.server"
    IDNO_URL = "https://example.com/"
    IDNO_UPLOADPATH = "/data/uploads/"

A variable whose whole value is `true`, `on` or `yes` turns a setting on, and `false`, `off`, `no` or `none` turns it off, in any letter case, as the same words do in `config.ini`. So `IDNO_DEBUG=false` turns debugging off. Any other value is text, exactly as you set it. Passwords and settings that are always text (the title, the database name, the site's address and the like) are never read as on or off, so a password of `off` stays `off`.

`IDNO_DATABASE_URL` (for example `mysql://user:password@host:3306/dbname`, or `sqlite:///data/idno.sqlite`) gives the database in one variable. A site whose environment names a database is installed: it needs no configuration file, and the web installer is not offered. Create the tables and the administrator as above (`idno:upgrade`, then `idno:admin:create`; the password can come from a file or standard input, see [Command line installation](cliinstaller.md)).

### If you're using SQLite

SQLite keeps the whole database in one file, so a small site needs no database server. It suits a site on a single server with a local disk (SQLite 3.38 or later, with PHP's `pdo_sqlite`).

A relative `dbname` is relative to the Idno directory; an absolute one (`/var/lib/idno/idno.sqlite`) is used as it is. As a database URL: `sqlite:///data/idno.sqlite` (three slashes: relative to the Idno directory) or `sqlite:////var/lib/idno/idno.sqlite` (four: absolute). The name of the file must end in `.sqlite`, in lower case, and hold only letters, digits, dots, underscores and dashes, and its path cannot contain `..`: every web server configuration Idno ships refuses to serve a file with that suffix (and the `data/` directory, and files ending in `.sqlite-wal`, `.sqlite-shm` or `.upgrade.lock`), and PHP never runs one. The site refuses to start on an SQLite `dbname` with any other name. If you put the file outside `data/`, keep it outside your document root.

What to know before you choose SQLite:

* **One server, a local disk.** SQLite's write-ahead log needs shared memory between the processes that use the file, so never put the database on a network filesystem (NFS, SMB), and do not run several web servers against one file.
* **Sessions are files.** SQLite cannot lock a session, so an SQLite site keeps its PHP sessions in PHP's session files (as with `sessions_database` off), not in the database. Anyone logged in to a site that moves from MySQL to SQLite logs in again.
* **Search has no full-text index.** A search term of more than five bytes matches any of its words inside longer words, and boolean operators (`+word -other`) are plain characters.
* **Case and accents.** Only the case of the letters A to Z is ignored when text is compared: `ÉTÉ` does not find `été`, `café` does not find `cafe`.
* **Who owns the files.** If you set the site up from a terminal (`php bin/console idno:install`, `php bin/console idno:upgrade`, `php bin/console idno:import:mongo --target=sqlite:///...`), the database file, its directory and the files SQLite and the upgrade create beside it (`-wal`, `-shm` and `.upgrade.lock`) belong to you, and the web server cannot write to them ("attempt to write a readonly database"). Idno changes no file's permissions: give them to the web server's user (or make them writable for it), for example `chown -R www-data data/`, and run console commands on the site, and cron or queue workers, as that user (`sudo -u www-data php bin/console ...`).
* **Backups.** Use `sqlite3 data/idno.sqlite ".backup backup.sqlite"`, or copy the file together with its `-wal` and `-shm` files while no request is writing.

Note that `database = "Sqlite3"`, which older instructions gave, does not mean SQLite: Idno connects to MySQL with it.

### Where uploaded files go

Uploaded files are stored in the folder `uploadpath` names, which the web server must be able to write to, or in Amazon S3: set `uploadpath` to `s3://<bucket>/<prefix>/` (with `aws_region`, and `aws_key` and `aws_secret` unless the server has credentials of its own); no plugin is needed. A third-party plugin's own `filesystem` class named in the configuration still works, but the setting is deprecated.

**The web server must never serve the upload folder itself.** Idno hands out each file through its own file page, which applies the access rules of the post the file belongs to; a file fetched straight from the folder skips them (a picture of a private post, the administrators-only site archive). The folder `Uploads/` that the installer uses is inside Idno's folder, so it is inside the document root:

* **Apache**: the bundled `.htaccess` refuses `Uploads/` (`RewriteRule ^(...|configuration|Uploads|...)(/|$) - [F,NC]`), and `Uploads/.htaccess` (`Require all denied`) refuses everything in it as well. Both need `.htaccess` files to be allowed (`AllowOverride All`).
* **nginx**: use `warmup/webserver-configs/nginx.conf`, or add `location ^~ /Uploads/ { deny all; }` and `uploads` to the case-insensitive (`~*`) location that refuses the private folders, before the static-file and `.php` locations.
* **Caddy**: use `docker/caddy/Caddyfile`, whose `@denied` matcher names `uploads` (case-insensitively).
* **An upload folder of your own inside the document root** needs the same rule with its name, or move it outside the document root.

The web installer checks this on its first page by asking for `Uploads/readme.txt`, and will not go on while the server hands it out. To check a running site, open `https://your.domain/Uploads/readme.txt`: you must get `403 Forbidden` (or `404`), never the file's text.

## After installing

* **Site configuration** (in the menu, once you are signed in) has the site's name and description, who can sign up, plugins, and email.
* **Background tasks** are needed to federate with Mastodon and the rest of the Fediverse. See [the asynchronous event queue](advanced.md).
* **If you installed from a terminal**, the files it created belong to the user who ran the commands. The web server must be able to read `configuration/config.ini` and to write to the upload folder and, for SQLite, to the database's folder (for example `chown -R www-data configuration data Uploads`). Run later console commands as the web server's user.
