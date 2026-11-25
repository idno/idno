<?php

namespace IdnoPlugins\Portfolios\Pages {

    use Idno\Core\Idno;
    use IdnoPlugins\Portfolios\Portfolio;

    /**
     * One portfolio: /profile/<handle>/portfolio/<slug or id>. A draft, a portfolio of
     * another member and one that does not exist all answer with the site's "not found" page.
     */
    class View extends Base
    {

        function getContent()
        {
            $member = $this->memberOrNotFound($this->arguments[0] ?? '');
            $portfolio = Portfolio::findFor($member, rawurldecode((string) ($this->arguments[1] ?? '')));
            if (!$portfolio) {
                $this->noContent();
            }
            $this->member = $member;
            $this->setOwner($member);
            // The page of the portfolio: the posts on it are drawn as items of a list
            $this->setPermalink(true, $portfolio);

            $visible = $portfolio->getVisibleItems();
            $count = count($visible);
            $perPage = max(1, (int) Idno::site()->config()->items_per_page);
            $offset = max(0, (int) $this->getInput('offset'));
            $items = array_slice($visible, $offset, $perPage);

            $this->setLastModifiedHeader((int) $portfolio->updated);

            $description = trim((string) preg_replace('/\s+/u', ' ', $portfolio->getDescriptionText()));
            if ($description === '') {
                $description = $this->language()->_('A portfolio by %s', [strip_tags((string) $member->getTitle())]);
            }

            $t = Idno::site()->template();
            $t->__([
                'title' => (string) $portfolio->getTitle(),
                'description' => mb_strimwidth($description, 0, 300, '…', 'UTF-8'),
                'body' => $t->__([
                    'portfolio' => $portfolio,
                    'portfolio_owner' => $member,
                    'items' => $items,
                    'count' => $count,
                    'offset' => $offset,
                ])->draw('portfolios/view'),
            ])->drawPage();
        }

    }

}
