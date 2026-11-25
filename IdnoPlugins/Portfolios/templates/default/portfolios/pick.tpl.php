<?php

    /*
     * The picker: which of the member's portfolios one of their posts is in.
     * $vars['post'], $vars['portfolios'], $vars['return'], $vars['heading'].
     *
     * The plugin's script lifts the form out of this page into a dialog; everything the
     * dialog needs is inside the form.
     */

    /* @var \Idno\Common\Entity $post */
    $post = $vars['post'];
    $portfolios = is_array($vars['portfolios'] ?? null) ? $vars['portfolios'] : [];
    $language = \Idno\Core\Idno::site()->language();
    $site = \Idno\Core\Idno::site();
    $action = $site->config()->getDisplayURL() . 'portfolio/pick';
    $return = (string) ($vars['return'] ?? '');

    $about = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) ($post->getTitle() ?: $post->getShortDescription(12))), ENT_QUOTES, 'UTF-8')));
    $about = mb_strimwidth($about, 0, 90, '…', 'UTF-8');

?>
<div class="idno-portfolio-form-page">
    <form class="idno-portfolio-form idno-portfolio-picker" data-portfolio-picker action="<?php echo htmlspecialchars($action, ENT_QUOTES) ?>" method="post"
          data-saved-text="<?php echo htmlspecialchars($language->_('Your portfolios were updated'), ENT_QUOTES) ?>">
        <h1 class="idno-portfolios-title" id="portfolio-picker-title"><?php echo htmlspecialchars((string) $vars['heading'], ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($about !== '') { ?>
            <p class="idno-portfolio-picker-about"><?php echo htmlspecialchars($about, ENT_QUOTES, 'UTF-8') ?></p>
        <?php } ?>

        <input type="hidden" name="object" value="<?php echo htmlspecialchars((string) $post->getID(), ENT_QUOTES) ?>">
        <input type="hidden" name="return" value="<?php echo htmlspecialchars($return, ENT_QUOTES) ?>">

        <?php if (!empty($portfolios)) { ?>
            <fieldset class="idno-portfolio-picker-list">
                <legend class="idno-label"><?php echo $language->_('Your portfolios') ?></legend>
                <?php
                foreach ($portfolios as $portfolio) {
                    /* @var \IdnoPlugins\Portfolios\Portfolio $portfolio */
                    $in = $portfolio->hasItem($post->getUUID());
                    ?>
                    <label class="idno-portfolio-choice">
                        <input type="checkbox" name="portfolios[]" value="<?php echo htmlspecialchars((string) $portfolio->getID(), ENT_QUOTES) ?>"<?php echo $in ? ' checked' : '' ?>>
                        <span><?php echo htmlspecialchars((string) $portfolio->getTitle(), ENT_QUOTES, 'UTF-8') ?><?php
                        if ($portfolio->getPublishStatus() === 'draft') {
                            ?> <span class="idno-portfolio-badge"><?php echo $language->_('Draft') ?></span><?php
                        }
                        ?></span>
                    </label>
                    <?php
                }
                ?>
            </fieldset>
        <?php } else { ?>
            <p class="idno-portfolio-picker-none"><?php echo $language->_("You don't have a portfolio yet. Name one here and this post will be the first thing in it.") ?></p>
        <?php } ?>

        <div class="idno-portfolio-field form-group">
            <label class="idno-label" for="portfolio-new-name"><?php echo $language->_('New portfolio') ?></label>
            <input class="idno-input form-control" type="text" id="portfolio-new-name" name="new_name" maxlength="<?php echo \IdnoPlugins\Portfolios\Portfolio::MAX_NAME ?>"
                   placeholder="<?php echo htmlspecialchars($language->_('Name a new portfolio to add this post to'), ENT_QUOTES) ?>">
        </div>

        <?php echo $site->actions()->signForm($action) ?>
        <div class="idno-portfolio-actions">
            <button type="submit" class="idno-btn idno-btn-primary btn btn-primary"><?php echo $language->_('Save') ?></button>
            <a class="idno-btn idno-btn-ghost btn btn-default" data-portfolio-picker-cancel href="<?php echo htmlspecialchars(\Idno\Core\UrlValidator::httpUrl($return) ?: $post->getDisplayURL(), ENT_QUOTES) ?>"><?php echo $language->_('Cancel') ?></a>
        </div>
    </form>
</div>
