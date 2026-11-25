<?php

    /**
     * Site invitation representation
     *
     * An invitation lets the person at one email address create an account, as a member or as
     * an administrator, until it expires. The role is stored here and nowhere else: neither
     * the link nor the registration form says what the new account will be.
     *
     * Invitations are for administrators only: their access is a value that no access group
     * matches, canRead() and canEdit() are true for administrators, and nothing of them is
     * serialised. The registration page, which a visitor uses, looks them up through
     * unrestricted().
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Entities {

    use Idno\Core\Email;

    class Invitation extends \Idno\Common\Entity
    {

        const ACCESS = 'INVITATION';

        const ROLE_MEMBER = 'member';
        const ROLE_ADMIN = 'admin';

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
         * Generates the code associated with this invitation: 64 hex characters from the
         * system's source of randomness
         */
        function generateCode()
        {
            $this->code = bin2hex(random_bytes(32));
        }

        function save($overrideAccess = true)
        {
            $this->access = self::ACCESS;
            $this->setPublishStatus('published');
            if (empty($this->slug)) {
                // An address of its own that says nothing
                $this->slug = 'invitation-' . bin2hex(random_bytes(12));
            }
            if (empty($this->expires)) {
                $this->expires = time() + static::lifetimeDays() * 86400;
            }

            return parent::save(true);
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

        function getTitle()
        {
            return '';
        }

        function getDescription()
        {
            return '';
        }

        function getActivityStreamsObjectType()
        {
            return false; // Not content: never federated, never in a feed
        }

        function getURL()
        {
            return \Idno\Core\Idno::site()->config()->getURL() . 'admin/users';
        }

        public function jsonSerialize(): mixed
        {
            return [];
        }

        /**
         * Run a lookup or a change of invitations whoever is asking: the registration page
         * works for a visitor, who can read no invitation.
         *
         * @param  callable $work
         * @return mixed What $work returns
         */
        static function unrestricted(callable $work)
        {
            $db = \Idno\Core\Idno::site()->db();
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
         * @param  string $email
         * @return string
         */
        static function normalizeEmail($email)
        {
            return strtolower(trim((string) $email));
        }

        /**
         * The email addresses in what an administrator typed: separated by spaces, new lines,
         * commas or semicolons, "Name <address>" accepted.
         *
         * @param  string $text
         * @return array[] The valid addresses (lower case, each once) and the pieces that are
         *                 not addresses
         */
        static function parseAddresses($text)
        {
            $valid = [];
            $invalid = [];
            foreach (preg_split('/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY) as $piece) {
                $address = static::normalizeEmail(trim($piece, "<>\"'()"));
                if (strpos($piece, '@') === false) {
                    continue; // A word of a name
                }
                if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $valid[$address] = $address;
                } else {
                    $invalid[] = $piece;
                }
            }

            return [array_values($valid), $invalid];
        }

        /**
         * Every invitation, newest first
         *
         * @return Invitation[]
         */
        static function all()
        {
            return static::unrestricted(
                function () {
                    $found = static::get([], [], 1000);

                    return is_array($found) ? $found : [];
                }
            );
        }

        /**
         * Every invitation to an email address
         *
         * @param  string $email
         * @return Invitation[]
         */
        static function allForEmail($email)
        {
            $email = (string) $email;
            $found = [];
            // Earlier versions stored the address as it was typed
            foreach (array_unique([static::normalizeEmail($email), trim($email)]) as $address) {
                if ($address === '') {
                    continue;
                }
                $rows = static::unrestricted(
                    function () use ($address) {
                        return \Idno\Core\Idno::site()->db()->getObjects(get_called_class(), ['email' => $address], null, 100);
                    }
                );
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $found[(string) $row->getID()] = $row;
                }
            }

            return array_values($found);
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
         * Look an invitation up by the address and the code of its link.
         *
         * @param  string $email
         * @param  string $code
         * @return array 'invitation' (Invitation|null: set only when it can be used) and
         *               'problem': '' when it can be used, 'expired' when it is too old, 'invalid'
         *               when there is no such invitation (never made, used, or withdrawn)
         */
        static function check($email, $code)
        {
            $code = is_string($code) ? $code : '';
            $match = null;
            if ($code !== '' && is_string($email)) {
                foreach (static::allForEmail($email) as $invitation) {
                    // Every candidate is compared, in constant time
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
                static::unrestricted(
                    function () use ($row) {
                        return $row->delete();
                    }
                );
            }

            return true;
        }

        /**
         * Use this invitation up. It is read again and deleted, so that of two requests with
         * the same link only one is told yes (the database layer has no transactions: this
         * narrows the window to the time between the read and the delete).
         *
         * @return bool Was it still there to be used?
         */
        function consume()
        {
            $id = $this->getID();

            return (bool) static::unrestricted(
                function () use ($id) {
                    $fresh = static::getByID($id);
                    if (!($fresh instanceof Invitation) || $fresh->isExpired()) {
                        return false;
                    }

                    return $fresh->delete();
                }
            );
        }

        /**
         * When this invitation stops working (a timestamp). Invitations made before they
         * expired count from the day they were made.
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
         * Give the invitation its full time again, and a new code if the old one had expired
         */
        function renew()
        {
            if ($this->isExpired()) {
                $this->generateCode();
            }
            $this->expires = time() + static::lifetimeDays() * 86400;
        }

        /**
         * @return string ROLE_MEMBER or ROLE_ADMIN
         */
        function getRole()
        {
            return $this->role === self::ROLE_ADMIN ? self::ROLE_ADMIN : self::ROLE_MEMBER;
        }

        function setRole($role)
        {
            $this->role = $role === self::ROLE_ADMIN ? self::ROLE_ADMIN : self::ROLE_MEMBER;
        }

        function isForAdministrator()
        {
            return $this->getRole() === self::ROLE_ADMIN;
        }

        /**
         * The name of the administrator who made the invitation, as plain text
         *
         * @return string
         */
        function getInviterName()
        {
            return (string) $this->inviter_name;
        }

        /**
         * The address the invited person opens to join
         *
         * @return string
         */
        function getLink()
        {
            return Email::siteUrl('account/register/') . '?email=' . urlencode((string) $this->email) . '&code=' . urlencode((string) $this->code);
        }

        /**
         * Email the invitation to its address. The invitation must have been saved.
         *
         * @return bool Did the mail server take it? If not, $sendError says why.
         */
        function send()
        {
            $this->sendError = '';
            $site = \Idno\Core\Idno::site();
            $vars = [
                'email'    => (string) $this->email,
                'code'     => (string) $this->code,
                'link'     => $this->getLink(),
                'inviter'  => $this->getInviterName(),
                'is_admin' => $this->isForAdministrator(),
                'expires'  => $this->getExpiry(),
            ];
            $message = new Email();
            try {
                $message->addTo((string) $this->email);
                $message->setBodyFromTemplate('account/invite', $vars);
                $inviter_email = (string) $this->inviter_email;
                if ($inviter_email !== '' && filter_var($inviter_email, FILTER_VALIDATE_EMAIL) && empty($site->config()->reply_to_email)) {
                    $message->setReplyTo($inviter_email);
                }
                $sent = (bool) $message->send();
            } catch (\Throwable $e) {
                $sent = false;
                list($message->errorKind, $message->error) = Email::explain($e);
            }
            $this->sendError = $sent ? '' : (string) $message->error;
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
                    $this->invited_by = $inviter->getUUID();
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
         * Associates this invitation with a particular email address; returns false if the address is invalid
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
