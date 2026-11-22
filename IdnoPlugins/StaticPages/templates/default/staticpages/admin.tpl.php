<?php
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
    $categories = [];
?>

<div style="display:flex;justify-content:space-between;align-items:center;">
    <h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Pages') ?></h1>
    <a href="<?= $baseUrl ?>staticpages/edit/" class="idno-btn idno-btn-primary">
        + <?= \Idno\Core\Idno::site()->language()->_('Add new page') ?>
    </a>
</div>
<p class="idno-admin-description">
    <?= \Idno\Core\Idno::site()->language()->_('Pages are a great way to add content to your site that you want to keep separate from your stream of normal posts and updates. Common examples of pages include an about page, a contact page, or a resume.') ?>
</p>

<?php if (!empty($vars['pages'])) {
    foreach ($vars['pages'] as $category => $pages) {
        $categories[$category] = count($pages);
    }
    ?>

<div class="idno-admin-card">
    <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('All Pages') ?></h2>
    <table style="width:100%;border-collapse:collapse;">
        <thead>
            <tr style="border-bottom:2px solid var(--color-border,#e5e7eb);">
                <th style="width:30px;padding:0.5rem;"></th>
                <th style="text-align:left;padding:0.5rem;font-weight:600;"><?= \Idno\Core\Idno::site()->language()->_('Title') ?></th>
                <th style="text-align:left;padding:0.5rem;font-weight:600;"><?= \Idno\Core\Idno::site()->language()->_('Category') ?></th>
                <th style="text-align:right;padding:0.5rem;font-weight:600;"><?= \Idno\Core\Idno::site()->language()->_('Actions') ?></th>
            </tr>
        </thead>
        <?php foreach ($vars['pages'] as $category => $pages) {
            if (!empty($pages)) { ?>
        <tbody class="sortable-pages" data-category="<?= htmlspecialchars($category) ?>">
                <?php foreach ($pages as $page) { ?>
            <tr draggable="true" data-page-id="<?= $page->getID() ?>" style="border-bottom:1px solid var(--color-border,#e5e7eb);cursor:grab;"
                ondragstart="this.style.opacity='0.4'; event.dataTransfer.setData('text/plain', this.dataset.pageId); event.dataTransfer.effectAllowed='move';"
                ondragend="this.style.opacity='1';"
                ondragover="event.preventDefault(); this.style.borderTop='2px solid var(--color-primary,#2563eb)';"
                ondragleave="this.style.borderTop='';"
                ondrop="event.preventDefault(); this.style.borderTop=''; handlePageDrop(event, this);">
                <td style="padding:0.5rem;color:var(--color-muted,#9ca3af);cursor:grab;">⋮⋮</td>
                <td style="padding:0.5rem;">
                    <a href="<?= $page->getURL() ?>"><?= htmlspecialchars($page->getTitle()) ?></a>
                </td>
                <td style="padding:0.5rem;font-size:0.875rem;color:var(--color-muted,#6b7280);"><?= htmlspecialchars($category) ?></td>
                <td style="padding:0.5rem;text-align:right;font-size:0.875rem;white-space:nowrap;">
                    <?php if ($page->isHomepage()) {
                        echo \Idno\Core\Idno::site()->actions()->createLink(
                            $page->getClearHomepageURL(),
                            '🏠',
                            [],
                            ['method' => 'POST', 'title' => \Idno\Core\Idno::site()->language()->_('Remove as homepage'), 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure you want to remove this page from your homepage?')]
                        );
                    } else { ?>
                        <span style="opacity:0.3;">
                        <?php echo \Idno\Core\Idno::site()->actions()->createLink(
                            $page->getSetAsHomepageURL(),
                            '🏠',
                            [],
                            ['method' => 'POST', 'title' => \Idno\Core\Idno::site()->language()->_('Make homepage'), 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure you want to make this page your homepage?')]
                        ); ?>
                        </span>
                    <?php } ?>
                    <a href="<?= $baseUrl ?>staticpage/edit/<?= $page->_id ?>" style="margin-left:0.5rem;" title="<?= \Idno\Core\Idno::site()->language()->_('Edit page') ?>">
                        <?= $this->__(['icon' => 'pencil'])->draw('shell/icon') ?>
                    </a>
                    <span style="margin-left:0.5rem;color:var(--color-danger,#dc2626);">
                    <?= \Idno\Core\Idno::site()->actions()->createLink(
                        $page->getDeleteURL(),
                        $this->__(['icon' => 'trash-2'])->draw('shell/icon'),
                        [],
                        ['method' => 'POST', 'title' => \Idno\Core\Idno::site()->language()->_('Delete page'), 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure you want to permanently delete this page?')]
                    ) ?>
                    </span>
                </td>
            </tr>
                <?php } ?>
        </tbody>
            <?php }
        } ?>
    </table>
</div>

<?php } ?>

<!-- Categories Section -->
<div class="idno-admin-card" x-data="{ showAddCategory: false }">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Categories') ?></h2>
        <button type="button" @click="showAddCategory = !showAddCategory" class="idno-btn" style="font-size:0.875rem;">
            + <?= \Idno\Core\Idno::site()->language()->_('Add category') ?>
        </button>
    </div>
    <p style="font-size:0.875rem;color:var(--color-muted,#6b7280);">
        <?= \Idno\Core\Idno::site()->language()->_('If you plan on adding many pages, you may want to group them under categories. However, you don\'t have to assign a page to a category.') ?>
    </p>

    <div x-show="showAddCategory" x-cloak style="margin-top:0.75rem;">
        <form action="<?= $baseUrl ?>admin/staticpages/add/" method="post" style="display:flex;gap:0.5rem;align-items:start;">
            <input type="text" name="category" placeholder="<?= \Idno\Core\Idno::site()->language()->_('Name of category to add') ?>" class="idno-input" style="max-width:20rem;" required>
            <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Add') ?></button>
            <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/staticpages/add') ?>
        </form>
    </div>

    <?php if (!empty($categories)) { ?>
    <table style="width:100%;border-collapse:collapse;margin-top:1rem;">
        <thead>
            <tr style="border-bottom:2px solid var(--color-border,#e5e7eb);">
                <th style="width:30px;padding:0.5rem;"></th>
                <th style="text-align:left;padding:0.5rem;font-weight:600;"><?= \Idno\Core\Idno::site()->language()->_('Category Name') ?></th>
                <th style="text-align:left;padding:0.5rem;font-weight:600;"><?= \Idno\Core\Idno::site()->language()->_('Pages') ?></th>
                <th style="text-align:right;padding:0.5rem;font-weight:600;"><?= \Idno\Core\Idno::site()->language()->_('Actions') ?></th>
            </tr>
        </thead>
        <tbody id="sortable-categories">
            <?php foreach ($categories as $category => $count) {
                $uniqueId = md5($category . rand(0, 999));
                $isNoCategory = ($category === 'No Category');
                ?>
            <tr <?php
            if (!$isNoCategory) {
                ?>draggable="true" data-category="<?= htmlspecialchars($category) ?>"
                style="border-bottom:1px solid var(--color-border,#e5e7eb);cursor:grab;"
                ondragstart="this.style.opacity='0.4'; event.dataTransfer.setData('text/plain', this.dataset.category); event.dataTransfer.effectAllowed='move';"
                ondragend="this.style.opacity='1';"
                ondragover="event.preventDefault(); this.style.borderTop='2px solid var(--color-primary,#2563eb)';"
                ondragleave="this.style.borderTop='';"
                ondrop="event.preventDefault(); this.style.borderTop=''; handleCategoryDrop(event, this);"<?php
            } else {
                ?>style="border-bottom:1px solid var(--color-border,#e5e7eb);"<?php
            } ?>
                x-data="{ editing: false }">
                <td style="padding:0.5rem;color:var(--color-muted,#9ca3af);"><?php if (!$isNoCategory) echo '⋮⋮'; ?></td>
                <td style="padding:0.5rem;">
                    <span x-show="!editing"><?= htmlspecialchars($category) ?></span>
                    <?php if (!$isNoCategory) { ?>
                    <form x-show="editing" x-cloak action="<?= $baseUrl ?>admin/staticpages/edit/" method="post" style="display:flex;gap:0.5rem;">
                        <input type="text" name="new_category" value="<?= htmlspecialchars($category) ?>" class="idno-input" style="width:auto;">
                        <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                        <button type="submit" class="idno-btn idno-btn-primary" style="font-size:0.75rem;"><?= \Idno\Core\Idno::site()->language()->_('Save') ?></button>
                        <button type="button" @click="editing = false" class="idno-btn" style="font-size:0.75rem;"><?= \Idno\Core\Idno::site()->language()->_('Cancel') ?></button>
                        <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/staticpages/edit') ?>
                    </form>
                    <?php } ?>
                </td>
                <td style="padding:0.5rem;"><?= $count ?></td>
                <td style="padding:0.5rem;text-align:right;font-size:0.875rem;">
                    <?php if (!$isNoCategory) { ?>
                        <a href="#" @click.prevent="editing = true" style="margin-right:0.5rem;">
                            <?= $this->__(['icon' => 'pencil'])->draw('shell/icon') ?>
                        </a>
                        <span style="color:var(--color-danger,#dc2626);">
                        <?= \Idno\Core\Idno::site()->actions()->createLink(
                            $baseUrl . 'admin/staticpages/delete/',
                            $this->__(['icon' => 'trash-2'])->draw('shell/icon'),
                            ['category' => $category],
                            ['method' => 'POST', 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure you want to permanently delete this category?')]
                        ) ?>
                        </span>
                    <?php } ?>
                </td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
    <?php } ?>
</div>

<script>
function handlePageDrop(event, targetRow) {
    var draggedId = event.dataTransfer.getData('text/plain');
    var tbody = targetRow.closest('tbody');
    var draggedRow = tbody.querySelector('[data-page-id="' + draggedId + '"]');
    if (!draggedRow || draggedRow === targetRow) return;
    tbody.insertBefore(draggedRow, targetRow);
    var rows = tbody.querySelectorAll('[data-page-id]');
    var newIndex = Array.prototype.indexOf.call(rows, draggedRow);
    fetch('<?= $baseUrl ?>admin/staticpages/reorder/page', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ page: draggedId, position: newIndex })
    });
}

function handleCategoryDrop(event, targetRow) {
    var draggedCategory = event.dataTransfer.getData('text/plain');
    var tbody = targetRow.closest('tbody');
    var draggedRow = tbody.querySelector('[data-category="' + draggedCategory + '"]');
    if (!draggedRow || draggedRow === targetRow) return;
    tbody.insertBefore(draggedRow, targetRow);
    var rows = tbody.querySelectorAll('[data-category]');
    var newIndex = Array.prototype.indexOf.call(rows, draggedRow);
    fetch('<?= $baseUrl ?>admin/staticpages/reorder/', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ category: draggedCategory, position: newIndex })
    });
}
</script>
