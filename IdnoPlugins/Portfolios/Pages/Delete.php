<?php

namespace IdnoPlugins\Portfolios\Pages {

    use Idno\Core\Idno;
    use IdnoPlugins\Portfolios\Portfolio;

    /**
     * Deletes a portfolio (a POST with a form token). The posts in it are not touched.
     */
    class Delete extends Base
    {

        function getContent()
        {
            // Deleting is done with a POST from the portfolio's page
            $this->createGatekeeper();
            $this->forward();
        }

        function postContent()
        {
            $this->createGatekeeper();
            $portfolio = $this->editablePortfolio($this->arguments[0] ?? '');
            $owner = $portfolio->getOwner();
            $list = $owner instanceof \Idno\Entities\User ? Portfolio::listURL($owner) : '';

            if ($portfolio->delete()) {
                Idno::site()->session()->addMessage($this->language()->_('Your portfolio was deleted. The posts that were in it are still on your profile.'));
            } else {
                Idno::site()->session()->addErrorMessage($this->language()->_("We couldn't delete your portfolio."));
            }
            $this->forward($list);
        }

    }

}
