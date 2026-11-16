This is a way to run Idno using Docker and Tailscale for local development.

Copy docker/sample.env to .env and adjust as appropriate

Run ./bin/init.sh

Run ./bin/start.sh

Visit https://known.YOUR-TAILSCALE-TAILNET/

You will need to enter the following for the database:

Database name: known (or whatever you set in .env)
User name: known (or whatever you set in .env)
Password: whatever you set in .env
Server: db

The installer does not create the database tables: the first page load after it has written the
configuration sets them up (or run `php bin/console idno:upgrade` in the idno container, from the
Idno directory).

The 'configuration', 'Uploads' and 'var' directories are wide open so the webserver
can write to them because Docker user/permission management is a headache.

The image runs PHP 8.4, which Idno requires. It sets opcache.validate_timestamps=0, so
restart the idno container after changing any PHP file (see docs/install/upgrade.md).

Jim Winstead <jimw@trainedmonkey.com>
June 3, 2024
