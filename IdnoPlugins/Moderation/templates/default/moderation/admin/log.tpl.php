<?php

    /*
     * Lines of the moderation log: 'entries' (LogEntry[]), 'links' (whether to link each line
     * to its case). Administrators only: the lines name who acted.
     */

    $language = \Idno\Core\Idno::site()->language();
    $verbs = [
        'report' => $language->get('%s reported this'),
        'dismiss' => $language->get('%s dismissed the report'),
        'remove' => $language->get('%s removed the content'),
        'ban' => $language->get('%s banned the member'),
        'reopen' => $language->get('%s reopened the report'),
        'restore' => $language->get('%s restored the content'),
        'delete' => $language->get('%s deleted the content permanently'),
        'unban' => $language->get('%s lifted the ban'),
        'note' => $language->get('%s added a note'),
    ];

    ?>
<ol class="mod-log">
    <?php foreach ($vars['entries'] as $entry) {
        $actor = (string) $entry->actor_name !== '' ? (string) $entry->actor_name : $language->_('Someone');
        ?>
    <li>
        <time datetime="<?php echo date(DATE_ATOM, (int) $entry->created) ?>"><?php echo htmlspecialchars(date('j M Y, H:i', (int) $entry->created)) ?></time>
        <span>
            <?php echo htmlspecialchars(sprintf($verbs[$entry->action] ?? '%s: ' . (string) $entry->action, $actor)) ?><?php
            if (!empty($vars['links']) && (string) $entry->subject_name !== '') {
                echo ' · ' . htmlspecialchars((string) $entry->subject_name);
            }
            if ((string) $entry->detail !== '') {
                echo ' <span class="mod-muted">(' . htmlspecialchars((string) $entry->detail) . ')</span>';
            }
            if (!empty($vars['links']) && !empty($entry->case_id) && preg_match('/^[A-Za-z0-9-]+$/', (string) $entry->case_id)) {
                echo ' <a href="' . htmlspecialchars(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/moderation/case/' . $entry->case_id, ENT_QUOTES) . '">' . $language->_('View report') . '</a>';
            }
            ?>
        </span>
    </li>
    <?php } ?>
</ol>
<?php
    unset($this->vars['entries'], $this->vars['links']);
