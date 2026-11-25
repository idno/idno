<?php

    /*
     * One case: what was reported, who reported it and why, the member's record, private
     * notes, the actions and the log. Administrators only.
     *
     * The reported text, the reporters' words, names and notes are all typed by people:
     * everything is escaped, and addresses go through UrlValidator::httpUrl().
     */

    $language = \Idno\Core\Idno::site()->language();
    /* @var \IdnoPlugins\Moderation\Entities\ModerationCase $case */
    $case = $vars['case'];
    $subject = $vars['subject'] ?? null;
    $snapshot = is_array($case->snapshot) ? $case->snapshot : [];
    $actionUrl = $case->getAdminURL() . '/action';
    $sign = \Idno\Core\Idno::site()->actions()->signForm($actionUrl);
    $base = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/moderation/';
    $outcomes = \IdnoPlugins\Moderation\Outcomes::labels();
    $who = (string) ($snapshot['author_name'] ?? '') !== '' ? (string) $snapshot['author_name'] : ((string) $case->subject_name !== '' ? (string) $case->subject_name : $language->_('someone'));
    $liveUrl = \Idno\Core\UrlValidator::httpUrl((string) ($snapshot['url'] ?? ''));
    $authorUrl = \Idno\Core\UrlValidator::httpUrl((string) ($snapshot['author_url'] ?? ''));
    $edited = $case->editedSince();
    $isContent = $case->target_type !== 'profile';
    $thing = $case->target_type === 'comment' ? $language->_('comment') : $language->_('post');
    $hidden = function ($action) use ($case, $sign) {
        return '<input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES) . '"><input type="hidden" name="revision" value="' . (int) $case->revision . '">' . $sign;
    };
    $reasons = is_array($case->reasons) ? $case->reasons : [];

?>
<article class="mod-case" aria-labelledby="mod-case-title">
    <header class="mod-case-head">
        <div>
            <h2 class="mod-case-title" id="mod-case-title"><?php
                $titles = ['post' => $language->get('Post by %s'), 'comment' => $language->get('Comment by %s'), 'profile' => $language->get('Profile: %s')];
                echo htmlspecialchars(sprintf($titles[$case->target_type] ?? '%s', $who));
            ?></h2>
            <p class="mod-case-meta">
                <?php foreach ($reasons as $slug => $count) { ?>
                <span class="mod-pill<?php if (\IdnoPlugins\Moderation\Reasons::isUrgent($slug)) echo ' mod-pill-urgent'; ?>"><?php echo htmlspecialchars(\IdnoPlugins\Moderation\Reasons::label($slug)) ?><?php if ($count > 1) echo ' × ' . (int) $count; ?></span>
                <?php } ?>
                <span class="mod-muted"><?php echo htmlspecialchars($language->_('First reported %s', [date('j M Y, H:i', (int) $case->first_reported)])) ?></span>
            </p>
        </div>
        <span class="mod-status<?php echo $case->isOpen() ? ' mod-status-open' : ''; ?>"><?php echo $case->isOpen() ? $language->_('Unresolved') : htmlspecialchars($outcomes[$case->outcome] ?? $language->_('Resolved')) ?></span>
    </header>

    <?php if (!$case->isOpen()) { ?>
    <p class="mod-resolution">
        <?php echo htmlspecialchars($language->_('%s by %s on %s.', [$outcomes[$case->outcome] ?? $language->_('Resolved'), (string) $case->resolved_by_name !== '' ? (string) $case->resolved_by_name : $language->_('a moderator'), date('j M Y, H:i', (int) $case->resolved_at)])) ?>
        <span class="mod-muted"><?php echo $language->_('Only moderators see who acted.') ?></span>
    </p>
    <?php } ?>
    <?php if ($subject && $subject->getUUID() === $vars['admin']->getUUID()) { ?>
    <p class="mod-alert" role="note"><?php echo $language->_('This report is about you. You are seeing it because you are the only administrator.') ?></p>
    <?php } ?>

    <h3 class="mod-section"><?php echo $language->_('What was reported') ?></h3>
    <div class="mod-card mod-snapshot">
        <p class="mod-snapshot-author">
            <?php if ($authorUrl !== '') { ?><a href="<?php echo htmlspecialchars($authorUrl, ENT_QUOTES) ?>"><strong><?php echo htmlspecialchars($who) ?></strong></a><?php } else { ?><strong><?php echo htmlspecialchars($who) ?></strong><?php } ?>
            <?php if (!empty($snapshot['time'])) { ?><span class="mod-muted"><?php echo htmlspecialchars(date('j M Y, H:i', (int) $snapshot['time'])) ?></span><?php } ?>
        </p>
        <?php if ((string) ($snapshot['title'] ?? '') !== '') { ?><p class="mod-snapshot-title"><?php echo htmlspecialchars((string) $snapshot['title']) ?></p><?php } ?>
        <?php if ((string) ($snapshot['text'] ?? '') !== '') { ?><div class="mod-snapshot-text"><?php echo nl2br(htmlspecialchars((string) $snapshot['text'])) ?></div><?php } else if ($case->target_type !== 'profile') { ?><p class="mod-muted"><?php echo $language->_('No text. Open the post to see what it holds.') ?></p><?php } ?>
        <p class="mod-snapshot-foot">
            <?php echo $language->_('Saved copy from when it was first reported.') ?>
            <?php if ($edited === null) { ?>
                <strong><?php echo $language->_('It no longer exists on the site.') ?></strong>
            <?php } else { ?>
                <?php if ($edited) { ?><strong><?php echo $language->_('It has been edited since.') ?></strong><?php } ?>
                <?php if ($liveUrl !== '') { ?><a href="<?php echo htmlspecialchars($liveUrl, ENT_QUOTES) ?>"><?php echo $case->target_type === 'profile' ? $language->_('Open the profile') : $language->_('Open it on the site') ?></a><?php } ?>
            <?php } ?>
            <?php if (!empty($case->content_deleted)) { ?><span class="mod-pill mod-pill-urgent"><?php echo $language->_('Deleted permanently') ?></span>
            <?php } else if (!empty($case->content_removed)) { ?><span class="mod-pill mod-pill-urgent"><?php echo $language->_('Removed') ?></span><?php } ?>
        </p>
    </div>

    <div class="mod-columns">
        <div>
            <h3 class="mod-section"><?php echo htmlspecialchars($language->_('Reports (%d)', [count($vars['reports'])])) ?></h3>
            <div class="mod-card">
                <ul class="mod-reports">
                    <?php foreach ($vars['reports'] as $report) {
                        $reporter = $report->getReporter();
                        $record = \IdnoPlugins\Moderation\Moderation::reporterRecord($report->reporter_uuid);
                        $entries = is_array($report->entries) && $report->entries ? $report->entries : [['time' => $report->created, 'reason' => $report->reason, 'description' => $report->description]];
                        ?>
                    <li class="mod-report-entry">
                        <p class="mod-report-who">
                            <?php if ($reporter) { ?><a href="<?php echo htmlspecialchars($base . 'member/' . $reporter->getID(), ENT_QUOTES) ?>"><strong><?php echo htmlspecialchars(strip_tags((string) $reporter->getTitle())) ?></strong></a>
                            <?php } else { ?><strong><?php echo htmlspecialchars((string) $report->reporter_name) ?></strong> <span class="mod-muted"><?php echo $language->_('(account deleted)') ?></span><?php } ?>
                            <span class="mod-muted"><?php echo htmlspecialchars($language->_('has filed %d reports, %d dismissed', [$record['filed'], $record['dismissed']])) ?></span>
                        </p>
                        <?php foreach ($entries as $entry) { ?>
                        <div class="mod-report-said">
                            <span class="mod-pill<?php if (\IdnoPlugins\Moderation\Reasons::isUrgent($entry['reason'] ?? '')) echo ' mod-pill-urgent'; ?>"><?php echo htmlspecialchars(\IdnoPlugins\Moderation\Reasons::label($entry['reason'] ?? '')) ?></span>
                            <span class="mod-muted"><?php echo htmlspecialchars(date('j M Y, H:i', (int) ($entry['time'] ?? 0))) ?></span>
                            <?php if ((string) ($entry['description'] ?? '') !== '') { ?>
                            <blockquote><?php echo nl2br(htmlspecialchars((string) $entry['description'])) ?></blockquote>
                            <?php } else { ?>
                            <p class="mod-muted"><?php echo $language->_('No context given.') ?></p>
                            <?php } ?>
                        </div>
                        <?php } ?>
                    </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
        <div>
            <h3 class="mod-section"><?php echo htmlspecialchars($language->_('About %s', [$who])) ?></h3>
            <div class="mod-card mod-about">
                <?php if ($subject) {
                    $earlier = $vars['history'];
                    $removed = count(array_filter($earlier, function ($other) { return !empty($other->content_removed) || !empty($other->content_deleted); }));
                    ?>
                    <p>
                        <?php echo htmlspecialchars($language->_('Member since %s', [date('M Y', (int) $subject->created)])) ?>
                        · <a href="<?php echo htmlspecialchars($base . 'member/' . $subject->getID(), ENT_QUOTES) ?>"><?php echo $language->_('Moderation record') ?></a>
                    </p>
                    <p><?php echo htmlspecialchars($language->_('Reported before: %d · Content removed: %d', [count($earlier), $removed])) ?></p>
                    <?php if ($subject->isBanned()) { ?><p><span class="mod-pill mod-pill-urgent"><?php echo $language->_('Banned') ?></span> <?php echo htmlspecialchars($language->_('since %s', [date('j M Y', (int) $subject->getBan()['time'])])) ?></p><?php } ?>
                    <?php if (!empty($subject->admin)) { ?><p><span class="mod-pill"><?php echo $language->_('Administrator') ?></span></p><?php } ?>
                <?php } else if ((string) $case->subject_uuid !== '') { ?>
                    <p class="mod-muted"><?php echo $language->_('This account no longer exists.') ?></p>
                <?php } else { ?>
                    <p class="mod-muted"><?php echo $language->_('Not a member of this site: the comment came from another site, so there is no account to ban.') ?></p>
                <?php } ?>
            </div>

            <h3 class="mod-section"><?php echo $language->_('Moderator notes') ?> <span class="mod-muted"><?php echo $language->_('(private)') ?></span></h3>
            <div class="mod-card">
                <?php foreach ((is_array($case->notes) ? $case->notes : []) as $note) { ?>
                <div class="mod-note">
                    <p class="mod-muted"><?php echo htmlspecialchars((string) ($note['by_name'] ?? '')) ?> · <?php echo htmlspecialchars(date('j M Y, H:i', (int) ($note['time'] ?? 0))) ?></p>
                    <p><?php echo nl2br(htmlspecialchars((string) ($note['text'] ?? ''))) ?></p>
                </div>
                <?php } ?>
                <form method="post" action="<?php echo htmlspecialchars($actionUrl, ENT_QUOTES) ?>" class="mod-note-form">
                    <label class="mod-sr" for="mod-note"><?php echo $language->_('Add a note for other moderators') ?></label>
                    <textarea id="mod-note" class="mod-textarea" name="note" rows="2" maxlength="<?php echo \IdnoPlugins\Moderation\Moderation::NOTE_LENGTH ?>" placeholder="<?php echo htmlspecialchars($language->_('Add a note for other moderators'), ENT_QUOTES) ?>" required></textarea>
                    <?php echo $hidden('note') ?>
                    <button type="submit" class="mod-btn mod-btn-sm"><?php echo $language->_('Add note') ?></button>
                </form>
            </div>
        </div>
    </div>

    <div class="mod-actions" role="group" aria-label="<?php echo htmlspecialchars($language->_('Actions'), ENT_QUOTES) ?>">
        <?php if ($case->isOpen()) { ?>
            <form method="post" action="<?php echo htmlspecialchars($actionUrl, ENT_QUOTES) ?>">
                <?php echo $hidden('dismiss') ?>
                <button type="submit" class="mod-btn"><?php echo $language->_('Dismiss') ?></button>
            </form>
            <?php if ($isContent && $edited !== null) { ?>
            <form method="post" action="<?php echo htmlspecialchars($actionUrl, ENT_QUOTES) ?>"
                  data-mod-confirm-title="<?php echo htmlspecialchars($language->_('Remove this %s?', [$thing]), ENT_QUOTES) ?>"
                  data-mod-confirm-text="<?php echo htmlspecialchars($case->target_type === 'post'
                      ? $language->_('It will be hidden from everyone except its author and moderators, and deleted from other servers that received it. %s gets an email with the reason. You can restore it later, but other servers may not show it again.', [$who])
                      : $language->_('It will disappear from the post. %s gets an email with the reason if they are a member here. You can restore it later.', [$who]), ENT_QUOTES) ?>"
                  data-mod-confirm-button="<?php echo htmlspecialchars($language->_('Remove %s', [$thing]), ENT_QUOTES) ?>">
                <?php echo $hidden('remove') ?>
                <button type="submit" class="mod-btn"><?php echo htmlspecialchars($language->_('Remove %s', [$thing])) ?></button>
            </form>
            <?php } ?>
            <?php if ($subject && !$subject->isBanned() && $subject->getUUID() !== $vars['admin']->getUUID()) { ?>
            <details class="mod-ban">
                <summary class="mod-btn mod-btn-danger"><?php echo htmlspecialchars($language->_('Ban %s…', [$who])) ?></summary>
                <form method="post" action="<?php echo htmlspecialchars($actionUrl, ENT_QUOTES) ?>" class="mod-card mod-ban-form">
                    <h3><?php echo htmlspecialchars($language->_('Ban %s?', [$who])) ?></h3>
                    <p><?php echo htmlspecialchars($language->_('%s will be signed out everywhere right away and will not be able to sign in or post. Their API key and connected apps stop working, and their profile stops being served to other servers.', [$who])) ?></p>
                    <?php if ($isContent && $edited !== null) { ?>
                    <label class="mod-check"><input type="checkbox" name="remove_content" value="1" checked> <span><strong><?php echo htmlspecialchars($language->_('Also remove this %s', [$thing])) ?></strong></span></label>
                    <?php } ?>
                    <label class="mod-check"><input type="checkbox" name="hide_all" value="1"> <span><strong><?php echo $language->_('Also hide everything they have posted') ?></strong><small><?php echo $language->_('Their profile and posts disappear for everyone but moderators. Lifting the ban brings them back.') ?></small></span></label>
                    <label class="mod-label" for="mod-ban-reason"><?php echo $language->_('Reason') ?> <span class="mod-muted"><?php echo $language->_('(only moderators see this)') ?></span></label>
                    <textarea id="mod-ban-reason" class="mod-textarea" name="ban_reason" rows="2" maxlength="<?php echo \IdnoPlugins\Moderation\Moderation::NOTE_LENGTH ?>"></textarea>
                    <label class="mod-check"><input type="checkbox" name="notify" value="1" checked> <span><?php echo $language->_('Email them to say their account was banned, with the reason category') ?></span></label>
                    <?php echo $hidden('ban') ?>
                    <div class="mod-ban-buttons">
                        <button type="submit" class="mod-btn mod-btn-danger"><?php echo htmlspecialchars($language->_('Ban %s', [$who])) ?></button>
                    </div>
                </form>
            </details>
            <?php } ?>
            <p class="mod-actions-hint"><?php echo $language->_('Reporters get an email saying their report was reviewed, without your name and without the details.') ?></p>
        <?php } else { ?>
            <?php if ($case->outcome === 'dismissed') { ?>
            <form method="post" action="<?php echo htmlspecialchars($actionUrl, ENT_QUOTES) ?>">
                <?php echo $hidden('reopen') ?>
                <button type="submit" class="mod-btn"><?php echo $language->_('Reopen') ?></button>
            </form>
            <?php } ?>
            <?php if (!empty($case->content_removed) && empty($case->content_deleted)) { ?>
            <form method="post" action="<?php echo htmlspecialchars($actionUrl, ENT_QUOTES) ?>">
                <?php echo $hidden('restore') ?>
                <button type="submit" class="mod-btn"><?php echo htmlspecialchars($language->_('Restore %s', [$thing])) ?></button>
            </form>
            <form method="post" action="<?php echo htmlspecialchars($actionUrl, ENT_QUOTES) ?>"
                  data-mod-confirm-title="<?php echo htmlspecialchars($language->_('Delete this %s permanently?', [$thing]), ENT_QUOTES) ?>"
                  data-mod-confirm-text="<?php echo htmlspecialchars($language->_("It will be gone for good and can't be restored. The saved copy stays with this report."), ENT_QUOTES) ?>"
                  data-mod-confirm-button="<?php echo htmlspecialchars($language->_('Delete permanently'), ENT_QUOTES) ?>">
                <?php echo $hidden('delete') ?>
                <button type="submit" class="mod-btn mod-btn-danger-quiet"><?php echo $language->_('Delete permanently') ?></button>
            </form>
            <?php } ?>
            <?php if ($subject && $subject->isBanned() && !empty($case->banned_here)) { ?>
            <form method="post" action="<?php echo htmlspecialchars($actionUrl, ENT_QUOTES) ?>"
                  data-mod-confirm-title="<?php echo htmlspecialchars($language->_('Lift the ban on %s?', [$who]), ENT_QUOTES) ?>"
                  data-mod-confirm-text="<?php echo htmlspecialchars($language->_('They will be able to sign in and post again, and any posts the ban hid will come back. They get an email saying so.'), ENT_QUOTES) ?>"
                  data-mod-confirm-button="<?php echo htmlspecialchars($language->_('Lift ban'), ENT_QUOTES) ?>">
                <?php echo $hidden('unban') ?>
                <button type="submit" class="mod-btn"><?php echo $language->_('Lift ban') ?></button>
            </form>
            <?php } ?>
        <?php } ?>
    </div>

    <h3 class="mod-section"><?php echo $language->_('History') ?></h3>
    <?php echo $this->__(['entries' => array_reverse($vars['case_log']), 'links' => false])->draw('moderation/admin/log'); ?>
</article>
