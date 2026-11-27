<?php

    /**
     * Site invitation representation
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Entities {

    use Idno\Core\Email;

    /**
     * An invitation lets the person at one email address create an account, as a member or as an administrator,
     * until it expires, once. The role is stored here and nowhere else: neither the link nor the registration form
     * says what the new account will be.
     *
     * Invitations are for administrators only: their access is a value no access group matches, canRead() and
     * canEdit() are true for administrators alone, and nothing of them is serialised. The registration page, which a
     * visitor uses, looks them up through unrestricted().
     */
    #[\Idno\Storage\Attribute\AsEntity('invitation')]
    #[\Idno\Storage\Attribute\IndexedField('email')]
    #[\Idno\Storage\Attribute\IndexedField('code')]
    class Invitation extends \Idno\Common\Entity
    {

        const ROLE_MEMBER = 'member';
        const ROLE_ADMIN  = 'admin';

        /// Days an invitation can be used for, unless the invitation_lifetime_days setting says otherwise
        const LIFETIME_DAYS = 14;

        /// The most addresses one submission of the invite form may hold
        const MAX_PER_BATCH = 50;

        /// Why the last send() failed, in plain words for an administrator
        public $sendError = '';

        function __construct()
        {
            $this->generateCode();

            parent::__construct();
        }

        /**
         * Generates the code associated with this invitation: 64 hex characters from the system's source of
         * randomness. Shorter codes issued before stay valid.
         */
        function generateCode()
        {
            $token = new \Idno\Core\TokenProvider();

            $this->code = $token->generateHexToken(32);
        }

        /**
         * Invitations are for administrators only: the code is a credential. Their access is a value no
         * access group matches, and canRead() and canEdit() are true for administrators alone. Registration,
         * which a visitor uses, looks them up through unrestricted().
         */
        const ACCESS = 'INVITATION';

        function save($overrideAccess = false)
        {
            $this->access = self::ACCESS;
            if (empty($this->expires)) {
                $this->expires = time() + static::lifetimeDays() * 86400;
            }

            return parent::save($overrideAccess);
        }

        function canRead($user_id = '')
        {
            return static::isAdministrator($user_id);
        }

        function canEdit($user_id = '')
        {
            return static::isAdministrator($user_id);
        }

        protected static function isAdministrator($user_id = '')
        {
            $session = \Idno\Core\Idno::site()->session();
            if (empty($user_id)) {
                return $session->isLoggedOn() && $session->currentUser()->isAdmin();
            }
            $user = User::getByUUID($user_id);

            return $user && $user->isAdmin();
        }

        /**
         * An invitation is no content: it is never federated, never in a feed, never titled.
         */
        function getActivityStreamsObjectType()
        {
            return false;
        }

        function getTitle()
        {
            return '';
        }

        function getDescription()
        {
            return '';
        }

        function getURL()
        {
            return \Idno\Core\Idno::site()->config()->getURL() . 'admin/users';
        }

        /**
         * Invitations stored before they were administrators' alone have the access of any post. Make this one
         * an administrators' invitation (a listing then no longer returns it to anyone else).
         */
        private function protect(): void
        {
            if ($this->access !== self::ACCESS) {
                $this->access = self::ACCESS;
                parent::save(true);
            }
        }

        /**
         * Every invitation, newest first (for an administrator's list).
         *
         * @return Invitation[]
         */
        static function all()
        {
            $found = static::unrestricted(static fn () => static::get([], [], 1000));
            $found = is_array($found) ? $found : [];
            foreach ($found as $invitation) {
                $invitation->protect();
            }

            return $found;
        }

        /**
         * Nothing of an invitation is serialised: its code is a credential.
         */
        public function jsonSerialize(): mixed
        {
            return [];
        }

        /**
         * Run a lookup or a change of invitations whoever is asking: the registration page works for a
         * visitor, who can read no invitation.
         *
         * @param  callable $work
         * @return mixed What $work returns
         */
        static function unrestricted(callable $work)
        {
            $db     = \Idno\Core\Idno::site()->db();
            $before = $db->getIgnoreAccess();
            $db->setIgnoreAccess(true);
            try {
                return $work();
            } finally {
                $db->setIgnoreAccess($before);
            }
        }

        /**
         * Days a new invitation can be used for
         *
         * @return int
         */
        static function lifetimeDays()
        {
            $days = (int) \Idno\Core\Idno::site()->config()->invitation_lifetime_days;

            return $days > 0 ? $days : self::LIFETIME_DAYS;
        }

        /**
         * An email address as invitations store and compare it
         *
         * @param  mixed $email
         * @return string
         */
        static function normalizeEmail($email)
        {
            return is_string($email) ? strtolower(trim($email)) : '';
        }

        /**
         * The email addresses in what an administrator typed: separated by spaces, new lines, commas or
         * semicolons, "Name <address>" accepted.
         *
         * @param  mixed $text
         * @return array[] The valid addresses (lower case, each once) and the pieces that are not addresses
         */
        static function parseAddresses($text)
        {
            $valid   = [];
            $invalid = [];
            foreach (preg_split('/[\s,;]+/', is_string($text) ? $text : '', -1, PREG_SPLIT_NO_EMPTY) as $piece) {
                if (strpos($piece, '@') === false) {
                    continue; // A word of a name
                }
                $address = static::normalizeEmail(trim($piece, "<>\"'()"));
                if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $valid[$address] = $address;
                } else {
                    $invalid[] = $piece;
                }
            }

            return [array_values($valid), $invalid];
        }

        /**
         * Every invitation to an email address (none for a value that is not text)
         *
         * @param  $email
         * @return Invitation[]
         */
        static function allForEmail($email)
        {
            if (!is_string($email) || trim($email) === '') {
                return [];
            }
            $found = [];
            // Invitations stored before the address was lower-cased have it as it was typed
            foreach (array_unique([static::normalizeEmail($email), trim($email)]) as $address) {
                $rows = static::unrestricted(
                    function () use ($address) {
                        return \Idno\Core\Idno::site()->db()->getObjects(get_called_class(), array('email' => $address), null, 100);
                    }
                );
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $found[(string) $row->getID()] = $row;
                }
            }
            $found = array_values($found);
            foreach ($found as $row) {
                $row->protect();
            }

            return $found;
        }

        /**
         * Retrieves an invitation associated with a particular email address
         *
         * @param  $email
         * @return Invitation|false
         */
        static function getByEmail($email)
        {
            $found = static::allForEmail($email);

            return $found ? $found[0] : false;
        }

        /**
         * Look an invitation up by the address and the code of its link. Both must be text, and the code is compared
         * with every invitation to the address, in constant time, never as a condition of the lookup.
         *
         * @param  mixed $email
         * @param  mixed $code
         * @return array 'invitation' (Invitation|null: set only when it can be used) and 'problem': '' when it can be
         *               used, 'expired' when it is too old, 'invalid' when there is no such invitation (never made,
         *               used, or withdrawn). A wrong code is always 'invalid', so only whoever holds the right code
         *               learns anything about an address.
         */
        static function check($email, $code)
        {
            $match = null;
            if (is_string($code) && $code !== '') {
                foreach (static::allForEmail($email) as $invitation) {
                    if (is_string($invitation->code) && hash_equals($invitation->code, $code)) {
                        $match = $invitation;
                    }
                }
            }
            if (!$match) {
                return ['invitation' => null, 'problem' => 'invalid'];
            }
            if ($match->isExpired()) {
                return ['invitation' => null, 'problem' => 'expired'];
            }

            return ['invitation' => $match, 'problem' => ''];
        }

        /**
         * Validates an email address / invitation code combination (or returns false if no such invitation exists).
         *
         * @param  $email
         * @param  $code
         * @return \Idno\Entities\Invitation|false
         */
        static function validate($email, $code)
        {
            $check = static::check($email, $code);

            return $check['invitation'] ?: false;
        }

        /**
         * Retrieves an invitation associated with a particular email address and code.
         *
         * @param  $email
         * @param  $code
         * @return Invitation|false
         */
        static function getByEmailAndCode($email, $code)
        {
            return static::validate($email, $code);
        }

        /**
         * A cleanup utility method to remove all invitations associated with a given email address
         *
         * @param string $email
         */
        static function removeByEmail($email)
        {
            foreach (static::allForEmail($email) as $row) {
                static::unrestricted(static fn () => $row->delete());
            }

            return true;
        }

        /**
         * Use this invitation up, once. It is read again (an administrator may have withdrawn it, or sent it again
         * with a new code, since the page looked) and then CLAIMED: one conditional delete that only one caller can
         * win, so of two registrations that arrive with the same link exactly one is told yes. A claim does not run
         * the entity's delete events: an invitation is no content.
         *
         * @return bool Was it still there, with this code and in time, to be used by this call?
         */
        function consume()
        {
            $id   = $this->getID();
            $code = (string) $this->code;
            $role = $invited_by = null;

            $claimed = (bool) static::unrestricted(
                static function () use ($id, $code, &$role, &$invited_by) {
                    $fresh = Invitation::getByID($id);
                    if (!($fresh instanceof Invitation) || $fresh->isExpired() || !hash_equals((string) $fresh->code, $code)) {
                        return false;
                    }
                    $role       = $fresh->role;
                    $invited_by = $fresh->invited_by;

                    return \Idno\Core\Idno::site()->db()->claimRecord($id, $fresh->getCollection());
                }
            );
            if ($claimed) {
                // What the claimed record said, as it was when it was claimed (an administrator may have changed it since this one was read)
                $this->role       = $role;
                $this->invited_by = $invited_by;
            }

            return $claimed;
        }

        /**
         * When this invitation stops working (a timestamp). Invitations made before they expired count from the day
         * they were made.
         *
         * @return int
         */
        function getExpiry()
        {
            if (!empty($this->expires)) {
                return (int) $this->expires;
            }

            return (int) $this->created + static::lifetimeDays() * 86400;
        }

        function isExpired()
        {
            return $this->getExpiry() < time();
        }

        /**
         * Give the invitation its full time again, and a new code if the old one had expired (a link that was refused
         * stays refused: only the new mail works). The caller saves it.
         */
        function renew()
        {
            if ($this->isExpired()) {
                $this->generateCode();
            }
            $this->expires = time() + static::lifetimeDays() * 86400;
        }

        /**
         * @return string ROLE_MEMBER or ROLE_ADMIN: anything stored that is not 'admin' is a member's
         */
        function getRole()
        {
            return $this->role === self::ROLE_ADMIN ? self::ROLE_ADMIN : self::ROLE_MEMBER;
        }

        function setRole($role)
        {
            $this->role = $role === self::ROLE_ADMIN ? self::ROLE_ADMIN : self::ROLE_MEMBER;
        }

        /**
         * Is it stored as an administrator's invitation?
         */
        function isForAdministrator()
        {
            return $this->getRole() === self::ROLE_ADMIN;
        }

        /**
         * Does using it make an administrator? Only if it is stored as an administrator's and the member who sent it
         * is still an administrator: an invitation is issued by an administrator, so one whose sender has since lost
         * their rights (or their account) is a member's.
         */
        function makesAnAdministrator()
        {
            if (!$this->isForAdministrator() || !is_string($this->invited_by) || $this->invited_by === '') {
                return false;
            }
            $inviter = User::getByUUID($this->invited_by);

            return $inviter instanceof User && !empty($inviter->admin);
        }

        /**
         * The name of the administrator who made the invitation, as plain text
         *
         * @return string
         */
        function getInviterName()
        {
            return is_string($this->inviter_name) ? $this->inviter_name : '';
        }

        /**
         * The address the invited person opens to join, on the site's configured address
         *
         * @return string
         */
        function getLink()
        {
            return \Idno\Core\Idno::site()->config()->getURL() . 'account/register/?email=' . urlencode((string) $this->email) . '&code=' . urlencode((string) $this->code);
        }

        /**
         * Email the invitation to its address. The invitation must have been saved.
         *
         * @return bool Did the mail server take it? If not, $sendError says why.
         */
        function send()
        {
            $this->sendError = '';
            $message         = new Email();
            $message->addTo((string) $this->email);
            $message->setBodyFromTemplate(
                'account/invite', [
                    'email'    => (string) $this->email,
                    'code'     => (string) $this->code,
                    'link'     => $this->getLink(),
                    'inviter'  => $this->getInviterName(),
                    'is_admin' => $this->isForAdministrator(),
                    'expires'  => $this->getExpiry(),
                ]
            );
            $inviter_email = (string) $this->inviter_email;
            if ($inviter_email !== '' && filter_var($inviter_email, FILTER_VALIDATE_EMAIL)) {
                $message->setReplyTo($inviter_email);
            }
            $sent = $message->send() > 0;
            $this->sendError = $sent ? '' : $message->explanation();
            // For the administrators' list: when it was last mailed, or that the mail failed
            if ($sent) {
                $this->last_sent = time();
            }
            $this->send_failed = !$sent;
            $this->save();

            return $sent;
        }

        /**
         * Saves this invitation and sends it to the appropriate email address
         *
         * @param  $email
         * @param  $from_email If set, sets a reply to
         * @return bool|int
         */
        function sendToEmail($email, $from_email = '')
        {
            if ($this->associateWithEmail($email)) {
                $inviter = \Idno\Core\Idno::site()->session()->currentUser();
                if ($inviter) {
                    $this->inviter_name = $inviter->getTitle();
                    $this->invited_by   = $inviter->getUUID();
                }
                if (!empty($from_email)) {
                    $this->inviter_email = $from_email;
                }
                $this->save();

                return $this->send() ? 1 : 0;
            }

            return false;
        }

        /**
         * Associates this invitation with a particular email address (stored lower case); returns false if the
         * address is invalid
         *
         * @param  $email
         * @return bool
         */
        function associateWithEmail($email)
        {
            $email = static::normalizeEmail($email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->email = $email;

                return true;
            }

            return false;
        }
    }

}
