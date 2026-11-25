<?php

namespace IdnoPlugins\Portfolios\Pages {

    use Idno\Core\Idno;

    /**
     * Changes the posts of one portfolio (a POST with a form token): "remove", "up", "down"
     * or "add" for the post named in "object". Only whoever may edit the portfolio, and only
     * with the portfolio owner's own posts.
     */
    class Items extends Base
    {

        function getContent()
        {
            $this->createGatekeeper();
            $this->forward();
        }

        function postContent()
        {
            $this->createGatekeeper();
            $portfolio = $this->editablePortfolio($this->arguments[0] ?? '');
            $object = $this->getInput('object');
            $action = $this->getInput('action');
            $changed = false;

            if (is_string($object) && $object !== '') {
                switch ($action) {
                    case 'remove':
                        $changed = $portfolio->removeItem($object);
                        break;
                    case 'up':
                        $changed = $portfolio->moveItem($object, -1);
                        break;
                    case 'down':
                        $changed = $portfolio->moveItem($object, 1);
                        break;
                    case 'add':
                        $post = $this->postFromInput($object);
                        $changed = $post && $post->canEdit() && $portfolio->addItem($post);
                        break;
                }
            }

            if ($changed && $portfolio->save()) {
                if ($action === 'remove') {
                    Idno::site()->session()->addMessage($this->language()->_('The post was taken out of this portfolio.'));
                }
            } else if ($action === 'add') {
                $this->setResponse(403);
                Idno::site()->session()->addErrorMessage($this->language()->_("That post can't go into this portfolio."));
            }
            $this->forward($portfolio->getURL());
        }

    }

}
