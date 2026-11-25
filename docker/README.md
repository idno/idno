This is a way to run Idno using Docker and Tailscale for local development.

Copy docker/sample.env to .env and adjust as appropriate

Run ./bin/init.sh

Run ./bin/start.sh

Visit https://known.YOUR-TAILSCALE-TAILNET/

The installer opens. On "Where should Idno keep your site?" choose "A MySQL or MariaDB database" and enter:

Database name: known (or whatever you set in .env)
Database user: known (or whatever you set in .env)
Database password: whatever you set in .env
Database server: db

(Or choose "A file on this server" to use SQLite, and no database container.) Saving creates the
tables; the last step creates your administrator account. From a shell in the idno container,
`php bin/console idno:install` does the same.

The 'configuration', 'data', 'Uploads' and 'var' directories are wide open so the webserver
can write to them because Docker user/permission management is a headache.

The image runs PHP 8.4, which Idno requires. It sets opcache.validate_timestamps=0, so
restart the idno container after changing any PHP file (see docs/install/upgrade.md).

Jim Winstead <jimw@trainedmonkey.com>
June 3, 2024
