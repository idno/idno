<?php

    /*
     * The form that makes or changes a portfolio. $vars['portfolio'], $vars['heading'].
     */

    /* @var \IdnoPlugins\Portfolios\Portfolio $portfolio */
    $portfolio = $vars['portfolio'];
    $language = \Idno\Core\Idno::site()->language();
    $site = \Idno\Core\Idno::site();
    $new = !$portfolio->getID();
    // Not getTitle(): an entity with no title answers with a stand-in
    $name = is_string($portfolio->title) ? $portfolio->title : '';
    $action = $new ? $site->config()->getDisplayURL() . 'portfolio/edit' : $portfolio->getEditURL();
    $published = !$new && $portfolio->getPublishStatus() !== 'draft';
    $owner = $new ? $site->session()->currentUser() : $portfolio->getOwner();
    $cancel = $new ? ($owner ? \IdnoPlugins\Portfolios\Portfolio::listURL($owner) : $site->config()->getDisplayURL()) : $portfolio->getURL();

?>
<div class="idno-portfolio-form-page">
    <h1 class="idno-portfolios-title"><?php echo htmlspecialchars((string) $vars['heading'], ENT_QUOTES, 'UTF-8') ?></h1>
    <form class="idno-portfolio-form" action="<?php echo htmlspecialchars($action, ENT_QUOTES) ?>" method="post">
        <div class="idno-portfolio-field form-group">
            <label class="idno-label" for="portfolio-name"><?php echo $language->_('Name') ?></label>
            <input class="idno-input form-control" type="text" id="portfolio-name" name="name" required maxlength="<?php echo \IdnoPlugins\Portfolios\Portfolio::MAX_NAME ?>"
                   value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="<?php echo htmlspecialchars($language->_('My best reporting'), ENT_QUOTES) ?>"<?php echo $new ? ' autofocus' : '' ?>>
        </div>
        <div class="idno-portfolio-field form-group">
            <label class="idno-label" for="portfolio-description"><?php echo $language->_('Description') ?></label>
            <textarea class="idno-textarea form-control" id="portfolio-description" name="description" rows="4" maxlength="<?php echo \IdnoPlugins\Portfolios\Portfolio::MAX_DESCRIPTION ?>"
                      placeholder="<?php echo htmlspecialchars($language->_('What this collection is and why you chose these pieces (optional)'), ENT_QUOTES) ?>"><?php echo htmlspecialchars($portfolio->getDescriptionText(), ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
        <fieldset class="idno-portfolio-field idno-portfolio-status-field">
            <legend class="idno-label"><?php echo $language->_('Who can see it') ?></legend>
            <label class="idno-portfolio-choice">
                <input type="radio" name="publish_status" value="draft"<?php echo $published ? '' : ' checked' ?>>
                <span><strong><?php echo $language->_('Draft') ?></strong> <span class="idno-portfolio-choice-help"><?php echo $language->_('Only you can see it') ?></span></span>
            </label>
            <label class="idno-portfolio-choice">
                <input type="radio" name="publish_status" value="published"<?php echo $published ? ' checked' : '' ?>>
                <span><strong><?php echo $language->_('Published') ?></strong> <span class="idno-portfolio-choice-help"><?php echo $language->_('Anyone can see it, and it is listed on your profile') ?></span></span>
            </label>
        </fieldset>
        <?php echo $site->actions()->signForm($action) ?>
        <div class="idno-portfolio-actions">
            <button type="submit" class="idno-btn idno-btn-primary btn btn-primary"><?php echo $new ? $language->_('Create portfolio') : $language->_('Save') ?></button>
            <a class="idno-btn idno-btn-ghost btn btn-default" href="<?php echo htmlspecialchars($cancel, ENT_QUOTES) ?>"><?php echo $language->_('Cancel') ?></a>
        </div>
    </form>
</div>
