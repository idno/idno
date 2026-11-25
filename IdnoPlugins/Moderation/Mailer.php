<?php

namespace IdnoPlugins\Moderation {

    use Idno\Core\Idno;
    use IdnoPlugins\Moderation\Entities\ModerationCase;

    /**
     * The mails this plugin sends. Each is put together here from a case, so that what goes
     * to whom is decided in one place:
     *
     * - a reporter is told that the report arrived and, later, whether something was done.
     *   Never what was done, and never which administrator did it;
     * - the member a case is about is told what happened to their content or account and the
     *   reason. Never who reported them, and never which administrator acted;
     * - administrators are told about a new report, with the reporter's own words.
     *
     * A mail is a heading, paragraphs, an optional quotation and an optional link; the two
     * templates (HTML and text) print them, and the HTML one escapes every part.
     */
    class Mailer
    {

        /// When this is an array, mails are added to it instead of being sent (for tests)
        public static $capture = null;

        /**
         * Put a mail together.
         *
         * @param  string $kind 'received', 'reviewed', 'content-removed', 'banned', 'restored', 'ban-lifted' or 'new-report'
         * @param  array  $vars 'case' (ModerationCase) and, by kind: 'action_taken', 'category', 'reason', 'description'
         * @return array 'subject', 'html', 'text'
         */
        public static function compose($kind, array $vars = [])
        {
            $language = Idno::site()->language();
            $site = (string) Idno::site()->config()->getTitle();
            $case = ($vars['case'] ?? null) instanceof ModerationCase ? $vars['case'] : null;
            $what = $case ? static::describe($case) : '';
            $contact = Moderation::contactEmail();
            $appeal = $contact !== ''
                ? $language->_('If you think this is a mistake, you can write to %s.', [$contact])
                : $language->_("If you think this is a mistake, you can contact the site's administrators.");
            $category = !empty($vars['category']) ? Reasons::label($vars['category']) : '';

            $mail = ['heading' => '', 'paragraphs' => [], 'quote' => '', 'link' => null];

            switch ($kind) {
                case 'received':
                    $mail['subject'] = $language->_('We received your report');
                    $mail['heading'] = $language->_('Thanks for your report');
                    $mail['paragraphs'][] = $language->_('You reported %s on %s.', [$what, $site]);
                    $mail['paragraphs'][] = $language->_("A moderator will review it. We'll email you when it has been looked at.");
                    $mail['paragraphs'][] = $language->_("We won't share your name with the person you reported.");
                    break;

                case 'reviewed':
                    $mail['subject'] = $language->_('Your report has been reviewed');
                    $mail['heading'] = $language->_('Your report has been reviewed');
                    $mail['paragraphs'][] = $language->_('You reported %s on %s.', [$what, $site]);
                    if (!empty($vars['action_taken'])) {
                        $mail['paragraphs'][] = $language->_('Our moderators reviewed it and have taken action.');
                    } else {
                        $mail['paragraphs'][] = $language->_("Our moderators reviewed it and didn't find that it breaks this site's rules, so no action was taken.");
                    }
                    $mail['paragraphs'][] = $language->_('Thank you for helping to keep %s safe.', [$site]);
                    break;

                case 'content-removed':
                    $comment = $case && $case->target_type === 'comment';
                    $mail['subject'] = $comment ? $language->_('Your comment was removed') : $language->_('Your post was removed');
                    $mail['heading'] = $mail['subject'];
                    $mail['paragraphs'][] = $comment
                        ? $language->_('The moderators of %s have removed a comment of yours.', [$site])
                        : $language->_('The moderators of %s have removed a post of yours. You can still see it when you are signed in; nobody else can.', [$site]);
                    if ($category !== '') {
                        $mail['paragraphs'][] = $language->_('Reason: %s.', [$category]);
                    }
                    $mail['quote'] = $case ? static::excerpt($case) : '';
                    $mail['paragraphs'][] = $appeal;
                    break;

                case 'restored':
                    $comment = $case && $case->target_type === 'comment';
                    $mail['subject'] = $comment ? $language->_('Your comment was restored') : $language->_('Your post was restored');
                    $mail['heading'] = $mail['subject'];
                    $mail['paragraphs'][] = $comment
                        ? $language->_('The moderators of %s have put back a comment of yours that had been removed.', [$site])
                        : $language->_('The moderators of %s have put back a post of yours that had been removed.', [$site]);
                    $mail['quote'] = $case ? static::excerpt($case) : '';
                    if ($case && $case->getTargetURL() !== '') {
                        $mail['link'] = ['url' => $case->getTargetURL(), 'label' => $language->_('See it')];
                    }
                    break;

                case 'banned':
                    $mail['subject'] = $language->_('Your account on %s has been banned', [$site]);
                    $mail['heading'] = $language->_('Your account has been banned');
                    $mail['paragraphs'][] = $language->_('The moderators of %s have banned your account. You can no longer sign in or post.', [$site]);
                    if ($category !== '') {
                        $mail['paragraphs'][] = $language->_('Reason: %s.', [$category]);
                    }
                    $mail['paragraphs'][] = $appeal;
                    break;

                case 'ban-lifted':
                    $mail['subject'] = $language->_('Your account on %s is no longer banned', [$site]);
                    $mail['heading'] = $language->_('Your ban has been lifted');
                    $mail['paragraphs'][] = $language->_('The moderators of %s have lifted the ban on your account. You can sign in again.', [$site]);
                    $mail['link'] = ['url' => Idno::site()->config()->getDisplayURL() . 'session/login', 'label' => $language->_('Sign in')];
                    break;

                case 'new-report':
                    $reason = (string) ($vars['reason'] ?? '');
                    $urgent = Reasons::isUrgent($reason);
                    $mail['subject'] = ($urgent ? $language->_('Urgent') . ': ' : '') . $language->_('New report on %s: %s', [$site, Reasons::label($reason)]);
                    $mail['heading'] = $urgent ? $language->_('An urgent report needs review') : $language->_('A new report needs review');
                    $mail['paragraphs'][] = $language->_('A member reported %s.', [$what]);
                    $mail['paragraphs'][] = $language->_('Reason: %s.', [Reasons::label($reason)]);
                    $description = (string) ($vars['description'] ?? '');
                    if ($description !== '') {
                        $mail['paragraphs'][] = $language->_('What they told us:');
                        $mail['quote'] = $description;
                    }
                    if ($case) {
                        $mail['link'] = ['url' => $case->getAdminURL(), 'label' => $language->_('Open in the moderation queue')];
                    }
                    break;

                default:
                    throw new \InvalidArgumentException('Unknown mail.');
            }

            $html = clone Idno::site()->template();
            $html->setTemplateType('email');
            $text = clone Idno::site()->template();
            $text->setTemplateType('email-text');

            return [
                'subject' => $mail['subject'],
                'html' => $html->__(['mail' => $mail])->draw('moderation/mail'),
                'text' => $text->__(['mail' => $mail])->draw('moderation/mail'),
            ];
        }

        /**
         * Put a mail together and send it (through the event queue, so that a slow mail
         * server does not hold up the page).
         */
        public static function deliver($kind, $to, array $vars = [])
        {
            $to = trim((string) $to);
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $mail = static::compose($kind, $vars);
            if (is_array(static::$capture)) {
                static::$capture[] = ['kind' => $kind, 'to' => $to] + $mail;

                return;
            }
            if (defined('IDNO_UNIT_TEST')) {
                return; // The test suite sends no mail
            }
            Idno::site()->queue()->enqueue('default', 'moderation/mail', ['to' => $to, 'subject' => $mail['subject'], 'html' => $mail['html'], 'text' => $mail['text']]);
        }

        /**
         * Send a mail that deliver() queued
         */
        public static function send(array $mail)
        {
            if (empty($mail['to']) || empty($mail['subject'])) {
                return;
            }
            $email = new \Idno\Core\Email();
            $email->setSubject((string) $mail['subject']);
            $email->addTo((string) $mail['to']);
            $email->setHTMLBody((string) ($mail['html'] ?? ''));
            $email->setTextBody((string) ($mail['text'] ?? ''));
            $email->send();
        }

        /**
         * What a case is about, in a few words: "a post by Maya Okafor"
         */
        public static function describe(ModerationCase $case)
        {
            $language = Idno::site()->language();
            $name = (string) ($case->snapshot['author_name'] ?? $case->subject_name);
            if ($name === '') {
                $name = $language->_('someone');
            }
            switch ($case->target_type) {
                case 'post':
                    return $language->_('a post by %s', [$name]);
                case 'comment':
                    return $language->_('a comment by %s', [$name]);
                default:
                    return $language->_('the profile of %s', [$name]);
            }
        }

        protected static function excerpt(ModerationCase $case)
        {
            $text = trim((string) ($case->snapshot['title'] ?? '') . "\n" . (string) ($case->snapshot['text'] ?? ''));
            if (mb_strlen($text) > 280) {
                $text = mb_substr($text, 0, 280) . '…';
            }

            return $text;
        }
    }

}
