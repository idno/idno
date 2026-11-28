<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Session;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Http\RedirectTarget;
use Idno\Security\LoginAttempts;
use Idno\Security\LoginThrottle;
use Idno\Security\Passwords;
use Idno\Templating\TemplateTranslation;
use Symfony\Component\HttpFoundation\Response;

/**
 * The login page and the logout: the one implementation `SessionController` calls.
 *
 * What it asks the site is the interface `SignIn`, so it is built and tested without the legacy application. The rules it keeps, each also pinned over HTTP
 * by the recorded answers of the sign-in pages:
 * - an unknown name and a wrong password get ONE message and cost the same (`Passwords::spendVerification()`);
 * - an attempt is reserved BEFORE the password is looked at, and a refused one never reaches the password check, even for a right password;
 * - a right password clears the count, then signs the member on (a new session id and form token);
 * - every address the request supplies (the page to go to, the `Referer`) goes through `RedirectTarget::sanitize()`;
 * - the API key is given only to a signed-in member, as `json`, over a secure request;
 * - the logout ends the session and starts another, which holds the "signed out" message (a signed request adds none).
 */
final class SessionPages
{
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly SignIn $signIn,
        private readonly LoginAttempts $attempts,
        private readonly Passwords $passwords,
        private readonly TemplateTranslation $translation,
        private readonly ?\Closure $spendVerification = null, // a seam for a test: what a login for a name nobody has spends (the default is `Passwords::spendVerification()`)
    )
    {
    }

    /**
     * The login form (a signed-in member on a web page is sent on to the page they asked for). A signed-in member who asks for JSON over a secure
     * connection gets their API key in the answer.
     */
    public function login(PageContext $page): Response
    {
        $site = $this->signIn->displayUrl();
        $user = $this->signIn->currentUser();

        // Somehow here but signed in: on to the page asked for (the field is the page's address as base64)
        if ($user !== null && $this->signIn->templateType() === 'default') {
            $raw = $page->text('fwd');
            $fwd = empty($raw) ? $site : $this->signIn->decodeForward($raw);

            return $this->renderer->redirect($page, RedirectTarget::sanitize($fwd, $site, $this->signIn->configuredUrl()));
        }

        $fwd = $this->signIn->decodeForward($page->text('fwd'));
        if ($fwd === $site . 'session/login') {
            $fwd = $site;
        }
        if (empty($fwd)) {
            $fwd = $site;
        }
        $vars = ['fwd' => RedirectTarget::sanitize($fwd, $site, $this->signIn->configuredUrl())];

        // Signed in and still here: an API login, which gets the member's key. Only the JSON answer may carry it: another site cannot read that one, but it can
        // load any answer that is a script (JSONP), and the browser may send the session cookie with it.
        if ($user !== null && $this->signIn->templateType() === 'json' && $this->signIn->isSecure()) {
            $vars['api-token'] = $this->signIn->apiKey($user);
        }

        return $this->renderer->page($page, [
            'body' => $this->renderer->template('account/login', $vars),
            'title' => $this->translation->translate('Sign in'),
        ]);
    }

    /**
     * The login form's POST. Every answer is a forward to the login page (which sends a signed-in member on to the page they asked for), with the
     * message of what happened waiting in the session.
     */
    public function signIn(PageContext $page): Response
    {
        $fwd = $page->text('fwd');
        $site = $this->signIn->displayUrl();
        $fwd = RedirectTarget::sanitize(empty($fwd) ? $site : $fwd, $site, $this->signIn->configuredUrl());

        $login = $page->text('email');
        $password = trim($page->text('password'));

        // Who is it for? The throttle counts for the ACCOUNT the name resolves to (the database compares names by its own collation, so spellings that differ
        // in case, accents or the like are one account), else for the name folded.
        $user = $this->signIn->accountFor($login);
        $key = $user !== null ? LoginThrottle::keyForAccount((string) $user->getUUID()) : LoginThrottle::keyForUnknownName($login);

        // An attempt is reserved BEFORE the password is looked at (so that parallel requests cannot each get a guess); too many in this window and it is
        // refused without checking, even a right password.
        if (!$this->attempts->attempt($key)) {
            $this->signIn->message($this->translation->translate('Too many failed sign-in attempts for that username or email address. Please wait a few minutes and try again.'), 'alert-danger');

            return $this->toTheLoginPage($page, $fwd);
        }

        $oops = $this->translation->translate("Oops! That username or password isn't right. Please try again.");

        if ($user === null) {
            $this->signIn->trigger('login/failure/nouser', ['method' => 'password', 'credentials' => ['email' => $login]]);
            $page->setStatus(401);
            // A login for a name nobody has costs what a wrong password for a member costs (one bcrypt), so that the time it takes does not say which names
            // are members'.
            ($this->spendVerification ?? $this->passwords->spendVerification(...))($password);
            $this->signIn->message($oops, 'alert-danger');

            return $this->toTheLoginPage($page, $fwd);
        }

        if ($this->signIn->checkPassword($user, $password)) {
            $this->attempts->reset($key);
            // for auditing: a member who is refused (a blocked address, an account being deleted) did not sign in
            if ($this->signIn->mayLogOn($user)) {
                $this->signIn->trigger('login/success', ['user' => $user]);
            }
            $this->signIn->logOn($user);
        } else {
            $this->signIn->message($oops, 'alert-danger');
            $this->signIn->trigger('login/failure', ['user' => $user]);
        }

        return $this->toTheLoginPage($page, $fwd);
    }

    /**
     * The logout's POST: the session ends and a new one starts, which holds the message; the browser goes back to the page it came from if that is a page
     * of this site, else the home page.
     */
    public function logout(PageContext $page): Response
    {
        // `false` and not null for nobody: what the event always carried
        $this->signIn->trigger('logout/success', ['user' => $this->signIn->currentUser() ?? false]); // for auditing

        $this->signIn->logOff();
        // The old session is gone (its data, its user, its form token and its id); the message waits in the new one, which the page the visitor lands on
        // shows. A signed request has no session of its own to tell, and could land the message in a browser session it carried.
        if (!$this->signIn->isApiRequest()) {
            $this->signIn->message($this->translation->translate("You've signed out. See you soon!"));
        }

        return $this->renderer->redirect($page, RedirectTarget::sanitize((string) $page->request()->headers->get('Referer', ''), $this->signIn->displayUrl(), $this->signIn->configuredUrl()));
    }

    private function toTheLoginPage(PageContext $page, string $fwd): Response
    {
        return $this->renderer->redirect($page, $this->signIn->loginAddress($fwd));
    }

}
