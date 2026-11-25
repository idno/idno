<?php

    /*
     * A member's portfolios. $vars['portfolio_owner'], $vars['portfolios'] (those the viewer
     * may see), $vars['heading'].
     */

    /* @var \Idno\Entities\User $member */
    $member = $vars['portfolio_owner'];
    $portfolios = is_array($vars['portfolios'] ?? null) ? $vars['portfolios'] : [];
    $language = \Idno\Core\Idno::site()->language();
    $site = \Idno\Core\Idno::site();
    $mine = $member->getUUID() === $site->session()->currentUserUUID();
    $newUrl = $site->config()->getDisplayURL() . 'portfolio/edit';
    $memberName = htmlspecialchars(strip_tags((string) $member->getTitle()), ENT_QUOTES, 'UTF-8');

?>
<div class="idno-portfolios">
    <header class="idno-portfolios-header">
        <div class="idno-portfolios-heading">
            <a class="idno-portfolio-owner h-card" href="<?php echo htmlspecialchars($member->getDisplayURL(), ENT_QUOTES) ?>">
                <img class="idno-portfolio-owner-photo u-photo" src="<?php echo htmlspecialchars((string) $member->getIcon(), ENT_QUOTES) ?>" alt="">
                <span class="p-name"><?php echo $memberName ?></span>
            </a>
            <h1 class="idno-portfolios-title"><?php echo htmlspecialchars((string) $vars['heading'], ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
        <?php if ($mine && $site->canWrite()) { ?>
            <a class="idno-btn idno-btn-primary btn btn-primary" href="<?php echo htmlspecialchars($newUrl, ENT_QUOTES) ?>"><?php echo $language->_('New portfolio') ?></a>
        <?php } ?>
    </header>

    <?php if (empty($portfolios)) { ?>
        <?php if ($mine) { ?>
            <div class="idno-portfolio-empty" data-portfolios-empty="owner">
                <p class="idno-portfolio-empty-title"><?php echo $language->_("You don't have any portfolios yet") ?></p>
                <p><?php echo $language->_('A portfolio is a collection of your posts that you choose and put in order: your best videos, a series of articles, the work from one project. Make one, then add posts to it from the menu on each of your posts.') ?></p>
                <p><a class="idno-btn idno-btn-primary btn btn-primary" href="<?php echo htmlspecialchars($newUrl, ENT_QUOTES) ?>"><?php echo $language->_('Make your first portfolio') ?></a></p>
            </div>
        <?php } else { ?>
            <div class="idno-portfolio-empty" data-portfolios-empty="visitor">
                <p class="idno-portfolio-empty-title"><?php echo $language->_('No portfolios yet') ?></p>
                <p><?php echo htmlspecialchars($language->_("%s hasn't published a portfolio.", [strip_tags((string) $member->getTitle())]), ENT_QUOTES, 'UTF-8') ?>
                    <a href="<?php echo htmlspecialchars($member->getDisplayURL(), ENT_QUOTES) ?>"><?php echo $language->_('See their profile') ?></a></p>
            </div>
        <?php } ?>
    <?php } else { ?>
        <div class="idno-portfolio-grid">
            <?php
            foreach ($portfolios as $portfolio) {
                echo $this->__(['portfolio' => $portfolio])->draw('portfolios/card');
            }
            unset($this->vars['portfolio']);
            ?>
        </div>
    <?php } ?>
</div>
