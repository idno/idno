<?php

    /* @var $this \Idno\Core\Template */
    $xhr = false;

if (isset($vars['offset']) && !empty($vars['count'])) {

    if (empty($vars['items_per_page'])) {
        $items_per_page = \Idno\Core\Idno::site()->config()->items_per_page;
    } else {
        $items_per_page = $vars['items_per_page'];
    }
    $prev_offset = $vars['offset'] - $items_per_page;
    if ($prev_offset < 0) $prev_offset = 0;
    $next_offset = $vars['offset'] + $items_per_page;
    if ($next_offset > ($vars['count'] - 1)) $next_offset = $vars['count'] - 1;
    ?>

        <nav class="idno-pagination <?php

        if (!empty($vars['control-id']) && (!empty($vars['source-url']))) {
            echo "pager-xhr";
            $xhr = true;
        }

        ?>" data-count="<?= $vars['count'] ?>" data-limit="<?= $items_per_page ?>" data-offset="<?= $vars['offset'] ?>" data-control-id="<?= empty($vars['control-id']) ? '' : $vars['control-id'] ?>" data-source-url="<?= empty($vars['source-url']) ? '' : htmlspecialchars($vars['source-url']) ?>">
            <ul>
                <li class="newer <?php if ($vars['offset'] == 0) echo "pagination-disabled" ?>">
                    <a href="<?= !$xhr ? $this->getURLWithVar('offset', $prev_offset) : '#' ?>" rel="next">
                        <span>&laquo; <?= \Idno\Core\Idno::site()->language()->_('Newer') ?></span>
                    </a>
                </li>
                <li class="older <?php if ($vars['offset'] > $vars['count'] - $items_per_page) echo "pagination-disabled" ?>">
                    <a href="<?= !$xhr ? $this->getURLWithVar('offset', $next_offset) : '#' ?>" rel="prev">
                        <span><?= \Idno\Core\Idno::site()->language()->_('Older') ?> &raquo;</span>
                    </a>
                </li>
            </ul>
        </nav>

    <?php

}
