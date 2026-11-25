<?php

    /*
     * One portfolio: its name, description and owner, then the posts in it that the viewer
     * may read, each drawn as it is in any list. An h-feed.
     *
     * $vars['portfolio'], $vars['portfolio_owner'], $vars['items'] (this page's posts),
     * $vars['count'] (all the posts the viewer may read), $vars['offset'].
     */

    /* @var \IdnoPlugins\Portfolios\Portfolio $portfolio */
    $portfolio = $vars['portfolio'];
    /* @var \Idno\Entities\User $member */
    $member = $vars['portfolio_owner'];
    $items = is_array($vars['items'] ?? null) ? $vars['items'] : [];
    $count = (int) ($vars['count'] ?? 0);
    $language = \Idno\Core\Idno::site()->language();
    $site = \Idno\Core\Idno::site();
    $canEdit = $portfolio->canEdit();
    $mine = $member->getUUID() === $site->session()->currentUserUUID();
    $draft = $portfolio->getPublishStatus() === 'draft';
    $description = $portfolio->getDescriptionText();
    $memberName = htmlspecialchars(strip_tags((string) $member->getTitle()), ENT_QUOTES, 'UTF-8');
    $all = $portfolio->getItemUUIDs();
    $first = reset($all);
    $last = end($all);

?>
<div class="idno-portfolio h-feed" data-portfolio="<?php echo htmlspecialchars((string) $portfolio->getID(), ENT_QUOTES) ?>" data-portfolio-status="<?php echo $draft ? 'draft' : 'published' ?>" data-portfolio-count="<?php echo $count ?>">
    <header class="idno-portfolio-header">
        <p class="idno-portfolio-crumb">
            <a href="<?php echo htmlspecialchars(\IdnoPlugins\Portfolios\Portfolio::listURL($member), ENT_QUOTES) ?>">&lsaquo; <?php
                echo $mine ? $language->_('Your portfolios') : htmlspecialchars($language->_("%s's portfolios", [strip_tags((string) $member->getTitle())]), ENT_QUOTES, 'UTF-8');
            ?></a>
        </p>
        <?php if ($draft) { ?>
            <p class="idno-portfolio-draft-note">
                <span class="idno-portfolio-badge"><?php echo $language->_('Draft') ?></span>
                <?php echo $mine ? $language->_('Only you can see this portfolio. Publish it to show it on your profile.') : $language->_('Only its owner and administrators can see this portfolio.') ?>
            </p>
        <?php } ?>
        <h1 class="idno-portfolio-name p-name"><?php echo htmlspecialchars((string) $portfolio->getTitle(), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($description !== '') { ?>
            <p class="idno-portfolio-description p-summary"><?php echo nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8')) ?></p>
        <?php } ?>
        <div class="idno-portfolio-meta">
            <a class="idno-portfolio-owner p-author h-card" href="<?php echo htmlspecialchars($member->getDisplayURL(), ENT_QUOTES) ?>">
                <img class="idno-portfolio-owner-photo u-photo" src="<?php echo htmlspecialchars((string) $member->getIcon(), ENT_QUOTES) ?>" alt="">
                <span class="p-name"><?php echo $memberName ?></span>
            </a>
            <span class="idno-portfolio-count"><?php echo htmlspecialchars($count === 1 ? $language->_('1 post') : $language->_('%d posts', [$count]), ENT_QUOTES) ?></span>
            <a class="u-url idno-portfolio-permalink" href="<?php echo htmlspecialchars($portfolio->getURL(), ENT_QUOTES) ?>" hidden><?php echo $language->_('Link') ?></a>
        </div>
        <?php if ($canEdit) { ?>
            <div class="idno-portfolio-actions idno-portfolio-owner-actions">
                <form action="<?php echo htmlspecialchars($portfolio->getEditURL(), ENT_QUOTES) ?>" method="post">
                    <input type="hidden" name="publish_status" value="<?php echo $draft ? 'published' : 'draft' ?>">
                    <?php echo $site->actions()->signForm($portfolio->getEditURL()) ?>
                    <button type="submit" class="idno-btn idno-btn-sm btn btn-sm <?php echo $draft ? 'idno-btn-primary btn-primary' : 'idno-btn-ghost btn-default' ?>"><?php echo $draft ? $language->_('Publish') : $language->_('Unpublish') ?></button>
                </form>
                <a class="idno-btn idno-btn-ghost idno-btn-sm btn btn-default btn-sm" href="<?php echo htmlspecialchars($portfolio->getEditURL(), ENT_QUOTES) ?>"><?php echo $language->_('Edit') ?></a>
                <form action="<?php echo htmlspecialchars($portfolio->getDeleteURL(), ENT_QUOTES) ?>" method="post"
                      data-portfolio-confirm="<?php echo htmlspecialchars($language->_('Delete this portfolio? The posts in it stay on your profile. This can\'t be undone.'), ENT_QUOTES) ?>">
                    <?php echo $site->actions()->signForm($portfolio->getDeleteURL()) ?>
                    <button type="submit" class="idno-btn idno-btn-ghost idno-btn-sm idno-portfolio-danger btn btn-default btn-sm"><?php echo $language->_('Delete') ?></button>
                </form>
            </div>
        <?php } ?>
    </header>

    <?php if (empty($items)) { ?>
        <?php if ($mine) { ?>
            <div class="idno-portfolio-empty" data-portfolio-empty="owner">
                <p class="idno-portfolio-empty-title"><?php echo $language->_('Nothing in this portfolio yet') ?></p>
                <p><?php echo $language->_('Open the menu (the three dots) on any of your posts and choose "Add to portfolio".') ?></p>
                <p><a class="idno-btn idno-btn-ghost btn btn-default" href="<?php echo htmlspecialchars($member->getDisplayURL(), ENT_QUOTES) ?>"><?php echo $language->_('Go to your posts') ?></a></p>
            </div>
        <?php } else { ?>
            <div class="idno-portfolio-empty" data-portfolio-empty="visitor">
                <p class="idno-portfolio-empty-title"><?php echo $language->_('Nothing here yet') ?></p>
                <p><?php echo $language->_('There are no posts in this portfolio that you can see.') ?></p>
            </div>
        <?php } ?>
    <?php } else { ?>
        <div class="idno-portfolio-items">
            <?php
            foreach ($items as $post) {
                if (!($post instanceof \Idno\Common\Entity)) {
                    continue;
                }
                ?>
                <div class="idno-portfolio-item">
                    <?php if ($canEdit) { ?>
                        <form class="idno-portfolio-item-controls" action="<?php echo htmlspecialchars($portfolio->getItemsURL(), ENT_QUOTES) ?>" method="post">
                            <input type="hidden" name="object" value="<?php echo htmlspecialchars($post->getUUID(), ENT_QUOTES) ?>">
                            <?php echo $site->actions()->signForm($portfolio->getItemsURL()) ?>
                            <button type="submit" name="action" value="up" class="idno-portfolio-item-button" title="<?php echo htmlspecialchars($language->_('Move up'), ENT_QUOTES) ?>" aria-label="<?php echo htmlspecialchars($language->_('Move up'), ENT_QUOTES) ?>"<?php echo $post->getUUID() === $first ? ' disabled' : '' ?>>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 7-7 7 7"/><path d="M12 19V5"/></svg>
                            </button>
                            <button type="submit" name="action" value="down" class="idno-portfolio-item-button" title="<?php echo htmlspecialchars($language->_('Move down'), ENT_QUOTES) ?>" aria-label="<?php echo htmlspecialchars($language->_('Move down'), ENT_QUOTES) ?>"<?php echo $post->getUUID() === $last ? ' disabled' : '' ?>>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>
                            </button>
                            <button type="submit" name="action" value="remove" class="idno-portfolio-item-button idno-portfolio-item-remove">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                <?php echo $language->_('Remove from portfolio') ?>
                            </button>
                        </form>
                    <?php } ?>
                    <?php echo $this->__(['object' => $post])->draw('entity/shell') ?>
                </div>
                <?php
            }
            unset($this->vars['object']);
            ?>
        </div>
        <?php echo $this->drawPagination($count) ?>
    <?php } ?>
</div>
