<?php

declare(strict_types=1);

namespace Idno\Install;

/**
 * The pages of the installation: each is one self-contained document (inline style and script
 * carrying the response's nonce, no image, no font and no request to anywhere else), readable at
 * 320 pixels, in light and dark, with a label for every field and its error beside it. Every value
 * that comes from a request, the server or a file is escaped here; a password is never printed.
 */
final class InstallPage
{
    public const STEPS = 4;

    /**
     * @param list<CheckResult> $checks
     * @param string            $rewrite What the browser found when it asked for a page address: 'ok', 'fail' or '' (not checked)
     * @param string            $uploads What the browser found when it asked for a file in the upload folder: 'refused', 'served' or '' (not checked)
     */
    public static function checks(array $checks, string $token, string $nonce, string $rewrite = '', string $notice = '', string $uploads = ''): string
    {
        $failed = ServerChecks::blocking($checks) || $rewrite === 'fail' || $uploads === 'served';
        $problems = count(array_filter($checks, static fn (CheckResult $c): bool => $c->state === CheckResult::FAIL)) + ($rewrite === 'fail' ? 1 : 0) + ($uploads === 'served' ? 1 : 0);
        $body = self::steps(1) . '<h1>Checking your server</h1>';
        if ($notice !== '') {
            $body .= '<p class="notice" role="alert">' . self::e($notice) . '</p>';
        }
        $body .= $failed
            ? '<p>Idno needs a few things from this server before it can be set up. ' . ($problems === 1 ? 'One needs' : self::e((string) $problems) . ' need') . ' fixing.</p>'
            : '<p>This server can run Idno.</p>';
        $body .= '<ul class="checks">';
        foreach ($checks as $check) {
            $body .= self::check($check);
        }
        $body .= '<li id="check-rewrite"' . ($rewrite === 'fail' ? '' : ' hidden') . '><span class="name">Page addresses</span> <span class="state fail">Needs fixing</span>'
            . '<div class="fix"><p>The web server is not passing page addresses to Idno, so only this page would work.</p>'
            . '<p>On Apache this usually means .htaccess files are switched off: ask your host to allow them (the setting is called AllowOverride All), and check that the file .htaccess was uploaded with the rest of Idno (it is hidden in some file managers). On nginx or Caddy, use the sample configuration in the folder warmup/webserver-configs.</p></div></li>';
        $body .= '<li id="check-uploads"' . ($uploads === 'served' ? '' : ' hidden') . '><span class="name">Uploaded files kept private</span> <span class="state fail">Needs fixing</span>'
            . '<div class="fix"><p>The web server hands out the files in the folder Uploads to anyone who asks for them by their address, so a picture attached to a private post, or a site archive, could be read without signing in.</p>'
            . '<p>On Apache, check that the files .htaccess and Uploads/.htaccess were uploaded with the rest of Idno and that .htaccess files are allowed (AllowOverride All). On nginx, add this inside the server block, before the other locations:</p>'
            . '<pre>' . self::e('location ^~ /Uploads/ { deny all; }') . '</pre>'
            . '<p>On Caddy, use the sample configuration in docker/caddy/Caddyfile. Then check again.</p></div></li>';
        $body .= '</ul>';
        $body .= '<form method="post" action="./" id="check-form">' . self::hidden(['step' => 'check', 'token' => $token, 'rewrite' => $rewrite, 'uploads' => $uploads])
            . '<div class="actions">'
            . ($failed
                ? '<a class="button" href="./">Check again</a>'
                : '<button type="submit">Continue</button> <a href="./">Check again</a>')
            . '</div></form>';
        $script = <<<'JS'
(function () {
  'use strict';
  var field = document.querySelector('input[name="rewrite"]');
  var item = document.getElementById('check-rewrite');
  var uploadsField = document.querySelector('input[name="uploads"]');
  var uploadsItem = document.getElementById('check-uploads');
  if (!field || !window.fetch) { return; }
  function stop(shown) {
    if (shown.hidden) {
      shown.hidden = false;
      var button = document.querySelector('#check-form button');
      if (button) { button.disabled = true; }
    }
  }
  fetch('./js/canary.js', {cache: 'no-store', credentials: 'omit'}).then(function (answer) {
    return answer.ok;
  }, function () {
    return false;
  }).then(function (ok) {
    field.value = ok ? 'ok' : 'fail';
    if (!ok) { stop(item); }
  });
  // A file of the upload folder must never be handed out: only an answer that holds the file's own marker counts as
  // served (a missing file is answered by Idno's own page).
  fetch('./%PROBE%', {cache: 'no-store', credentials: 'omit'}).then(function (answer) {
    return answer.ok ? answer.text() : '';
  }, function () {
    return '';
  }).then(function (text) {
    var served = text.indexOf('%MARKER%') !== -1;
    uploadsField.value = served ? 'served' : 'refused';
    if (served) { stop(uploadsItem); }
  });
})();
JS;
        $script = strtr($script, ['%PROBE%' => UploadFolder::PROBE, '%MARKER%' => UploadFolder::PROBE_MARKER]);

        return self::document('Checking your server', $body, $nonce, $script);
    }

    /**
     * @param array{title: string, domain: string} $values
     * @param array<string, string>                $errors field => message
     */
    public static function site(array $values, array $errors, string $token, string $nonce): string
    {
        $body = self::steps(2) . '<h1>Your site</h1>' . self::summary($errors)
            . '<form method="post" action="./">' . self::hidden(['step' => 'site', 'token' => $token])
            . self::field('title', 'Site name', 'Shown at the top of every page. You can change it later.', $values['title'], $errors, ['maxlength' => '100', 'autocomplete' => 'off'])
            . self::field('domain', 'Domain', 'The domain people will use to reach this site, without https:// in front. Idno read it from this page\'s address: check that it is right.', $values['domain'], $errors, ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'required' => ''])
            . '<div class="actions"><button type="submit">Continue</button> <a href="./">Back</a></div></form>';

        return self::document('Your site', $body, $nonce);
    }

    /**
     * @param array{title: string, domain: string, database: string, mysql_name: string, mysql_user: string, mysql_host: string} $values
     * @param array<string, string>                                                                                              $errors
     * @param array{sqlite: bool, mysql: bool}                                                                                   $available Which choices this server can offer
     * @param bool                                                                                                               $existing  Whether the chosen database already holds an Idno site (asks for the confirmation)
     */
    public static function database(array $values, array $errors, array $available, bool $existing, string $token, string $nonce): string
    {
        $sqlite = $values['database'] === 'sqlite';
        $body = self::steps(3) . '<h1>Where should Idno keep your site?</h1>' . self::summary($errors)
            . '<form method="post" action="./">' . self::hidden(['step' => 'database', 'token' => $token, 'title' => $values['title']] + ($existing ? [] : ['domain' => $values['domain']]))
            . '<fieldset><legend>Everything you post is kept in a database. Uploaded pictures and files are kept in the folder Uploads.</legend>';
        $body .= '<label class="choice"><input type="radio" name="database" value="sqlite"' . ($sqlite ? ' checked' : '') . ($available['sqlite'] ? '' : ' disabled') . '>'
            . '<span><strong>A file on this server (SQLite)</strong><span>The simple choice: nothing else to set up. Right for a personal site or a small group.'
            . ($available['sqlite'] ? '' : ' Not available here: the folder data cannot be written to, or PHP\'s SQLite extension is missing.') . '</span></span></label>';
        $body .= '<label class="choice"><input type="radio" name="database" value="mysql"' . ($sqlite ? '' : ' checked') . ($available['mysql'] ? '' : ' disabled') . '>'
            . '<span><strong>A MySQL or MariaDB database</strong><span>For a busy site, or if your host has already given you a database. You will need its name, user and password.'
            . ($available['mysql'] ? '' : ' Not available here: PHP\'s MySQL extension (pdo_mysql) is missing.') . '</span></span></label></fieldset>';
        $body .= '<div id="mysql-fields" class="needs-mysql' . ($sqlite ? ' off' : '') . '">'
            . '<p>Create the database first, in your hosting control panel (often called "MySQL databases"), with a user that has all privileges on it.</p>'
            . self::field('mysql_name', 'Database name', '', $values['mysql_name'], $errors, ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'])
            . self::field('mysql_user', 'Database user', '', $values['mysql_user'], $errors, ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'])
            . self::field('mysql_pass', 'Database password', '', '', $errors, ['type' => 'password', 'autocomplete' => 'off'])
            . self::field('mysql_host', 'Database server', 'Almost always localhost. Your hosting control panel says if it is something else.', $values['mysql_host'] === '' ? 'localhost' : $values['mysql_host'], $errors, ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'])
            . '</div>';
        if ($existing) {
            // Adopting a site writes its address: it is shown here, beside the code from the server, for the person to confirm.
            $body .= self::field('install_code', 'Code from the server', 'The code in the file ' . InstallCode::relativePath() . ' in Idno\'s folder. It is not shown anywhere else.', '', $errors, ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'required' => ''])
                . self::field('domain', 'The site\'s address', 'The domain people use to reach the site, without https:// in front. It is written to the configuration: check that it is right.', $values['domain'], $errors, ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'required' => ''])
                . '<label class="check"><input type="checkbox" name="use_existing" value="1"> Use the Idno site that is already in this database. Nothing in it is replaced; if it is from an older version of Idno it is upgraded, and its own accounts sign in as before.</label>';
        }
        $body .= '<div class="actions"><button type="submit">Save and set up the site</button> <button type="submit" name="back" value="1" class="link" formnovalidate>Back</button></div></form>';
        $script = <<<'JS'
(function () {
  'use strict';
  var fields = document.getElementById('mysql-fields');
  var radios = document.querySelectorAll('input[name="database"]');
  function show() {
    var mysql = document.querySelector('input[name="database"][value="mysql"]').checked;
    fields.classList.toggle('off', !mysql);
  }
  for (var i = 0; i < radios.length; i++) { radios[i].addEventListener('change', show); }
  show();
})();
JS;

        return self::document('Where should Idno keep your site?', $body, $nonce, $script);
    }

    /**
     * @param array{name: string, handle: string, email: string} $values
     * @param array<string, string>                              $errors
     * @param string                                             $siteUrl The site's address, for the hint of the username
     */
    public static function account(array $values, array $errors, string $field, string $action, string $siteUrl, string $nonce): string
    {
        $body = self::steps(4) . '<h1>Create your account</h1><p>This is the administrator account: the one that runs the site.</p>' . self::summary($errors)
            . '<form method="post" action="' . self::e($action) . '">' . self::hidden(['token' => $field])
            . self::field('name', 'Your name', '', $values['name'], $errors, ['autocomplete' => 'name', 'maxlength' => '100'])
            . self::field('handle', 'Username', 'Letters, numbers and underscores. It becomes your profile address: ' . $siteUrl . 'profile/' . ($values['handle'] === '' ? 'username' : strtolower($values['handle'])), $values['handle'], $errors, ['autocomplete' => 'username', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'maxlength' => '32', 'required' => ''])
            . self::field('email', 'Email address', 'Used to reset your password and as the sender of the site\'s mail. Not shown publicly.', $values['email'], $errors, ['type' => 'email', 'autocomplete' => 'email', 'required' => ''])
            . self::field('password', 'Password', 'At least ' . AdministratorDetails::PASSWORD_LENGTH . ' characters. A few unrelated words work well.', '', $errors, ['type' => 'password', 'autocomplete' => 'new-password', 'required' => '', 'minlength' => (string) AdministratorDetails::PASSWORD_LENGTH])
            . self::field('password2', 'Password again', '', '', $errors, ['type' => 'password', 'autocomplete' => 'new-password', 'required' => ''])
            . '<p id="show-passwords" hidden><label class="check"><input type="checkbox" id="show-passwords-box"> Show passwords</label></p>'
            . '<div class="actions"><button type="submit">Create account</button></div></form>';
        $script = <<<'JS'
(function () {
  'use strict';
  var row = document.getElementById('show-passwords');
  var box = document.getElementById('show-passwords-box');
  row.hidden = false;
  box.addEventListener('change', function () {
    var fields = [document.getElementById('field-password'), document.getElementById('field-password2')];
    for (var i = 0; i < fields.length; i++) { fields[i].type = box.checked ? 'text' : 'password'; }
  });
})();
JS;

        return self::document('Create your account', $body, $nonce, $script);
    }

    /**
     * What everyone but the person installing sees between the settings being saved and the
     * administrator existing.
     */
    public static function pending(string $nonce): string
    {
        return self::document(
            'This site is being set up',
            '<h1>This site is being set up</h1><p>It is not ready yet. Please come back later.</p>'
            . '<p>If you are the person setting it up: go back to the browser you started in and open this address again. If that is not possible, run this on the server, in the folder Idno is in, and open the link it prints:</p>'
            . '<pre>php bin/console idno:setup:link</pre>',
            $nonce
        );
    }

    /**
     * For a site whose configuration file exists and cannot be read: it is installed, and never
     * offered the installer.
     */
    public static function unreadableConfiguration(string $nonce): string
    {
        return self::document(
            'Idno cannot read its settings',
            '<h1>Idno cannot read its settings</h1><p>This site has a configuration file, but the web server is not allowed to read it, or it is empty.</p>'
            . '<p>In your hosting file manager, open the permissions of the file config.ini in the folder configuration and allow the owner and the group to read it (often shown as 640 or 644). Or, from a terminal in the folder Idno is in, with the user your web server runs as in place of www-data:</p>'
            . '<pre>chown www-data configuration/config.ini</pre>',
            $nonce
        );
    }

    public static function contentSecurityPolicy(string $nonce): string
    {
        return "default-src 'none'; style-src 'nonce-" . $nonce . "'; script-src 'nonce-" . $nonce . "'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";
    }

    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function steps(int $step): string
    {
        return '<p class="steps">Set up Idno &middot; Step ' . $step . ' of ' . self::STEPS . '</p>';
    }

    private static function check(CheckResult $check): string
    {
        $label = match ($check->state) {
            CheckResult::OK => 'OK',
            CheckResult::FAIL => 'Needs fixing',
            default => 'Worth knowing',
        };
        $html = '<li><span class="name">' . self::e($check->title) . '</span> <span class="state ' . self::e($check->state) . '">' . $label . '</span>';
        if ($check->state !== CheckResult::OK && $check->meaning !== '') {
            $html .= '<div class="' . ($check->state === CheckResult::FAIL ? 'fix' : 'warning') . '"><p>' . self::e($check->meaning) . '</p>'
                . ($check->fix === '' ? '' : '<p>' . self::e($check->fix) . '</p>')
                . ($check->command === '' ? '' : '<pre>' . self::e($check->command) . '</pre>')
                . '</div>';
        }

        return $html . '</li>';
    }

    /**
     * @param array<string, string> $errors
     */
    private static function summary(array $errors): string
    {
        if ($errors === []) {
            return '';
        }
        $html = '<div class="errors" role="alert"><p>' . (count($errors) === 1 ? 'One thing needs' : 'A few things need') . ' another look:</p><ul>';
        foreach ($errors as $message) {
            $html .= '<li>' . self::e($message) . '</li>';
        }

        return $html . '</ul></div>';
    }

    /**
     * @param array<string, string> $values
     */
    private static function hidden(array $values): string
    {
        $html = '';
        foreach ($values as $name => $value) {
            $html .= '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
        }

        return $html;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $attributes
     */
    private static function field(string $name, string $label, string $hint, string $value, array $errors, array $attributes = []): string
    {
        $id = 'field-' . $name;
        $described = [];
        $html = '<div class="field"><label for="' . $id . '">' . self::e($label) . '</label>';
        if ($hint !== '') {
            $html .= '<p class="hint" id="' . $id . '-hint">' . self::e($hint) . '</p>';
            $described[] = $id . '-hint';
        }
        if (isset($errors[$name])) {
            $html .= '<p class="error" id="' . $id . '-error">' . self::e($errors[$name]) . '</p>';
            $described[] = $id . '-error';
        }
        $attributes += ['type' => 'text'];
        $html .= '<input id="' . $id . '" name="' . self::e($name) . '"';
        if ($attributes['type'] !== 'password') {
            $html .= ' value="' . self::e($value) . '"';
        }
        foreach ($attributes as $attribute => $content) {
            $html .= ' ' . self::e($attribute) . ($content === '' ? '' : '="' . self::e($content) . '"');
        }
        if ($described !== []) {
            $html .= ' aria-describedby="' . implode(' ', $described) . '"';
        }
        if (isset($errors[$name])) {
            $html .= ' aria-invalid="true"';
        }

        return $html . '></div>';
    }

    private static function document(string $title, string $body, string $nonce, string $script = ''): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . self::e($title) . ' &middot; Idno</title>'
            . '<style nonce="' . self::e($nonce) . '">' . self::STYLE . '</style>'
            . '<script nonce="' . self::e($nonce) . '">document.documentElement.className="js";</script>'
            . '</head><body><main>' . $body . '</main>'
            . '<script nonce="' . self::e($nonce) . '">' . self::ONCE . $script . '</script>'
            . '</body></html>' . "\n";
    }

    /** A form is sent once: a second click while the first submission is on its way does nothing. */
    private const ONCE = <<<'JS'
(function () {
  'use strict';
  var forms = document.querySelectorAll('form');
  for (var i = 0; i < forms.length; i++) {
    forms[i].addEventListener('submit', function (event) {
      if (this.getAttribute('data-sent')) { event.preventDefault(); return; }
      this.setAttribute('data-sent', '1');
    });
  }
  window.addEventListener('pageshow', function () {
    for (var j = 0; j < forms.length; j++) { forms[j].removeAttribute('data-sent'); }
  });
})();
JS;

    private const STYLE = <<<'CSS'
:root{color-scheme:light dark;--bg:#ffffff;--fg:#1b1b1b;--muted:#4a4a4a;--line:#c8c8c8;--field:#ffffff;--warn-bg:#fff4d6;--warn-fg:#3d2c00;--err-bg:#fde8e8;--err-fg:#6b0f0f;--ok:#17633a;--button:#1f4fa3;--button-fg:#ffffff}
@media (prefers-color-scheme: dark){:root{--bg:#141414;--fg:#ececec;--muted:#c4c4c4;--line:#5a5a5a;--field:#1d1d1d;--warn-bg:#3a2e0c;--warn-fg:#ffe7a6;--err-bg:#3d1414;--err-fg:#ffd2d2;--ok:#8fd9ad;--button:#8fb4ff;--button-fg:#0d1a33}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:1rem/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:40rem;margin:0 auto;padding:2rem 1rem 4rem}
h1{font-size:1.5rem;line-height:1.25;margin:0 0 1rem}
p{margin:0 0 1rem}
.steps{font-size:.875rem;color:var(--muted);margin:0 0 .25rem}
.checks{list-style:none;margin:0 0 1.5rem;padding:0;border-top:1px solid var(--line)}
.checks li{padding:.7rem 0;border-bottom:1px solid var(--line)}
.checks li[hidden]{display:none}
.name{font-weight:600}
.state{font-weight:600;white-space:nowrap}
.state.ok{color:var(--ok)}
.state.fail{color:var(--err-fg)}
.state.warn{color:var(--warn-fg)}
.fix,.errors{background:var(--err-bg);color:var(--err-fg);padding:.75rem 1rem;border-radius:.25rem;margin:.5rem 0 0;overflow-wrap:anywhere}
.warning,.notice{background:var(--warn-bg);color:var(--warn-fg);padding:.75rem 1rem;border-radius:.25rem;margin:.5rem 0 0;overflow-wrap:anywhere}
.errors,.notice{margin:0 0 1rem}
.fix p:last-child,.warning p:last-child,.errors ul{margin-bottom:0}
.errors ul{padding-left:1.25rem}
code,pre{font:.9rem ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
pre{margin:.5rem 0 1rem;white-space:pre-wrap;overflow-wrap:anywhere;background:rgba(127,127,127,.18);padding:.5rem .6rem;border-radius:.25rem}
.fix pre,.warning pre{margin-bottom:0}
.field{margin:0 0 1rem}
label{display:block;font-weight:600;margin:0 0 .25rem}
.hint{color:var(--muted);font-size:.9rem;margin:0 0 .25rem;overflow-wrap:anywhere}
.error{color:var(--err-fg);font-weight:600;font-size:.9rem;margin:0 0 .25rem}
input[type=text],input[type=email],input[type=password]{font:inherit;width:100%;padding:.55rem .65rem;border:1px solid var(--line);border-radius:.25rem;background:var(--field);color:var(--fg)}
input[aria-invalid=true]{border-color:var(--err-fg);border-width:2px}
fieldset{border:0;padding:0;margin:0 0 1rem;min-width:0}
legend{padding:0;margin:0 0 .75rem}
.choice{display:flex;gap:.75rem;align-items:flex-start;border:1px solid var(--line);border-radius:.4rem;padding:.8rem 1rem;margin:0 0 .6rem;font-weight:400}
.choice input{margin-top:.35rem}
.choice strong{display:block}
.choice span span{color:var(--muted);font-size:.92rem}
.choice:has(input:checked){border-color:var(--button);box-shadow:0 0 0 1px var(--button)}
.check{font-weight:400;display:flex;gap:.6rem;align-items:flex-start;margin:0 0 1rem}
.check input{margin-top:.35rem}
.js .needs-mysql.off{display:none}
.actions{margin-top:1.5rem;display:flex;gap:1rem;flex-wrap:wrap;align-items:center}
button,.button{font:inherit;padding:.6rem 1.1rem;border:0;border-radius:.25rem;background:var(--button);color:var(--button-fg);cursor:pointer;text-decoration:none;display:inline-block}
button[disabled]{opacity:.5;cursor:not-allowed}
button.link{background:none;color:var(--button);padding:0;text-decoration:underline}
a{color:var(--button)}
a:focus-visible,button:focus-visible,input:focus-visible{outline:3px solid var(--fg);outline-offset:2px}
CSS;
}
