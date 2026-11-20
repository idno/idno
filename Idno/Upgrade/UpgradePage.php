<?php

declare(strict_types=1);

namespace Idno\Upgrade;

use Symfony\Component\HttpFoundation\Request;

/**
 * The page a browser gets while the schema gate is closed (UpgradeGateListener), and the plain-text
 * answer every other request gets. The page is one self-contained document: inline style and script
 * (each carrying the response's CSP nonce), no external request of any kind (no font, image or
 * script from anywhere), no legacy template. It reads without JavaScript (a <noscript> form posts
 * the step URL with `?html=1`), has one h1, a `role="status"` live region for the progress, keeps
 * its contrast in light and dark mode (prefers-color-scheme) and fits a 320 px wide screen.
 *
 * With JavaScript the page POSTs the step URL (UpgradeController) again and again, showing the
 * progress, until the status is `current`, then reloads; on `error`, `too_old` or `unsupported` it
 * stops and shows the message; on a network failure, a 503 answer or an answer that is not JSON it
 * waits and tries again, waiting longer each time (2 s doubling to 60 s, or the answer's
 * Retry-After when that is longer, at most 60 s); after MAX_ANSWERS_NOT_JSON answers in a row that
 * are not JSON (a PHP error page) it stops with PublicMessage::stopped(); a `working` answer's
 * message (the rename waiting for the 1.x tables) is shown once NOTICE_AFTER answers in a row have
 * carried it; it never sends more than one request a second.
 *
 * Everything that comes from the database (the copier's error, the progress) or the request (the
 * base path) is escaped: HTML-escaped in the markup, JSON-encoded with every HTML-significant
 * character escaped in the script; the script writes text with textContent only.
 *
 * A static helper, not a container service.
 */
final class UpgradePage
{
    public const STEP_PATH = '/_upgrade/step';

    /** The page's script stops (PublicMessage::stopped()) after this many answers in a row that are not the upgrade's JSON (a PHP error page). */
    public const MAX_ANSWERS_NOT_JSON = 5;

    /** The page shows a step's notice (the rename waiting for the 1.x tables) once this many answers in a row have carried it. */
    public const NOTICE_AFTER = 3;

    /** The collections of a 1.x database, as the page names them. */
    private const COLLECTIONS = [
        'entities' => 'Posts, people and other content',
        'reader' => 'Feed reader',
        'site' => 'Site records',
        'config' => 'Settings',
    ];

    /**
     * The URL the page posts each step to: the step path under the site's base path (the
     * subdirectory the site is in, from the request; '' at the root).
     */
    public static function stepUrl(string $basePath): string
    {
        return rtrim($basePath, '/') . self::STEP_PATH;
    }

    /**
     * Whether the router will match this request to the step route (UpgradeController): a POST,
     * both as sent and as Symfony reads it (an X-HTTP-Method-Override header changes the latter, and
     * would send the request past the route to the legacy catch-all), to exactly the step path.
     * An override Symfony refuses is no step.
     */
    public static function isStepRequest(Request $request): bool
    {
        if ($request->getRealMethod() !== 'POST' || $request->getPathInfo() !== self::STEP_PATH) {
            return false;
        }
        try {
            return $request->getMethod() === 'POST';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The request's base path, or '' when it is not a plain path: one that starts with `//` or holds a
     * backslash or a control character would make the redirect or the page's URLs point elsewhere.
     */
    public static function basePath(Request $request): string
    {
        $base = $request->getBasePath();

        return str_starts_with($base, '//') || str_contains($base, '\\') || preg_match('/[\x00-\x1f\x7f]/', $base) === 1 ? '' : $base;
    }

    /**
     * The Content-Security-Policy of the page: nothing but its own inline script and style (by
     * nonce), its requests to its own origin (the steps) and its form's post to it.
     */
    public static function contentSecurityPolicy(string $nonce): string
    {
        return "default-src 'none'; script-src 'nonce-" . $nonce . "'; style-src 'nonce-" . $nonce . "'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";
    }

    /**
     * The answer to a request that is not a browser's page load.
     */
    public static function text(SchemaStatus $status, ?PublicMessage $refusal = null): string
    {
        $message = $status->refusesTheUpgrade() ? PublicMessage::forStatus($status, $refusal) : null;

        return $message !== null ? 'Idno cannot start: ' . $message->text . "\n" : "Idno is upgrading its database.\n";
    }

    /**
     * @param string      $nonce   the response's CSP nonce (base64)
     * @param PublicMessage|null $refusal for UnsupportedServer: what is required and what was found
     */
    public static function html(SchemaStatus $status, ?UpgradeProgress $progress, string $basePath, string $nonce, ?PublicMessage $refusal = null): string
    {
        $runs = $status->advancesBySteps();
        $refused = $status->refusesTheUpgrade() ? self::paragraph((string) PublicMessage::forStatus($status, $refusal)?->text) : '';
        $body = match ($status) {
            SchemaStatus::Empty => self::heading('Idno is setting up its database')
                . self::paragraph('Idno is creating the tables it needs in its database. This takes a few seconds, and this page reloads by itself when it is done.'),
            SchemaStatus::PendingMigrations => self::heading('Idno is updating its database')
                . self::paragraph('This version of Idno needs some changes to its database, and is making them now. This takes a few seconds, and this page reloads by itself when it is done.'),
            SchemaStatus::Legacy => self::heading('Idno is upgrading its database')
                . self::paragraph('Your site\'s content is being copied into the new database tables this version of Idno uses. On a large site this can take several minutes. This page shows how far it has got, and reloads by itself when it is done.')
                . self::paragraph('You can close this page and open it again later: the upgrade carries on from where it stopped. It only moves on while this page is open (or while php bin/console idno:upgrade runs on the server).')
                . '<p class="warning"><strong>Before you go on:</strong> ' . self::escape('stop any Idno processes that were started before you replaced the files, such as a queue worker or a cron service. Anything they write while the upgrade runs is not carried over.') . '</p>'
                . self::paragraph('Nothing is deleted. The old tables are kept, renamed with the prefix legacy_, and nothing in them is changed.')
                . self::paragraph('If the upgrade stops, this page says why. The server\'s error log has the details, and you can also run php bin/console idno:upgrade on the server to see what happens.')
                . self::progressList($progress),
            SchemaStatus::LegacyTooOld => self::heading('This site\'s database is too old to upgrade') . $refused,
            SchemaStatus::RenamedWithoutRecord => self::heading('This site\'s old tables need attention') . $refused,
            SchemaStatus::OneXBesideCompletedUpgrade => self::heading('This database holds two copies of the site') . $refused,
            SchemaStatus::MongoRetired => self::heading('This site\'s database is MongoDB, which Idno no longer runs on') . $refused
                . self::paragraph('The site\'s data is not changed. The site\'s owner can move it to MySQL, MariaDB or SQLite by running php bin/console idno:import:mongo on the server.'),
            SchemaStatus::UnsupportedServer => self::heading('This database server is too old for Idno')
                . $refused
                . self::paragraph('Upgrade the database server, or move the database to one that is new enough, then load this page again. Nothing has been changed.'),
            // The gate is open for these: the page is never served for them.
            SchemaStatus::Current, SchemaStatus::Unreachable, SchemaStatus::NotApplicable => self::heading('Idno is upgrading its database'),
        };
        if ($runs) {
            $body .= '<p id="upgrade-status" role="status" aria-live="polite">' . self::escape(self::initialStatus($progress)) . '</p>'
                . self::stoppedBox($progress?->error)
                . '<noscript><p>' . self::escape('Your browser is not running JavaScript, so the upgrade does not move on by itself. Press the button to do the next part of it, and again until your site appears.') . '</p>'
                . '<form method="post" action="' . self::escape(self::stepUrl($basePath) . '?html=1') . '"><button type="submit">Continue the upgrade</button></form></noscript>';
        }

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . self::escape($runs ? 'Upgrading Idno' : 'Idno cannot start') . '</title>'
            . '<style nonce="' . self::escape($nonce) . '">' . self::STYLE . '</style>'
            . '</head><body><main>' . $body . '</main>'
            . ($runs ? '<script nonce="' . self::escape($nonce) . '">' . self::script($basePath) . '</script>' : '')
            . '</body></html>' . "\n";
    }

    private const STYLE = <<<'CSS'
:root{color-scheme:light dark;--bg:#ffffff;--fg:#1b1b1b;--muted:#4a4a4a;--line:#c8c8c8;--warn-bg:#fff4d6;--warn-fg:#3d2c00;--err-bg:#fde8e8;--err-fg:#6b0f0f;--button:#1f4fa3;--button-fg:#ffffff}
@media (prefers-color-scheme: dark){:root{--bg:#141414;--fg:#ececec;--muted:#c4c4c4;--line:#4a4a4a;--warn-bg:#3a2e0c;--warn-fg:#ffe7a6;--err-bg:#3d1414;--err-fg:#ffd2d2;--button:#8fb4ff;--button-fg:#0d1a33}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:1rem/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:40rem;margin:0 auto;padding:2rem 1rem}
h1{font-size:1.5rem;line-height:1.25;margin:0 0 1rem}
h2{font-size:1.125rem;margin:0 0 .5rem}
p{margin:0 0 1rem}
.warning{background:var(--warn-bg);color:var(--warn-fg);padding:.75rem 1rem;border-radius:.25rem}
.stopped{background:var(--err-bg);color:var(--err-fg);padding:.75rem 1rem;border-radius:.25rem;margin:0 0 1rem;overflow-wrap:anywhere}
.stopped[hidden]{display:none}
ul{list-style:none;margin:0 0 1rem;padding:0;border-top:1px solid var(--line)}
li{padding:.5rem 0;border-bottom:1px solid var(--line);display:flex;flex-wrap:wrap;justify-content:space-between;gap:.25rem 1rem}
.count{color:var(--muted)}
#upgrade-status{font-weight:600}
button{font:inherit;padding:.5rem 1rem;border:0;border-radius:.25rem;background:var(--button);color:var(--button-fg);cursor:pointer}
button:focus-visible{outline:3px solid var(--fg);outline-offset:2px}
CSS;

    private static function script(string $basePath): string
    {
        $url = json_encode(self::stepUrl($basePath), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $names = json_encode(self::COLLECTIONS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $stoppedText = json_encode(PublicMessage::stopped()->text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $maxGarbled = self::MAX_ANSWERS_NOT_JSON;
        $noticeAfter = self::NOTICE_AFTER;

        return <<<JS
(function () {
  'use strict';
  var url = {$url};
  var names = {$names};
  var status = document.getElementById('upgrade-status');
  var stopped = document.getElementById('upgrade-stopped');
  var maxGarbled = {$maxGarbled};
  var noticeAfter = {$noticeAfter};
  var stoppedText = {$stoppedText};
  var failures = 0;
  var garbled = 0;
  var notices = 0;
  function say(text) { status.textContent = text; }
  function render(data) {
    var list = document.getElementById('upgrade-progress');
    if (!list || !data.collections) { return; }
    Object.keys(data.collections).forEach(function (name) {
      var counts = data.collections[name];
      var item = list.querySelector('[data-collection="' + name.replace(/[^a-z_]/g, '') + '"]');
      if (!item || !counts) { return; }
      item.querySelector('.count').textContent = describe(counts);
    });
  }
  // The same words as UpgradeProgress::countText().
  function describe(counts) {
    var text = (counts.copied + counts.rejected) + ' of ' + counts.source + ' copied';
    if (counts.rejected > 0) { text += ', ' + counts.rejected + ' not carried over'; }
    return text;
  }
  function stop(message) {
    say('The upgrade has stopped.');
    stopped.querySelector('p').textContent = message;
    stopped.hidden = false;
  }
  function later(ms) { window.setTimeout(step, ms); }
  // 2 s, doubling to 60 s, for each failure in a row.
  function backoff() {
    failures += 1;
    return Math.min(60000, 2000 * Math.pow(2, failures - 1));
  }
  // The server's Retry-After (seconds, at most 60), in milliseconds; 0 without one.
  function retryAfter(response) {
    var seconds = parseInt(response.headers.get('Retry-After') || '', 10);
    return isFinite(seconds) && seconds > 0 ? Math.min(60, seconds) * 1000 : 0;
  }
  function retry(text, wait) {
    say(text + ' Trying again in ' + Math.round(wait / 1000) + ' seconds…');
    later(wait);
  }
  function step() {
    var started = Date.now();
    window.fetch(url, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (response) {
        return response.json().then(function (data) { return { response: response, data: data }; }, function () { return { response: response, data: null }; });
      })
      .then(function (answer) {
        var data = answer.data;
        if (!data || typeof data.status !== 'string') {
          // An answer that is not the upgrade's (a PHP error page): a few are retried, then it stops.
          garbled += 1;
          if (garbled >= maxGarbled) { stop(stoppedText); return; }
          retry('The server did not answer as expected.', Math.max(backoff(), retryAfter(answer.response)));
          return;
        }
        garbled = 0;
        render(data);
        if (data.status === 'current') {
          say('Done. Loading your site…');
          window.location.reload();
          return;
        }
        if (data.status === 'working') {
          stopped.hidden = true;
          var notice = typeof data.message === 'string' && data.message !== '' ? data.message : '';
          notices = notice !== '' ? notices + 1 : 0;
          if (answer.response.status === 503) {
            retry(notice !== '' ? notice : 'The server is busy.', Math.max(backoff(), retryAfter(answer.response)));
            return;
          }
          failures = 0;
          say(notices >= noticeAfter ? notice : (data.phase === 'verifying' ? 'Checking the copy against the old tables…' : 'Working…'));
          later(Math.max(0, 1000 - (Date.now() - started)));
          return;
        }
        failures = 0;
        stop(typeof data.message === 'string' && data.message !== '' ? data.message : 'The upgrade stopped.');
      })
      .catch(function () {
        retry('The server did not answer.', backoff());
      });
  }
  say('Working…');
  step();
})();
JS;
    }

    private static function initialStatus(?UpgradeProgress $progress): string
    {
        if ($progress?->error !== null) {
            return 'The upgrade has stopped.';
        }

        return 'Waiting to start. If nothing happens, use the button below.';
    }

    private static function stoppedBox(?string $error): string
    {
        return '<div id="upgrade-stopped" class="stopped"' . ($error === null ? ' hidden' : '') . '><h2>The upgrade stopped</h2><p>' . self::escape($error ?? '') . '</p></div>';
    }

    private static function progressList(?UpgradeProgress $progress): string
    {
        $items = '';
        foreach (self::COLLECTIONS as $collection => $name) {
            $counts = $progress?->collections[$collection] ?? ['source' => 0, 'copied' => 0, 'rejected' => 0];
            $text = UpgradeProgress::countText($counts);
            $items .= '<li data-collection="' . self::escape($collection) . '"><span>' . self::escape($name) . '</span><span class="count">' . self::escape($text) . '</span></li>';
        }

        return '<h2>Progress</h2><ul id="upgrade-progress">' . $items . '</ul>';
    }

    private static function heading(string $text): string
    {
        return '<h1>' . self::escape($text) . '</h1>';
    }

    private static function paragraph(string $text): string
    {
        return '<p>' . self::escape($text) . '</p>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
