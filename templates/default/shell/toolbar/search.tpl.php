<?php

    $currentPage = \Idno\Core\Idno::site()->currentPage();
    $action = \Idno\Core\Idno::site()->config()->getDisplayURL();
if (!empty($vars['content'])) {
    if (!is_array($vars['content'])) {
        $vars['content'] = array($vars['content']);
    }
    $action .= 'content/' . implode('/', $vars['content']);
} else {
    $action .= 'content/all/';
}

?>
<form class="navbar-form navbar-left" action="<?php echo $action?>" method="get" role="search">
    <div class="form-group">
        <input type="search" class="search-query form-control" name="q" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Search'); ?>" tabindex="2" value="<?php

        if (!empty($currentPage)) {
            if ($q = $currentPage->getTextInput('q')) {
                echo htmlspecialchars($q);
            }
        }

        ?>">
    </div>
</form>
