<?php

namespace IdnoPlugins\Portfolios\Pages {

    use Idno\Core\Idno;
    use IdnoPlugins\Portfolios\Portfolio;

    /**
     * The form that makes a portfolio (/portfolio/edit) or changes one (/portfolio/edit/<id>):
     * its name, its description, and whether it is a draft or published. A POST with the
     * status alone publishes or unpublishes.
     */
    class Edit extends Base
    {

        function getContent()
        {
            $this->createGatekeeper();
            $this->member = Idno::site()->session()->currentUser();

            if (!empty($this->arguments[0])) {
                $portfolio = $this->editablePortfolio($this->arguments[0]);
                $this->member = $portfolio->getOwner();
                $title = $this->language()->_('Edit portfolio');
            } else {
                $portfolio = new Portfolio();
                $title = $this->language()->_('New portfolio');
            }

            $t = Idno::site()->template();
            $t->__([
                'title' => $title,
                'body' => $t->__(['portfolio' => $portfolio, 'heading' => $title])->draw('portfolios/edit'),
            ])->drawPage();
        }

        function postContent()
        {
            $this->createGatekeeper();
            $new = empty($this->arguments[0]);
            $portfolio = $new ? new Portfolio() : $this->editablePortfolio($this->arguments[0]);
            $formUrl = $new ? Idno::site()->config()->getDisplayURL() . 'portfolio/edit' : $portfolio->getEditURL();

            $name = $this->getInput('name');
            $description = $this->getInput('description');
            $status = $this->getInput('publish_status');

            if ($name !== null || $new) {
                if (!is_string($name) || !$portfolio->setName($name)) {
                    Idno::site()->session()->addErrorMessage($this->language()->_('Give your portfolio a name.'));
                    $this->forward($formUrl);
                }
            }
            if (is_string($description)) {
                $portfolio->setDescriptionText($description);
            }
            if ($status === 'published' || $status === 'draft') {
                $portfolio->setPublishStatus($status);
            } else if ($new) {
                $portfolio->setPublishStatus('draft');
            }

            if (!$portfolio->save()) {
                Idno::site()->session()->addErrorMessage($this->language()->_("We couldn't save your portfolio."));
                $this->forward($formUrl);
            }

            if ($new) {
                Idno::site()->session()->addMessage($this->language()->_('Your portfolio was created. Add posts to it from the menu on each of your posts.'));
            } else if ($name === null && $status === 'published') {
                Idno::site()->session()->addMessage($this->language()->_('Your portfolio is published.'));
            } else if ($name === null && $status === 'draft') {
                Idno::site()->session()->addMessage($this->language()->_('Your portfolio is a draft again. Only you can see it.'));
            } else {
                Idno::site()->session()->addMessage($this->language()->_('Your portfolio was saved.'));
            }
            $this->forward($portfolio->getURL());
        }

    }

}
