<style>
    .home-icon {
        display: block;
        width: 100%;
        height: 100%;
        text-align: center;
    }

    .home-icon:hover .no-hover,
    .home-icon .hover {
        display: none;
    }

    .home-icon:hover .hover,
    .home-icon .no-hover {
        display: inline;
    }
    .home-icon:hover {
        text-decoration: none;
    }
</style>

<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('admin/menu') ?>
        <h1>
            <?php echo \Idno\Core\Idno::site()->language()->_('Pages'); ?>
        </h1>

        <p class="explanation">
            <?php echo \Idno\Core\Idno::site()->language()->_('Pages are a great way to add content to your site that you want to keep separate from your stream of normal posts and updates.  Common examples of pages include an about page, a contact page, or a resume.'); ?>
        </p>

    </div>
</div>

<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <p class="pages">
            <a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>staticpages/edit/"
               class="btn btn-primary btn-add"><?php echo \Idno\Core\Idno::site()->language()->_('Add new page'); ?></a>
        </p>
    </div>
</div>
<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <?php

        if (!empty($vars['pages'])) {

            ?>
                <table style="width: 100%; margin-bottom: 3em">
                    <thead>
                        <tr class="pages">
                            <td class="pages" width="30%"><?php echo \Idno\Core\Idno::site()->language()->_('Title'); ?></td>
                            <td class="pages" width="30%"><?php echo \Idno\Core\Idno::site()->language()->_('Category'); ?></td>
                            <td class="pages" width="10%">&nbsp;</td>
                            <td class="pages" width="15%">&nbsp;</td>
                            <td class="pages" width="15%">&nbsp;</td>
                        </tr>
                    </thead>
                    <?php

                    $categories = [];
                    foreach ($vars['pages'] as $category => $pages) {

                        $categories[$category] = count($pages);


                        if (!empty($pages)) {
                            ?>
                                <tbody class="sortable-pages" data-value="<?php echo $category ?>">
                                    <?php
                                    foreach ($pages as $page) {

                                        ?>
                                        <tr class="items" data-value="<?php echo $page->getID() ?>">
                                            <td>
                                                <a href="<?php echo $page->getURL() ?>"><?php echo htmlspecialchars($page->getTitle()) ?></a>
                                            </td>
                                            <td>
                                                <?php echo $category ?>
                                            </td>

                                            <td>
                                                <?php
                                                if ($page->isHomepage()) {

                                                    echo  \Idno\Core\Idno::site()->actions()->createLink($page->getClearHomepageURL(), '<icon class="fa fa-home no-hover"></icon><icon class="fa fa-times hover"></icon>', array(), array('method' => 'POST', 'class' => 'home-icon', 'title' => \Idno\Core\Idno::site()->language()->_('Remove as homepage'), 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure you want to remove this page from your homepage?')));

                                                } else {

                                                    echo  \Idno\Core\Idno::site()->actions()->createLink($page->getSetAsHomepageURL(), '<icon class="fa fa-home hover"></icon><div class="no-hover">&nbsp</div>', array(), array('method' => 'POST', 'class' => 'home-icon', 'title' => \Idno\Core\Idno::site()->language()->_('Make homepage'), 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure you want to make this page your homepage?')));

                                                }

                                                ?>
                                            </td>
                                            <td>
                                                <a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>staticpage/edit/<?php echo $page->_id ?>" title="<?php echo \Idno\Core\Idno::site()->language()->_('Edit page'); ?>"><icon class="fa fa-pencil"></icon><?php echo \Idno\Core\Idno::site()->language()->_('Edit'); ?></a>
                                            </td>
                                            <td>
                                                <?php echo  \Idno\Core\Idno::site()->actions()->createLink($page->getDeleteURL(), '<icon class="fa fa-trash-o"></icon>' . \Idno\Core\Idno::site()->language()->_('Delete'), array(), array('method' => 'POST', 'class' => 'edit', 'title' => \Idno\Core\Idno::site()->language()->_('Delete page'), 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure you want to permanently delete this page?')));?>
                                            </td>
                                        </tr>
                                        <?php

                                    }

                                    ?>
                                </tbody>
                            <?php

                        }

                    }

                    ?>
                </table>
            <?php


        }

        ?>
    </div>
</div>
<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <h2>
            <?php echo \Idno\Core\Idno::site()->language()->_('Categories'); ?>
        </h2>

        <p class="explanation">
            <?php echo \Idno\Core\Idno::site()->language()->_('If you plan on adding many pages, you may want to group them under categories.  However, you don’t have to assign a page to a category.'); ?>
        </p>
    </div>
</div>
<div class="row">
    <div class="col-md-10 col-md-offset-1">

        <p class="pages" id="add-category-button">
            <a href="#" class="btn btn-primary btn-add" onclick="$('#add-category-button').hide(); $('#add-category').show(); return false;"><?php echo \Idno\Core\Idno::site()->language()->_('Add new category'); ?></a>
        </p>
    </div>
</div>
<div class="row">
    <div class="col-md-10 col-md-offset-1">

        <div id="add-category" style="display:none">

            <form class="form-inline" action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL(); ?>admin/staticpages/add/" method="post">
                <input id="pages-add" class="form-control" type="text" name="category" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Name of category to add'); ?>">
                <input type="submit" class="btn btn-primary btn-page" value="<?php echo \Idno\Core\Idno::site()->language()->_('Add'); ?>">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/admin/staticpages/add') ?>
            </form>

        </div>

        <?php

        if (!empty($categories)) {

            ?>
                <table style="width: 100%; margin-bottom: 3em">
                    <thead>
                        <tr class="pages">
                            <td width="35%"><?php echo \Idno\Core\Idno::site()->language()->_('Category Name'); ?></td>
                            <td width="35%"><?php echo \Idno\Core\Idno::site()->language()->_('Count'); ?></td>
                            <td width="15%">&nbsp;</td>
                            <td width="15%">&nbsp;</td>
                        </tr>
                    </thead><tbody class="sortable-categories">
                    <?php

                    foreach ($categories as $category => $count) {

                        $unique_id = md5($category . rand(0, 999));

                        ?>
                            <tr class="items <?php
                            if ($category == 'No Category') {
                                echo 'pages-no-category';
                            } ?>" <?php
if ($category != 'No Category') {
    echo ' data-value="'.$category.'"';
} ?>>
                                <td>
                                    <div id="category-name-<?php echo $unique_id?>"><?php echo $category ?></div>
                                    <div id="edit-category-<?php echo $unique_id?>" style="display: none">
                                        <form class="form-inline" action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/staticpages/edit/" method="post">
                                            <input class="form-control" type="text" name="new_category" value="<?php echo htmlspecialchars($category)?>">
                                            <input type="submit" value="Save" class="btn btn-primary btn-page">
                                            <input class="form-control" type="hidden" name="category" value="<?php echo htmlspecialchars($category)?>">
                                            <?php echo \Idno\Core\Idno::site()->actions()->signForm('/admin/staticpages/edit') ?>
                                        </form>
                                    </div>
                                </td>
                                <td>
                                    <?php echo $count ?>
                                </td>
                                <td>
                                    <?php

                                    if ($category != 'No Category') {

                                        ?>
                                            <i class="fa fa-pencil"></i>
                                            <a href="#" onclick="$('#category-name-<?php echo $unique_id?>').hide(); $('#edit-category-<?php echo $unique_id?>').show(); return false;"><?php echo \Idno\Core\Idno::site()->language()->_('Edit'); ?></a>
                                        <?php

                                    }

                                    ?>
                                </td>
                                <td>
                                    <?php

                                    if ($category != 'No Category') {

                                        ?><i class="fa fa-trash-o"></i>
                                        <?php echo  \Idno\Core\Idno::site()->actions()->createLink(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/staticpages/delete/', \Idno\Core\Idno::site()->language()->_('Delete'), array('category' => $category), array('method' => 'POST', 'class' => 'edit', 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure you want to permanently delete this category?')));?>
                                        <?php

                                    }

                                    ?>
                                </td>
                            </tr>
                        <?php

                    }

                    ?></tbody>
                </table>
            <?php

        }

        ?>
        <script type="text/javascript" src="<?php echo \Idno\Core\Idno::site()->config()->getStaticURL() ?>IdnoPlugins/StaticPages/external/html5sortable/html.sortable.min.js"></script>
        <script type="text/javascript">
            $('.sortable-categories').sortable({
                items: '[data-value]',
                placeholder: '<tr style="border:1px dotted #999;"><td colspan="4">&nbsp;</td></tr>'
            }).bind('sortstop', function (evt, ui) {
                $.post("<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/staticpages/reorder/", {
                    category: ui.item.data('value'),
                    position: $('.sortable-categories [data-value]').index(ui.item)
                });
                var pageGroups = $('.sortable-pages');
                var container = pageGroups.parent();
                pageGroups.detach();
                container.append(pageGroups.filter('[data-value="No Category"]'));
                $('#sortable-categories [data-value]').each(function () {
                    container.append(pageGroups.filter('[data-value="'+$(this).data('value')+'"]'));
                });
            });
            $('.sortable-pages').sortable({
                items: '[data-value]',
                placeholder: '<tr style="border:1px dotted #999;"><td colspan="4">&nbsp;</td></tr>'
            }).bind('sortstop', function (evt, ui) {
                $.post("<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/staticpages/reorder/page", {
                    page: ui.item.data('value'),
                    position: ui.item.parent().children('[data-value]').index(ui.item)
                });
            });
        </script>
    </div>

</div>
