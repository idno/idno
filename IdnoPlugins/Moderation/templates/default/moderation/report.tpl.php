<?php

    /*
     * The report form. On its own page the two steps are shown one under the other; the
     * script (moderation.js) shows the same form in a dialog, one step at a time.
     *
     * Everything about the reported thing comes from members and is escaped here.
     */

    $language = \Idno\Core\Idno::site()->language();
    $target = $vars['target'];
    $snapshot = $target['snapshot'];
    $action = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'moderation/report';
    $name = (string) $snapshot['author_name'] !== '' ? (string) $snapshot['author_name'] : $language->_('This person');
    $excerpt = trim(($target['type'] === 'profile' ? '' : (string) $snapshot['title'] . "\n") . (string) $snapshot['text']);
if (mb_strlen($excerpt) > 240) {
    $excerpt = mb_substr($excerpt, 0, 240) . '…';
}
    $limit = \IdnoPlugins\Moderation\Moderation::DESCRIPTION_LENGTH;
    $back = \Idno\Core\UrlValidator::httpUrl((string) $snapshot['url']);

?>
<div class="mod-report-page">
<form class="mod-report" method="post" action="<?php echo htmlspecialchars($action, ENT_QUOTES) ?>" data-mod-report-form
      data-done-title="<?php echo htmlspecialchars($language->_("Thanks. We've got it."), ENT_QUOTES) ?>"
      data-done-text="<?php echo htmlspecialchars($language->_("A moderator will review your report. We'll email you when it has been looked at. We won't share your name with the person you reported."), ENT_QUOTES) ?>"
      data-done-emergency="<?php echo htmlspecialchars($language->_('If someone is in immediate danger, contact your local emergency services.'), ENT_QUOTES) ?>"
      data-done-button="<?php echo htmlspecialchars($language->_('Done'), ENT_QUOTES) ?>"
      data-back-label="<?php echo htmlspecialchars($language->_('Back'), ENT_QUOTES) ?>"
      data-close-label="<?php echo htmlspecialchars($language->_('Close'), ENT_QUOTES) ?>"
      data-error-text="<?php echo htmlspecialchars($language->_("Something went wrong and your report wasn't sent. Please try again."), ENT_QUOTES) ?>">

    <h1 class="mod-report-title" data-mod-report-title><?php echo htmlspecialchars((string) $vars['heading']) ?></h1>

    <?php if ((string) $vars['error'] !== '') { ?>
    <p class="mod-alert" role="alert"><?php echo htmlspecialchars((string) $vars['error']) ?></p>
    <?php } ?>
    <p class="mod-alert" role="alert" data-mod-report-error hidden></p>

    <?php if (empty($vars['own'])) { ?>

    <div class="mod-report-step" data-mod-step="1">
        <fieldset class="mod-reasons">
            <legend><span class="mod-step-label"><?php echo $language->_('Step 1 of 2') ?></span> <?php echo $language->_("What's going on?") ?></legend>
            <?php foreach (\IdnoPlugins\Moderation\Reasons::all() as $slug => $reason) { ?>
            <label class="mod-reason">
                <input type="radio" name="reason" value="<?php echo htmlspecialchars($slug, ENT_QUOTES) ?>" required <?php if ($vars['reason'] === $slug) echo 'checked'; ?>>
                <span class="mod-reason-text">
                    <strong><?php echo htmlspecialchars($reason['label']) ?></strong>
                    <small><?php echo htmlspecialchars($reason['hint']) ?></small>
                </span>
            </label>
            <?php } ?>
        </fieldset>
    </div>

    <div class="mod-report-step" data-mod-step="2">
        <p class="mod-step-heading"><span class="mod-step-label"><?php echo $language->_('Step 2 of 2') ?></span> <span data-mod-chosen-reason><?php echo $language->_('Add context') ?></span></p>

        <blockquote class="mod-quote">
            <strong><?php echo htmlspecialchars($name) ?></strong>
            <?php if ($excerpt !== '') { ?><span><?php echo nl2br(htmlspecialchars($excerpt)) ?></span><?php } ?>
        </blockquote>

        <label class="mod-label" for="mod-report-description"><?php echo $language->_('Anything the moderators should know?') ?> <span class="mod-muted"><?php echo $language->_('(optional)') ?></span></label>
        <textarea id="mod-report-description" class="mod-textarea" name="description" rows="4" maxlength="<?php echo $limit ?>"
                  aria-describedby="mod-report-counter mod-report-privacy"
                  placeholder="<?php echo htmlspecialchars($language->_('What happened, and where to look.'), ENT_QUOTES) ?>"><?php echo htmlspecialchars((string) $vars['description']) ?></textarea>
        <p class="mod-counter" id="mod-report-counter"><span data-mod-count>0</span> / <?php echo $limit ?></p>

        <p class="mod-privacy" id="mod-report-privacy">
            <?php echo $language->_("Your report goes to this site's moderators.") ?>
            <strong><?php echo htmlspecialchars($language->_("%s won't be told who reported them.", [$name])) ?></strong>
        </p>
    </div>

    <input type="hidden" name="type" value="<?php echo htmlspecialchars((string) $target['type'], ENT_QUOTES) ?>">
    <input type="hidden" name="id" value="<?php echo htmlspecialchars((string) $vars['id'], ENT_QUOTES) ?>">
    <input type="hidden" name="comment" value="<?php echo htmlspecialchars((string) $vars['comment'], ENT_QUOTES) ?>">
    <?php echo \Idno\Core\Idno::site()->actions()->signForm($action) ?>

    <div class="mod-report-actions">
        <?php if ($back !== '') { ?><a class="mod-btn mod-btn-ghost" data-mod-cancel href="<?php echo htmlspecialchars($back, ENT_QUOTES) ?>"><?php echo $language->_('Cancel') ?></a><?php } ?>
        <button type="submit" class="mod-btn mod-btn-primary"><?php echo $language->_('Send report') ?></button>
    </div>

    <?php } else if ($back !== '') { ?>
    <div class="mod-report-actions">
        <a class="mod-btn mod-btn-ghost" data-mod-cancel href="<?php echo htmlspecialchars($back, ENT_QUOTES) ?>"><?php echo $language->_('Back') ?></a>
    </div>
    <?php } ?>
</form>
</div>
