# Logs and diagnostics

This page shows you where Idno writes what it is doing, how to get more detail when something goes wrong, and how to use the Diagnostics page.

## The log

Idno writes its messages to PHP's error log. Where that is depends on your server:

- With Apache and `mod_php`, it is usually Apache's error log, such as `/var/log/apache2/error.log`.
- With PHP-FPM, it is PHP-FPM's log, or the file named by PHP's `error_log` setting.
- For the [background worker](advanced.md) run by systemd, it is the service's output: `journalctl -u idno-queue`.

Each of Idno's lines starts with `Idno`, the site's host name and the kind of message, so they are easy to pick out:

```
Idno (example.com): error - Something went wrong
```

When a page fails, the visitor sees a short error page, and the details (the message, the file and line, and the address of the page) go to the log.

## Getting more detail

The `loglevel` setting in `configuration/config.ini` says how much Idno logs:

| `loglevel` | What is logged |
|---|---|
| `0` | Nothing |
| `1` | Errors |
| `2` | Errors and warnings |
| `3` | Errors, warnings, notices and information |
| `4` | All of that, and debugging messages, each with the file and line it came from |
| `5` (the default) | Everything, without the file and line |

A busy site logs a lot at the default level. `2` is a good choice for a site that is working well. When you are chasing a problem, set it to `4`:

```ini
loglevel = 4
```

A few more settings help when you are tracking something down. They are described in the [configuration reference](config.md#logging-and-debugging):

- `debug = true` shows the full error, and usually the stack trace, on error pages. **Every visitor sees them**, so only use it on a test site, or for a few minutes.
- `forward_trace = true` logs where each redirect came from.
- `oops_notify[] = "you@example.com"` mails you whenever a page fails.

## The Diagnostics page

Administrators can open the Diagnostics page at `/admin/diagnostics/`. It runs a few basic checks and says what it finds:

- whether this copy of Idno is behind the latest version on GitHub, and whether it runs from a git checkout rather than a package,
- whether the site is served over HTTPS,
- whether the PHP version and the PHP extensions are right,
- whether the database holds exactly one record of settings,
- whether the upload folder is set and can be written to.

**Generate detailed report** adds a full report: the checks, the running configuration, the session, and PHP's server variables. Secrets in the configuration, such as passwords and keys, are replaced with `** REDACTED **` (see [Keeping secrets safe](config.md#keeping-secrets-safe)). The server variables are not redacted, and include every environment variable, so a password set as an `IDNO_` variable appears there. Read a report before you share it, and never post one in public.

!!! note "The Twenty26 theme"
    The Diagnostics page isn't in the Twenty26 theme's administration menu: open its address directly. Its **Generate detailed report** button relies on scripts that Twenty26 doesn't load, so to get the full report, switch to another theme for a moment.

## Captured logs

If you set `capture_logs = true`, the administration pages gain a Logs page at `/admin/logs/`. It shows the file `<host name>.log` in the server's temporary folder (PHP's `upload_tmp_dir`, or else the system's temporary folder, usually `/tmp/`), for example `/tmp/example.com.log`. Idno doesn't write that file itself: point PHP's `error_log` setting at it to use the page. Logs hold sensitive details, so think before turning this on.

## Asking for help

When you report a problem, include:

- what you did, what you expected, and what happened instead,
- the lines from the log around the time it happened,
- your Idno version (`php idno.php version`, or the About page at `/admin/about/`), PHP version and database.

Remove passwords, keys and email addresses first.

## Where next

- [Configuration reference](config.md)
- [Running background services](advanced.md)
- [Upgrading Idno](upgrade.md)
