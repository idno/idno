<?php

    /*
     * One portfolio in a list: its name, the start of its description and how many posts
     * the viewer can see in it. $vars['portfolio'].
     */

    /* @var \IdnoPlugins\Portfolios\Portfolio $portfolio */
    $portfolio = $vars['portfolio'] ?? null;

if ($portfolio instanceof \IdnoPlugins\Portfolios\Portfolio) {
    $language = \Idno\Core\Idno::site()->language();
    $count = $portfolio->countVisibleItems();
    $description = trim((string) preg_replace('/\s+/u', ' ', $portfolio->getDescriptionText()));
    $draft = $portfolio->getPublishStatus() === 'draft';
    ?>
        <article class="idno-portfolio-card" data-portfolio="<?php echo htmlspecialchars((string) $portfolio->getID(), ENT_QUOTES) ?>" data-portfolio-count="<?php echo $count ?>">
            <a class="idno-portfolio-card-link" href="<?php echo htmlspecialchars($portfolio->getURL(), ENT_QUOTES) ?>">
                <span class="idno-portfolio-card-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/></svg>
                </span>
                <span class="idno-portfolio-card-text">
                    <span class="idno-portfolio-card-name"><?php echo htmlspecialchars((string) $portfolio->getTitle(), ENT_QUOTES, 'UTF-8') ?><?php
                    if ($draft) {
                        ?> <span class="idno-portfolio-badge"><?php echo $language->_('Draft') ?></span><?php
                    }
                    ?></span>
                    <?php if ($description !== '') { ?>
                        <span class="idno-portfolio-card-description"><?php echo htmlspecialchars(mb_strimwidth($description, 0, 160, '…', 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php } ?>
                    <span class="idno-portfolio-card-meta"><?php echo htmlspecialchars($count === 1 ? $language->_('1 post') : $language->_('%d posts', [$count]), ENT_QUOTES) ?><?php
                    if ($draft) {
                        echo ' &middot; ' . htmlspecialchars($language->_('only you can see it'), ENT_QUOTES);
                    }
                    ?></span>
                </span>
            </a>
        </article>
    <?php
}
