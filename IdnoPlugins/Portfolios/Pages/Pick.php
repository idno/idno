<?php

namespace IdnoPlugins\Portfolios\Pages {

    use Idno\Core\Idno;
    use Idno\Entities\User;
    use IdnoPlugins\Portfolios\Portfolio;

    /**
     * The picker a member reaches from the menu of one of their own posts: a list of their
     * portfolios, ticked where the post is in one, and a field to make a new one. Saving it
     * (a POST with a form token) puts the post into the ticked portfolios, takes it out of
     * the others, and makes the new portfolio as a draft.
     *
     * Only the member's own post, and only the member's own portfolios: ids in the request
     * that are someone else's change nothing.
     */
    class Pick extends Base
    {

        /**
         * The post the request names, which must be the signed-in member's own.
         */
        protected function ownPost()
        {
            $member = Idno::site()->session()->currentUser();
            $post = $this->postFromInput($this->getInput('object'));
            if (!$post) {
                $this->noContent();
            }
            $probe = new Portfolio();
            $probe->setOwner($member);
            if ($post->getOwnerID() !== $member->getUUID() || !$post->canEdit() || !$probe->accepts($post)) {
                $this->deniedContent();
            }

            return $post;
        }

        function getContent()
        {
            $this->createGatekeeper();
            $member = Idno::site()->session()->currentUser();
            $this->member = $member;
            $post = $this->ownPost();

            $return = \Idno\Pages\Session\Login::sanitizeRedirectUrl($this->getInput('return') ?: (string) ($_SERVER['HTTP_REFERER'] ?? ''));
            if (strpos($return, 'portfolio/pick') !== false || rtrim($return, '/') === rtrim(Idno::site()->config()->getDisplayURL(), '/')) {
                $return = $post->getDisplayURL();
            }

            $title = $this->language()->_('Add to portfolio');
            $t = Idno::site()->template();
            $t->__([
                'title' => $title,
                'body' => $t->__([
                    'post' => $post,
                    'portfolios' => Portfolio::forOwner($member),
                    'return' => $return,
                    'heading' => $title,
                ])->draw('portfolios/pick'),
            ])->drawPage();
        }

        function postContent()
        {
            $this->createGatekeeper();
            /* @var User $member */
            $member = Idno::site()->session()->currentUser();
            $post = $this->ownPost();
            $uuid = $post->getUUID();

            $chosen = $this->getInput('portfolios');
            $chosen = is_array($chosen) ? array_filter($chosen, 'is_string') : [];
            $added = 0;
            $removed = 0;
            $full = false;

            foreach (Portfolio::forOwner($member) as $portfolio) {
                if ($portfolio->getOwnerID() !== $member->getUUID() || !$portfolio->canEdit()) {
                    continue;
                }
                $wanted = in_array((string) $portfolio->getID(), $chosen, true);
                if ($wanted && !$portfolio->hasItem($uuid)) {
                    if ($portfolio->addItem($post) && $portfolio->save()) {
                        $added++;
                    } else {
                        $full = true;
                    }
                } else if (!$wanted && $portfolio->hasItem($uuid)) {
                    if ($portfolio->removeItem($uuid) && $portfolio->save()) {
                        $removed++;
                    }
                }
            }

            $newName = $this->getInput('new_name');
            if (is_string($newName) && trim($newName) !== '') {
                $portfolio = new Portfolio();
                $portfolio->setOwner($member);
                $portfolio->setPublishStatus('draft');
                if ($portfolio->setName($newName) && $portfolio->addItem($post) && $portfolio->save()) {
                    $added++;
                    Idno::site()->session()->addMessage($this->language()->_('Your new portfolio is a draft. Publish it when it is ready.'));
                }
            }

            if ($full) {
                Idno::site()->session()->addErrorMessage($this->language()->_('A portfolio holds %d posts at most.', [Portfolio::MAX_ITEMS]));
            }
            if ($added || $removed) {
                Idno::site()->session()->addMessage($this->language()->_('Your portfolios were updated.'));
            }

            $return = \Idno\Pages\Session\Login::sanitizeRedirectUrl($this->getInput('return'));
            if (strpos($return, 'portfolio/pick') !== false || rtrim($return, '/') === rtrim(Idno::site()->config()->getDisplayURL(), '/')) {
                $return = $post->getDisplayURL();
            }
            $this->forward($return);
        }

    }

}
