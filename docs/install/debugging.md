# Log Files

If you're experiencing problems, helpful information can often be found in your apache (or NGinX) PHP logs.

Where these logs live are dependent on your server configuration, but they're often found living on ```/var/log/apache```.

It is helpful if you can include these logs with any bug reports.

To get more detailed logs, you can try adding ``` loglevel = 4 ``` in your config.ini (see [config.ini](config.md) for the log levels).

# Diagnostics

Admin users have access to various diagnostics which may shed light on any issues you may find. Click on "Site Configuration" and then "Diagnostics" (at `/admin/diagnostics/`). Be aware these reports will contain sensitive information, so be careful to whom you disclose them, and never post them publicly on the internet!
