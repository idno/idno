<?php

    /**
     * Site invitation representation
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Entities {

    use Idno\Core\Email;

    #[\Idno\Storage\Attribute\AsEntity('invitation')]
    #[\Idno\Storage\Attribute\IndexedField('email')]
    #[\Idno\Storage\Attribute\IndexedField('code')]
    class Invitation extends \Idno\Common\Entity
    {

        function __construct()
        {
            $this->generateCode();

            parent::__construct();
        }

        /**
         * Generates the code associated with this invitation
         */
        function generateCode()
        {
            $token = new \Idno\Core\TokenProvider();

            $this->code = $token->generateHexToken(16);
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
         * Every invitation to an email address (none for a value that is not text)
         *
         * @param  $email
         * @return Invitation[]
         */
        static function allForEmail($email)
        {
            if (!is_string($email) || $email === '') {
                return [];
            }
            $rows = static::unrestricted(
                function () use ($email) {
                    return \Idno\Core\Idno::site()->db()->getObjects(get_called_class(), array('email' => $email), null, 100);
                }
            );

            return is_array($rows) ? array_values($rows) : [];
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
         * Validates an email address / invitation code combination (or returns false if no such invitation exists).
         * Both must be text, and the code is compared with every invitation to the address, in constant time,
         * never as a condition of the lookup.
         *
         * @param  $email
         * @param  $code
         * @return \Idno\Entities\Invitation|false
         */
        static function validate($email, $code)
        {
            if (!is_string($code) || $code === '') {
                return false;
            }
            $match = false;
            foreach (static::allForEmail($email) as $invitation) {
                if (is_string($invitation->code) && hash_equals($invitation->code, $code)) {
                    $match = $invitation;
                }
            }

            return $match;
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
                $row->delete();
            }

            return true;
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
                $this->save();
                $message = new Email();
                $message->addTo($email);
                $message->setSubject(\Idno\Core\Idno::site()->language()->_("You've been invited to join %s", [\Idno\Core\Idno::site()->config()->title]));
                $message->setHTMLBodyFromTemplate('account/invite', array('email' => $email, 'code' => $this->code, 'inviter' => \Idno\Core\Idno::site()->session()->currentUser()->getTitle()));
                $message->setTextBodyFromTemplate('account/invite', array('email' => $email, 'code' => $this->code, 'inviter' => \Idno\Core\Idno::site()->session()->currentUser()->getTitle()));
                if (!empty($from_email)) {
                    $message->setReplyTo($from_email);
                }

                return $message->send();
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
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->email = $email;

                return true;
            }

            return false;
        }
    }

}
