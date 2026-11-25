<?php

namespace IdnoPlugins\Portfolios\Pages {

    use Idno\Core\Idno;
    use Idno\Entities\User;
    use IdnoPlugins\Portfolios\Portfolio;

    /**
     * What the portfolio pages share.
     */
    abstract class Base extends \Idno\Common\Page
    {

        /// The member whose portfolios this page is about
        protected $member = null;

        /**
         * Which item of the Twenty26 side navigation this page belongs to: "Portfolios" on
         * the viewer's own portfolio pages, none on anyone else's.
         *
         * @param  User|null $viewer
         * @return string
         */
        function navigationItem($viewer = null)
        {
            if ($viewer instanceof User && $this->member instanceof User && $viewer->getUUID() === $this->member->getUUID()) {
                return 'portfolios';
            }

            return '';
        }

        /**
         * The member named by a handle in the address, or the site's "not found" page: also for
         * a member whose profile is hidden by a ban, unless an administrator is looking.
         */
        protected function memberOrNotFound($handle): User
        {
            $member = is_string($handle) && $handle !== '' ? User::getByHandle(rawurldecode($handle)) : null;
            if (!($member instanceof User)) {
                $this->noContent();
            }
            // As the profile does: a member banned together with everything they posted has
            // no profile to show, and no portfolios
            if ($member->isHiddenByBan() && !Idno::site()->session()->isAdmin()) {
                $this->noContent();
            }

            return $member;
        }

        /**
         * The portfolio with the id in the address, which the viewer must be allowed to
         * change: "not found" when there is none they can see, "refused" when it is not theirs.
         */
        protected function editablePortfolio($id): Portfolio
        {
            $portfolio = Portfolio::findByID((string) $id);
            if (!$portfolio) {
                $this->noContent();
            }
            if (!$portfolio->canEdit()) {
                $this->deniedContent();
            }

            return $portfolio;
        }

        /**
         * A post named in a request by its id or its UUID, if the viewer may read it.
         */
        protected function postFromInput($value): ?\Idno\Common\Entity
        {
            if (!is_string($value) || $value === '' || strlen($value) > 500) {
                return null;
            }
            $post = preg_match('/^[A-Za-z0-9\-]{1,64}$/', $value) ? \Idno\Common\Entity::getByID($value) : \Idno\Common\Entity::getByUUID($value, false);

            return $post instanceof \Idno\Common\Entity && $post->canRead() ? $post : null;
        }

        protected function language()
        {
            return Idno::site()->language();
        }

    }

}
