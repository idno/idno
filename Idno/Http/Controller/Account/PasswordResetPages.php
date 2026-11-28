<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Account;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Security\LoginAttempts;
use Idno\Security\LoginThrottle;
use Idno\Templating\TemplateTranslation;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two password-reset pages: the one implementation `PasswordResetController` calls. `/account/password` asks for a reset link (the form, and the POST
 * that mails it), `/account/password/reset` is the link's page (the form that sets the new password, and its POST).
 *
 * What it asks the site is the interface `PasswordResets`, so it is built and tested without the legacy application. The rules it keeps, each also pinned
 * over HTTP by the recorded answers of the pages:
 * - the pages are for visitors: a member who is signed in is refused (the page's 403);
 * - a request for a link is answered the same whether or not the address has an account (a redirect to the page that says one was sent), and an address
 *   that may not reset (no member's, blocked, being deleted) is sent no code and no mail;
 * - the CODE is judged before the passwords: whoever has not a right one is told one thing, whatever was typed, and a wrong, a used and an expired code
 *   are not told apart; a refusal is a redirect to the page that asks for a new link;
 * - a refused password is the form again in the answer to the POST (a redirect would put the code in an address), carrying the address and the code and
 *   never a password, and the code is not used up;
 * - a good reset ends the account's other sessions, clears its count of failed sign-ins and sends the visitor to the sign-in page (nobody is signed in by
 *   it, and the home page of a private site would refuse them);
 * - the address and the code of the link are variables of the form's body alone, drawn on a copy, never of the page's template (JSON and JSONP print every
 *   variable of it), and the form's page sends `Referrer-Policy: no-referrer`: the link carries them in its query.
 */
final class PasswordResetPages
{
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly PasswordResets $resets,
        private readonly LoginAttempts $attempts,
        private readonly TemplateTranslation $translation,
    )
    {
    }

    /**
     * The page that asks for a reset link, or the one that says it was sent.
     */
    public function requestForm(PageContext $page): Response
    {
        if ($this->resets->isSignedIn()) {
            return $this->renderer->denied($page);
        }
        $vars = ['mail_configured' => $this->resets->mailIsConfigured()];
        if ($page->input('sent')) {
            $body = $this->renderer->templateOnACopy('account/password/sent', $vars);
            $title = 'Password recovery email sent';
        } else {
            $body = $this->renderer->templateOnACopy('account/password', $vars);
            $title = 'Recover password';
        }

        return $this->renderer->pageInExactShell($page, ['body' => $body, 'title' => $this->translation->translate($title)]);
    }

    /**
     * The POST that asks for a link: the member is mailed one, and everyone, a stranger's address too, is sent to the same page.
     */
    public function request(PageContext $page): Response
    {
        if ($this->resets->isSignedIn()) {
            return $this->renderer->denied($page);
        }
        $email = $page->text('email');

        // An account that is being deleted, and an address the site has blocked, are sent no code: the answer is the same
        $account = $this->resets->accountForRequest($email);
        if ($account !== null && ($code = $this->resets->issueCode($account)) !== null) {
            $this->resets->mailCode($account, $email, $code);
        }

        // Always show the same response to prevent user enumeration
        return $this->renderer->redirect($page, $this->resets->displayUrl() . 'account/password/?sent=true');
    }

    /**
     * The link's page: the form that sets the new password, or the refusal of a code that is not right.
     */
    public function resetForm(PageContext $page): Response
    {
        if ($this->resets->isSignedIn()) {
            return $this->renderer->denied($page);
        }
        $code = $page->text('code');
        $email = $page->text('email');

        if ($this->resets->memberFor($email, $code) === null) {
            return $this->refuseTheCode($page);
        }

        return $this->drawForm($page, $email, $code);
    }

    /**
     * The form's POST: the password is changed and the visitor sent to the sign-in page, or the code is refused, or the form is drawn again.
     */
    public function reset(PageContext $page): Response
    {
        if ($this->resets->isSignedIn()) {
            return $this->renderer->denied($page);
        }
        $code = $page->text('code');
        $email = $page->text('email');
        $password = trim($page->text('password'));
        $password2 = trim($page->text('password2'));

        // The code is judged first: whoever has not a right one is told only that, whatever they typed as passwords
        $account = $this->resets->memberFor($email, $code);
        if ($account === null) {
            return $this->refuseTheCode($page);
        }

        if (!$this->resets->passwordIsStrong($password) || $password !== $password2) {
            $this->resets->message($this->translation->translate("Sorry, your passwords either don't match, or are too weak"), 'alert-danger');

            // The form again, here: a redirect would put the code in an address
            return $this->drawForm($page, $email, $code, 400);
        }

        $this->resets->changePassword($account, $password);
        // A member who was locked out of signing in can sign in at once.
        $this->attempts->reset(LoginThrottle::keyForAccount($this->resets->accountId($account)));
        // On to the sign-in page: the home page of a private site would refuse them, and nobody is signed in by a reset
        $this->resets->message($this->translation->translate('Your password was changed. Sign in with your new password.'));

        return $this->renderer->redirect($page, $this->resets->displayUrl() . 'session/login');
    }

    /**
     * The one answer to a code that is not right: it does not say whether it was wrong, used or too old, and the page it goes to asks for a new link.
     */
    private function refuseTheCode(PageContext $page): Response
    {
        $this->resets->message($this->translation->translate("The password reset code wasn't valid. They expire after three hours, so you might need to try again."), 'alert-danger');

        return $this->renderer->redirect($page, $this->resets->displayUrl() . 'account/password');
    }

    /**
     * The form for this address and code in the site's own shell (the settings shell is for members), with the code and the address as variables of its
     * own draw.
     */
    private function drawForm(PageContext $page, string $email, string $code, int $status = 200): Response
    {
        // The link carries the address and the code in its query: no page this one links to is told where it came from
        $page->addHeader('Referrer-Policy', 'no-referrer');

        return $this->renderer->pageInExactShell($page, [
            'body' => $this->renderer->templateOnACopy('account/password/reset', ['email' => $email, 'code' => $code]),
            'title' => $this->translation->translate('Reset password'),
        ], $status);
    }

}
