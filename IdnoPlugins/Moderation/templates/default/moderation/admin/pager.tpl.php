<?php

    /* Older / newer links for a list: 'base' (address with a query string), 'offset', 'per_page', 'total' */

    $language = \Idno\Core\Idno::site()->language();
    $offset = (int) ($vars['offset'] ?? 0);
    $per = (int) ($vars['per_page'] ?? 30);
    $total = (int) ($vars['total'] ?? 0);

if ($total > $per) {
    ?>
    <nav class="mod-pager" aria-label="<?php echo htmlspecialchars($language->_('Pages'), ENT_QUOTES) ?>">
        <?php if ($offset > 0) { ?>
<a class="mod-btn mod-btn-sm" href="<?php echo htmlspecialchars($vars['base'] . '&offset=' . max(0, $offset - $per), ENT_QUOTES) ?>"><?php echo $language->_('Newer') ?></a><?php
        } ?>
        <span class="mod-muted"><?php echo htmlspecialchars($language->_('%d–%d of %d', [$offset + 1, min($total, $offset + $per), $total])) ?></span>
        <?php if ($offset + $per < $total) { ?>
<a class="mod-btn mod-btn-sm" href="<?php echo htmlspecialchars($vars['base'] . '&offset=' . ($offset + $per), ENT_QUOTES) ?>"><?php echo $language->_('Older') ?></a><?php
        } ?>
    </nav>
    <?php
}
    unset($this->vars['base']);
