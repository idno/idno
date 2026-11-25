<?php

namespace IdnoPlugins\Portfolios\Pages {

    use Idno\Core\Idno;
    use IdnoPlugins\Portfolios\Portfolio;

    /**
     * A member's portfolios: /profile/<handle>/portfolios. The member (and administrators)
     * see all of them, anyone else the published ones.
     */
    class Index extends Base
    {

        function getContent()
        {
            $member = $this->memberOrNotFound($this->arguments[0] ?? '');
            $this->member = $member;
            $this->setOwner($member);

            $portfolios = Portfolio::forOwner($member);
            $mine = $member->getUUID() === Idno::site()->session()->currentUserUUID();
            $title = $mine
                ? $this->language()->_('Your portfolios')
                : $this->language()->_("%s's portfolios", [strip_tags((string) $member->getTitle())]);

            $t = Idno::site()->template();
            $t->__([
                'title' => $title,
                'description' => $this->language()->_('Portfolios by %s', [strip_tags((string) $member->getTitle())]),
                'body' => $t->__(['portfolio_owner' => $member, 'portfolios' => $portfolios, 'heading' => $title])->draw('portfolios/list'),
            ])->drawPage();
        }

    }

}
