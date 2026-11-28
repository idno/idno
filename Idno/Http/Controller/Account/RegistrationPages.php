<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Account;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Templating\TemplateTranslation;
use Symfony\Component\HttpFoundation\Response;

/**
 * The registration page: the one implementation `RegistrationController` calls.
 *
 * What it asks the site is the interface `Registrations`, so it is built and tested without the legacy application. The rules it keeps, each also pinned
 * over HTTP by the recorded answers of the page:
 * - a site with no account registers nobody (its first administrator is made by the installation, never by this page), and a signed-in member is sent home;
 * - on a site closed to registration the page works only with an invitation (the address and the code of the link an administrator sent); a code is always
 *   checked, also on an open site, because it may make an administrator; the POST applies the same rules as the form;
 * - the invitation decides the address of the account and whether it is an administrator's; neither is read from the form, and nothing in the request
 *   names a role;
 * - the invitation is used exactly once, in the same step of the database as the account (see `Registrations::create()`); one that was used meanwhile is a
 *   refusal, and a busy database is "try again", with what was typed;
 * - a refusal is a page that says why (403, with `Referrer-Policy: no-referrer`: the link carries its code in the query); a failed form is the form again
 *   (400) with what was typed, never the passwords;
 * - the invitation's address and code are variables of the page's body alone, drawn on a copy, never of the page's template (JSON and JSONP print every
 *   variable of it).
 */
final class RegistrationPages
{
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly Registrations $registrations,
        private readonly TemplateTranslation $translation,
    )
    {
    }

    /**
     * The form, or why this visitor may not have it.
     */
    public function form(PageContext $page): Response
    {
        if (($early = $this->answerWithoutRegistering($page)) !== null) {
            return $early;
        }
        // Texts: a field sent as an array is an empty one
        $code = $page->text('code');
        $email = $page->text('email');

        $admission = $this->admission($email, $code);
        if ($admission->problem !== '') {
            return $this->refuse($page, $admission->problem);
        }

        return $this->drawForm($page, $admission->invitation, ['email' => $email, 'code' => $code]);
    }

    /**
     * The form's POST: the account is made and the member signed in, or the form is shown again with why not.
     */
    public function register(PageContext $page): Response
    {
        if (($early = $this->answerWithoutRegistering($page)) !== null) {
            return $early;
        }
        // Texts: a field sent as an array is an empty one
        $name = $page->text('name');
        $handle = trim($page->text('handle'));
        $password = trim($page->text('password'));
        $password2 = trim($page->text('password2'));
        $email = trim($page->text('email'));
        $code = $page->text('code');

        $admission = $this->admission($email, $code);
        if ($admission->problem !== '') {
            return $this->refuse($page, $admission->problem);
        }
        $invitation = $admission->invitation;
        if ($invitation !== null) {
            // The invitation is for one address, and that is the address of the account
            $email = $invitation->email;
        }

        $made = null;
        if (self::blank($handle) && self::blank($email)) {
            $this->error('Please enter a username and email address.');
        } elseif (!self::blank($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $blocked = $this->registrations->emailIsBlocked($email);
            $emailTaken = false;
            $handleTaken = false;
            $acceptable = false;
            if (!$blocked) {
                $emailTaken = $this->registrations->accountExistsForEmail($email);
                if (!$emailTaken) {
                    $handleTaken = $this->registrations->handleIsTaken($handle);
                    $acceptable = !$handleTaken
                        && !self::blank($handle)
                        && strlen($handle) <= 32
                        && preg_match('/^[a-zA-Z0-9_]{1,}$/', $handle) === 1
                        && $password === $password2
                        && $this->registrations->passwordIsStrong($password)
                        && $this->registrations->validates([
                            'name' => $name,
                            'handle' => $handle,
                            'email' => $email,
                            'password' => $password,
                            'password2' => $password2,
                        ]);
                }
            }

            if ($acceptable) {
                $lowerHandle = strtolower($handle);
                // (an empty name, `0` too, is the handle)
                if (self::blank($name)) {
                    $name = $lowerHandle;
                }
                $account = $this->registrations->create($email, $lowerHandle, $password, $name, $invitation);
                if ($account->outcome === NewAccount::USED) {
                    return $this->refuse($page, RegistrationAdmission::INVALID);
                }
                if ($account->outcome === NewAccount::BUSY) {
                    // Nothing was changed, and the invitation is as it was: say to try again, with what was typed
                    $this->error('The site is busy just now, and your account was not made. Please try again in a moment.');
                    $page->addHeader('Retry-After', '5');

                    return $this->drawForm($page, $invitation, ['email' => $email, 'code' => $code, 'name' => $name, 'handle' => $handle], 503);
                }
                if ($account->outcome === NewAccount::MADE && $account->user !== null) {
                    $made = $account;
                }
            } else {
                if ($blocked) {
                    $this->error("An account can't be created with that email address.");
                }
                if (self::blank($handle)) {
                    $this->error('Please create a username.');
                }
                if (strlen($handle) > 32) {
                    $this->error('Your username is too long.');
                }
                if (!self::blank($handle) && preg_match('/^[a-zA-Z0-9_]{1,}$/', $handle) !== 1) {
                    $this->error('Usernames can only have letters, numbers and underscores.');
                }
                if ($handleTaken) {
                    $this->error('Unfortunately, someone is already using that username. Please choose another.');
                }
                if ($emailTaken) {
                    $this->error("Hey, it looks like there's already an account with that email address. Did you forget your login?");
                }
                if (!$this->registrations->passwordIsStrong($password) || $password !== $password2) {
                    $this->error('Please check that your passwords match and that your password is at least 7 characters long.');
                }
            }
        } else {
            $this->error("That doesn't seem like it's a valid email address.");
        }

        if ($made !== null) {
            $welcome = $made->administrator && $invitation !== null
                ? 'Welcome! Your account is ready, and you are an administrator of this site.'
                : "You've registered! You're ready to get started. Why not add a post to say hello?";
            $this->registrations->message($this->translation->translate($welcome));
            $this->registrations->logOn($made->user);

            return $this->renderer->redirect($page);
        }

        // Back to the form, with what was typed (never the passwords). The invitation has not been used.
        $this->registrations->messageAtStart($this->translation->translate("We couldn't register you."), 'alert-danger');

        return $this->drawForm($page, $invitation, ['email' => $email, 'code' => $code, 'name' => $name, 'handle' => $handle], 400);
    }

    /**
     * What a visitor who has no use for the page is sent to: a member is sent home with a message, and a site with no account is sent to its home page,
     * which says it is being set up. Null for anybody else.
     */
    private function answerWithoutRegistering(PageContext $page): ?Response
    {
        $name = $this->registrations->signedInName();
        if ($name !== null) {
            $this->registrations->message(htmlentities($this->translation->translate("You're already signed in as %s. To create another account, sign out first.", [$name]), ENT_QUOTES, 'UTF-8'));

            return $this->renderer->redirect($page, $this->registrations->displayUrl());
        }
        if (!$this->registrations->siteHasAccounts()) {
            return $this->renderer->redirect($page, $this->registrations->siteUrl());
        }

        return null;
    }

    /**
     * Whether this visitor may register, and with which invitation. A code is always checked, also on a site anyone can join: it may make an administrator.
     */
    private function admission(string $email, string $code): RegistrationAdmission
    {
        $open = $this->registrations->isOpen();
        if ($code !== '' || !$open) {
            if (!$this->registrations->canAddUsers() || ($code === '' && !$open)) {
                return new RegistrationAdmission(null, 'closed');
            }

            return $this->registrations->checkInvitation($email, $code);
        }

        return new RegistrationAdmission(null, '');
    }

    /**
     * Tells a visitor who may not register why, on a page of its own (the home page of a private site would only tell them to sign in).
     */
    private function refuse(PageContext $page, string $problem): Response
    {
        return $this->gate($page, 403, $this->renderer->templateOnACopy('account/register/refused', ['problem' => $problem]));
    }

    /**
     * The registration form: the invitation's address when there is one, what was typed (never a password), and the invitation's code, as variables of
     * the form's own draw.
     *
     * @param array<string, string> $values email, code, and name and handle after a refusal
     */
    private function drawForm(PageContext $page, ?RegistrationInvitation $invitation, array $values, int $status = 200): Response
    {
        return $this->gate($page, $status, $this->renderer->templateOnACopy('account/register', [
            'email' => $invitation !== null ? $invitation->email : (string) ($values['email'] ?? ''),
            'code' => (string) ($values['code'] ?? ''),
            'name' => (string) ($values['name'] ?? ''),
            'handle' => (string) ($values['handle'] ?? ''),
            'invited' => $invitation !== null,
            'is_admin' => $invitation !== null && $invitation->administrator,
            'inviter' => $invitation !== null ? $invitation->inviter : '',
        ]));
    }

    /**
     * A page of this address in the site's own shell, with the session's messages: the settings shell is for members.
     */
    private function gate(PageContext $page, int $status, string $body): Response
    {
        // The link of an invitation carries its code in the query: no page this one links to is told where it came from
        $page->addHeader('Referrer-Policy', 'no-referrer');

        return $this->renderer->pageInExactShell($page, [
            'body' => $body,
            'title' => $this->translation->translate('Create a new account'),
        ], $status);
    }

    private function error(string $text): void
    {
        $this->registrations->message($this->translation->translate($text), 'alert-danger');
    }

    /**
     * PHP's `empty()` of a text: nothing, and `0`, which the page has always counted as nothing (a handle of `0` is no handle).
     */
    private static function blank(string $value): bool
    {
        return $value === '' || $value === '0';
    }

}
