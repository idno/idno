# Command Line Installer

Idno allows you to install Idno via the command line, this can be handy for scripted installs, using non-MySQL database backends, or when installing over a terminal connection to a remote server.

# The Basics

Go in to ```/warmup/CLI```, and you'll see ```CLIInstaller.php```.

To install a brand new Idno using an interactive install simply run 

```
./CLIInstaller.php install
```

The installer asks for the site name, database type (default `MySQL`), database name, user, password, host and port, and the upload path. It makes the same checks as the web installer: the upload folder is created if it can be and tested by saving a file in it, the database is connected to, the MySQL schema is loaded into a database that has none (this is skipped for MongoDB), `.htaccess` is created if there is none, and `configuration/config.ini` is written. If anything is wrong it says what, changes nothing, and exits with status 1. It never replaces a configuration file, and stops with an error if Idno is already installed.

The host can carry the port (`db.example.com:3307`), or the port can be given on its own; leave it empty for 3306. A socket path is not accepted: use `localhost` for the server's default socket.

# The Manifest

A manifest is used to pass configuration options to the CLI installer so that you won't be prompted for them. This is useful for scripting installs.

First, generate a manifest template:

```
./CLIInstaller.php generate-manifest /path/to/manifest
```

Next, edit that file and fill in the appropriate options. The manifest is an INI file with the keys `site_title`, `database`, `mysql_name`, `mysql_user`, `mysql_pass`, `mysql_host`, `mysql_port` and `upload_path`. Put a value that has anything but letters and digits in it in quotes.

Now, you are able to pass these options to the installer:

```
./CLIInstaller.php install config.ini /path/to/manifest
```

You'll also notice I'm specifying the ```config.ini``` ini file to write to. It is written in the ```configuration``` directory, and you can write this config to other files as necessary, which is handy if you're using per-domain configuration.

# Other options

The CLI installer has a few other options that might be useful:

## Checking requirements

If you just want to check that your server has the necessary requirements to run Idno, you can execute:

```
./CLIInstaller.php check-requirements
```

## Generate config from manifest

If you want to generate a config file, but without going through the full install (useful if you want to generate a new domain config in a script), you can use the following command to do so

```
./CLIInstaller.php makeconfig /path/to/manifest 
```

This will output the new config to stdOut. You can save it to an ini file as appropriate!