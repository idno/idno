# Installing Idno

This page takes you from an empty server to a working Idno site with you as its administrator.

If you want to work on Idno's code rather than run a site, see [Setting up for development](../developers/setup.md) instead.

## Before you begin

You need:

- A server that meets the [system requirements](requirements.md): a web server, PHP 8.3 or later with a few extensions, and MySQL.
- [Composer](https://getcomposer.org/), PHP's package manager, to fetch the libraries Idno uses.
- An empty MySQL database, and a database user that can create and change tables in it. The installer does not create the database for you.
- A domain or subdomain for the site. Idno can also live in a subfolder of a site, such as `example.com/blog/`.

## 1. Get the code

Idno's code lives in its [GitHub repository](https://github.com/idno/idno). Clone it into the folder your web server will serve, then fetch its libraries:

```bash
git clone https://github.com/idno/idno.git /var/www/idno
cd /var/www/idno
composer install
```

`composer install` creates the `vendor/` folder. Without it, every page says the installation is incomplete.

The web server's user (often `www-data`) needs to be able to read every file. It also needs to write to the `configuration/` folder while you install, so that the installer can save the configuration file there. If it can't, the installer shows you what to save by hand.

## 2. Set up the web server

Point your web server's document root at the folder you cloned into. Idno sends every address to `index.php`, so the web server must pass on addresses it has no file for:

- **Apache**: turn on `mod_rewrite` and allow `.htaccess` files (`AllowOverride All`). The installer copies the sample `.htaccess` into place if there is none. On Apache 2.4, see the note in [System requirements](requirements.md#apache).
- **nginx**: start from the sample configuration in `warmup/webserver-configs/nginx.conf`. It sends addresses to `index.php` and closes `configuration/`, `Uploads/` and other files visitors should never see.

Serve the site over HTTPS. Idno works over plain HTTP, but its diagnostics page warns about it, and other sites on the IndieWeb and the Fediverse expect HTTPS.

## 3. Create the database

Create a database and a user for Idno. In the MySQL shell, for example:

```sql
CREATE DATABASE idno CHARACTER SET utf8mb4;
CREATE USER 'idno'@'localhost' IDENTIFIED BY 'a long password';
GRANT ALL PRIVILEGES ON idno.* TO 'idno'@'localhost';
```

Leave the database empty. The installer creates the tables.

## 4. Run the web installer

Open your site's address in a browser. While there is no configuration file, every address sends you to the installer at `/warmup/`.

1. **Requirements.** The installer checks your PHP version, address rewriting and the PHP extensions Idno needs, and tells you if anything is missing.
2. **Settings.** Fill in:
    - **The site's name.** You can change it later.
    - **The database**: its name, the user, the password and the server. Type the server as a name or address (`localhost`, `db.example.com`). If MySQL listens on another port, add it after a colon (`db.example.com:3307`) or type it in the port field. Leave the port empty for 3306. A socket path is not accepted: `localhost` uses the server's default socket.
    - **The upload folder**: where photos and other files are kept. The installer suggests the `Uploads` folder inside the installation, which the sample web server configurations close to visitors. Any other folder must be outside the installation, so that visitors can't download files directly. The installer creates the folder if it can, and checks that it can save a file in it.
3. **Install.** The installer connects to the database, creates the tables, and saves the configuration file as `configuration/config.ini`. If anything is wrong, it says what beside the field and doesn't save a configuration file. It never replaces a configuration file that is already there.

If the web server can't write to `configuration/`, the installer shows you the contents of the file and where to save it. Save it, make sure the web server's user can read it, and continue.

If the database already holds a whole Idno site (one you are moving, for example), the installer keeps its tables as they are. Empty tables left by an earlier attempt are replaced. A table with one of Idno's names that has data in it, but isn't part of a whole site, stops the installation: the installer never deletes data.

## 5. Make your account

Once the configuration file is saved, the installer sends you to make the first account. **The first account on a site is its administrator.** When you make it, Idno also:

- gives the site the name you typed in the installer,
- turns off open registration, so that nobody else can make an account until you allow it,
- uses your email address as the address the site sends mail from.

Until that first account exists, anybody who reaches the site can make it. Finish the installation and make your account straight away.

## 6. After installing

Your site works now. A few things are worth doing next:

- **Set up email**, so that password resets and invitations reach people. See [Email](../admin/email.md).
- **Start the background worker**, especially if you'll use ActivityPub to connect with the Fediverse. See [Running background services](advanced.md).
- **Look through the administration pages** at `/admin/`: the site's name and description, who may register, plugins and themes. See [Administering a site](../admin/index.md).
- **Back up** the database and the upload folder regularly.

## Installing without the web installer

You can also write the configuration yourself. This is how you set up MongoDB, or a server where you'd rather not run the installer in a browser. The [command line installer](cliinstaller.md) does the same checks as the web installer and is often easier.

### MySQL

1. Create the database and user (step 3 above).
2. Load the schema:

    ```bash
    mysql -u idno -p idno < warmup/schemas/mysql/mysql.sql
    ```

3. Create `configuration/config.ini`:

    ```ini
    database = "MySQL"
    dbname = "idno"
    dbuser = "idno"
    dbpass = "a long password"
    dbhost = "localhost"
    filesystem = "local"
    uploadpath = "/var/www/idno/Uploads/"
    ```

    Add `dbport = "3307"` if MySQL isn't on port 3306. The upload folder must exist, and the web server's user must be able to write to it.

4. Open the site and make your account (step 5 above).

Later changes to the database tables are applied automatically the first time Idno connects after an upgrade.

### MongoDB

MongoDB support is deprecated: use MySQL for a new site if you can. MongoDB needs the PHP `mongodb` extension and the `mongodb/mongodb` library, which isn't installed by default:

```bash
composer require mongodb/mongodb
```

If MongoDB accepts connections from this machine without a password, this is all `configuration/config.ini` needs:

```ini
database = "MongoDB"
```

The database is called `idno` unless you set `dbname`, and Idno connects to `mongodb://localhost:27017` unless you set a [connection string](https://www.mongodb.com/docs/manual/reference/connection-string/) in `dbstring`. If MongoDB requires you to sign in, add the user, the password and the database the user is defined in:

```ini
dbuser = "idnouser"
dbpass = "a long password"
dbauthsrc = "admin"
```

The user needs the `readWrite` role on Idno's database. In the MongoDB shell:

```
use admin
db.createUser({user: "idnouser", pwd: "a long password", roles: [{role: "readWrite", db: "idno"}]})
```

With no `filesystem` setting, uploaded files are kept in MongoDB itself. To keep them on disk instead, add `filesystem = "local"` and an `uploadpath`.

### Settings from the environment

Every setting can also come from an environment variable, such as `IDNO_DBPASS` for `dbpass`. This is handy in containers, and keeps passwords out of files. See [Configuration reference](config.md#environment-variables).

## Where next

- [Configuration reference](config.md)
- [Running background services](advanced.md)
- [Logs and diagnostics](debugging.md)
- [Upgrading Idno](upgrade.md)
- [Administering a site](../admin/index.md)
