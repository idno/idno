<div x-data="{ open: false, query: '' }"
     x-show="open"
     x-cloak
     x-on:open-search.window="open = true; $nextTick(() => $refs.searchInput.focus())"
     x-on:keydown.escape.window="open = false"
     class="idno-modal-overlay">
    <div class="idno-modal" style="max-width:32rem" x-on:click.outside="open = false">
        <div class="idno-modal-header">
            <h3 class="idno-modal-title"><?= \Idno\Core\Idno::site()->language()->_('Search') ?></h3>
            <button type="button" class="idno-modal-close" x-on:click="open = false">
                <?= $this->__(['icon' => 'x', 'class' => 'idno-nav-icon'])->draw('shell/icon') ?>
            </button>
        </div>
        <div class="idno-modal-body">
            <form x-on:submit.prevent="if (query.trim()) window.location.href = '/?q=' + encodeURIComponent(query.trim())"
                  style="display:flex;gap:0.5rem">
                <input type="search" x-model="query" x-ref="searchInput"
                       class="idno-input" style="flex:1"
                       aria-label="<?= \Idno\Core\Idno::site()->language()->_('Search') ?>"
                       placeholder="<?= \Idno\Core\Idno::site()->language()->_('Search posts...') ?>">
                <button type="submit" class="idno-btn idno-btn-primary">
                    <?= \Idno\Core\Idno::site()->language()->_('Search') ?>
                </button>
            </form>
        </div>
    </div>
</div>
