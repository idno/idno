<?php

    /*
     * The moderation queue. With a case open, the list and the case are side by side on a
     * wide screen; on a narrow one only the case is shown, with a link back to the list.
     *
     * Names, excerpts and reasons come from members: everything is escaped.
     */

    $language = \Idno\Core\Idno::site()->language();
    $base = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/moderation/';
    $tab = $vars['tab'];
    $case = $vars['case'] ?? null;
    $kinds = ['post' => $language->get('Post by %s'), 'comment' => $language->get('Comment by %s'), 'profile' => $language->get('Profile: %s')];
    $outcomes = \IdnoPlugins\Moderation\Outcomes::labels();
    $ago = function ($time) use ($language) {
        $seconds = max(0, time() - (int) $time);
        if ($seconds < 3600) {
            return $language->_('%dm', [max(1, (int) floor($seconds / 60))]);
        }
        if ($seconds < 86400) {
            return $language->_('%dh', [(int) floor($seconds / 3600)]);
        }

        return $language->_('%dd', [(int) floor($seconds / 86400)]);
    };

    echo $this->__(['tab' => $tab])->draw('moderation/admin/header');

?>
<div class="mod-queue<?php if ($case) echo ' mod-queue-has-case'; ?>">

    <?php if ($tab === 'unresolved' || $tab === 'resolved') { ?>
    <section class="mod-list" aria-label="<?php echo htmlspecialchars($tab === 'unresolved' ? $language->_('Unresolved reports') : $language->_('Resolved reports'), ENT_QUOTES) ?>">
        <?php if (empty($vars['cases'])) { ?>
            <div class="mod-empty">
                <?php if ($tab === 'unresolved') { ?>
                <strong><?php echo $language->_('Nothing waiting.') ?></strong>
                <span><?php echo $language->_('New reports from members will show up here.') ?></span>
                <?php } else { ?>
                <strong><?php echo $language->_('Nothing resolved yet.') ?></strong>
                <span><?php echo $language->_('Reports you dismiss or act on move here.') ?></span>
                <?php } ?>
            </div>
        <?php } else { ?>
        <ul class="mod-items">
            <?php foreach ($vars['cases'] as $item) {
                $snapshot = is_array($item->snapshot) ? $item->snapshot : [];
                $who = (string) ($snapshot['author_name'] ?? '') !== '' ? (string) $snapshot['author_name'] : (string) $item->subject_name;
                $excerpt = trim((string) ($item->target_type === 'profile' ? ($snapshot['title'] ?? '') : (($snapshot['text'] ?? '') ?: ($snapshot['title'] ?? ''))));
                $excerpt = mb_strlen($excerpt) > 90 ? mb_substr($excerpt, 0, 90) . '…' : $excerpt;
                $current = $case && $case->getID() === $item->getID();
                $reasons = is_array($item->reasons) ? $item->reasons : [];
                ?>
            <li>
                <a class="mod-item<?php if ($current) echo ' mod-item-current'; ?>" href="<?php echo htmlspecialchars($item->getAdminURL(), ENT_QUOTES) ?>" <?php if ($current) echo 'aria-current="true"'; ?>>
                    <span class="mod-item-top">
                        <span class="mod-item-title"><?php echo htmlspecialchars(sprintf($kinds[$item->target_type] ?? '%s', $who !== '' ? $who : $language->_('someone'))) ?></span>
                        <span class="mod-item-age"><?php echo htmlspecialchars($ago($item->isOpen() ? $item->first_reported : $item->resolved_at)) ?></span>
                    </span>
                    <?php if ($excerpt !== '') { ?><span class="mod-item-excerpt"><?php echo htmlspecialchars($excerpt) ?></span><?php } ?>
                    <span class="mod-item-pills">
                        <?php foreach (array_keys($reasons) as $slug) { ?>
                        <span class="mod-pill<?php if (\IdnoPlugins\Moderation\Reasons::isUrgent($slug)) echo ' mod-pill-urgent'; ?>"><?php echo htmlspecialchars(\IdnoPlugins\Moderation\Reasons::label($slug)) ?></span>
                        <?php } ?>
                        <span class="mod-pill mod-pill-quiet"><?php echo (int) $item->report_count === 1 ? $language->_('1 report') : htmlspecialchars($language->_('%d reports', [(int) $item->report_count])) ?></span>
                        <?php if (!$item->isOpen()) { ?><span class="mod-pill mod-pill-outcome"><?php echo htmlspecialchars($outcomes[$item->outcome] ?? (string) $item->outcome) ?></span><?php } ?>
                    </span>
                </a>
            </li>
            <?php } ?>
        </ul>
        <?php echo $this->__(['base' => $base . '?tab=' . $tab])->draw('moderation/admin/pager'); ?>
        <?php } ?>
    </section>

    <section class="mod-detail" <?php if ($case) echo 'id="case"'; ?>>
        <?php if ($case) { ?>
            <a class="mod-back" href="<?php echo htmlspecialchars($base . ($tab === 'resolved' ? '?tab=resolved' : ''), ENT_QUOTES) ?>">&larr; <?php echo $tab === 'resolved' ? $language->_('Resolved reports') : $language->_('Unresolved reports') ?></a>
            <?php echo $this->draw('moderation/admin/case'); ?>
        <?php } else if (!empty($vars['cases'])) { ?>
            <div class="mod-empty mod-empty-detail">
                <strong><?php echo $language->_('Choose a report') ?></strong>
                <span><?php echo $language->_('Pick one from the list to see what was reported and decide what to do.') ?></span>
            </div>
        <?php } ?>
    </section>
    <?php } ?>

    <?php if ($tab === 'banned') { ?>
    <section class="mod-wide">
        <?php if (empty($vars['banned'])) { ?>
            <div class="mod-empty">
                <strong><?php echo $language->_('Nobody is banned.') ?></strong>
                <span><?php echo $language->_('Members you ban will be listed here, and you can lift a ban at any time.') ?></span>
            </div>
        <?php } else { ?>
        <div class="mod-card mod-table-card">
        <table class="mod-table">
            <thead><tr><th scope="col"><?php echo $language->_('Member') ?></th><th scope="col"><?php echo $language->_('Banned') ?></th><th scope="col"><?php echo $language->_('Reason') ?></th><th scope="col"><span class="mod-sr"><?php echo $language->_('Actions') ?></span></th></tr></thead>
            <tbody>
            <?php foreach ($vars['banned'] as $member) {
                $ban = $member->getBan();
                $by = !empty($ban['by']) ? \Idno\Entities\User::getByUUID($ban['by']) : null;
                $memberUrl = $base . 'member/' . $member->getID();
                ?>
                <tr>
                    <td>
                        <a class="mod-member" href="<?php echo htmlspecialchars($memberUrl, ENT_QUOTES) ?>">
                            <img class="mod-avatar" src="<?php echo htmlspecialchars(\Idno\Core\UrlValidator::httpUrl((string) $member->getIcon()), ENT_QUOTES) ?>" alt="">
                            <span><strong><?php echo htmlspecialchars(strip_tags((string) $member->getTitle())) ?></strong><small>@<?php echo htmlspecialchars((string) $member->getHandle()) ?><?php if ($member->isHiddenByBan()) echo ' · ' . $language->_('posts hidden'); ?></small></span>
                        </a>
                    </td>
                    <td><?php echo htmlspecialchars(date('j M Y', (int) $ban['time'])) ?><?php if ($by) { ?><small><?php echo htmlspecialchars($language->_('by %s', [strip_tags((string) $by->getTitle())])) ?></small><?php } ?></td>
                    <td><?php echo (string) $ban['reason'] !== '' ? nl2br(htmlspecialchars((string) $ban['reason'])) : '<span class="mod-muted">' . $language->_('No reason recorded') . '</span>' ?></td>
                    <td class="mod-table-actions">
                        <form method="post" action="<?php echo htmlspecialchars($memberUrl, ENT_QUOTES) ?>"
                              data-mod-confirm-title="<?php echo htmlspecialchars($language->_('Lift the ban on %s?', [strip_tags((string) $member->getTitle())]), ENT_QUOTES) ?>"
                              data-mod-confirm-text="<?php echo htmlspecialchars($language->_('They will be able to sign in and post again, and any posts the ban hid will come back. They get an email saying so.'), ENT_QUOTES) ?>"
                              data-mod-confirm-button="<?php echo htmlspecialchars($language->_('Lift ban'), ENT_QUOTES) ?>">
                            <input type="hidden" name="action" value="unban">
                            <input type="hidden" name="back" value="banned">
                            <?php echo \Idno\Core\Idno::site()->actions()->signForm($memberUrl) ?>
                            <button type="submit" class="mod-btn mod-btn-sm"><?php echo $language->_('Lift ban') ?></button>
                        </form>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
        <?php echo $this->__(['base' => $base . '?tab=banned'])->draw('moderation/admin/pager'); ?>
        <?php } ?>
    </section>
    <?php } ?>

    <?php if ($tab === 'log') { ?>
    <section class="mod-wide">
        <?php if (empty($vars['entries'])) { ?>
            <div class="mod-empty">
                <strong><?php echo $language->_('Nothing has happened yet.') ?></strong>
                <span><?php echo $language->_('Every report and every moderator action is recorded here. Only administrators can see it.') ?></span>
            </div>
        <?php } else { ?>
        <div class="mod-card">
            <?php echo $this->__(['entries' => $vars['entries'], 'links' => true])->draw('moderation/admin/log'); ?>
        </div>
        <?php echo $this->__(['base' => $base . '?tab=log'])->draw('moderation/admin/pager'); ?>
        <?php } ?>
    </section>
    <?php } ?>
</div>
