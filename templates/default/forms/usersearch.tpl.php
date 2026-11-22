<?php
if (empty($vars['name'])) {
    $vars['name'] = 'user-search';
}

global $input_id;
if (!isset($vars['id'])) {
    $input_id ++;
    $vars['id'] = $vars['name'] . "_$input_id";
}

if (empty($vars['source-url'])) {
    $vars['source-url'] = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'search/users/';
}

if (empty($vars['render-template'])) {
    $vars['render-template'] = 'forms/components/usersearch/user';
}
?>
<div id="<?= $vars['id'] ?>" class="users-search <?php if (!empty($vars['class'])) echo $vars['class']; ?>"
     x-data="userSearch('<?= $vars['source-url'] ?>', '<?= $vars['render-template'] ?>')"
     x-init="search()">
    <form @submit.prevent="search()" x-ref="form">
        <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
            <input name="query" type="text" class="idno-input" style="flex: 1;"
                   placeholder="<?= \Idno\Core\Idno::site()->language()->_('Search by name, email address, or username') ?>"
                   @change="search()"
                   x-model="query">
            <button type="submit" class="idno-btn-primary">
                <?= $this->__(['icon' => 'search'])->draw('shell/icon') ?>
            </button>
            <input type="hidden" name="template" value="<?= $vars['render-template'] ?>">
            <input type="hidden" name="sort" value="created">
            <input type="hidden" name="order" value="desc">
            <input type="hidden" name="offset" x-bind:value="offset">
            <input type="hidden" name="limit" value="100">
        </div>
    </form>

    <div class="results pane" x-html="resultsHtml"></div>

    <div class="pager" style="display: flex; gap: 1rem; margin-top: 0.75rem;">
        <span :class="{ 'pagination-disabled': offset <= 0 }">
            <a href="#" @click.prevent="prevPage()" style="font-size: var(--font-size-sm); color: var(--color-text-secondary); text-decoration: none;">&laquo; <?= \Idno\Core\Idno::site()->language()->_('Prev') ?></a>
        </span>
        <span :class="{ 'pagination-disabled': offset + limit >= totalCount }">
            <a href="#" @click.prevent="nextPage()" style="font-size: var(--font-size-sm); color: var(--color-text-secondary); text-decoration: none;"><?= \Idno\Core\Idno::site()->language()->_('Next') ?> &raquo;</a>
        </span>
    </div>
</div>
<?php
foreach (['source-url', 'control-id', 'render-template', 'name', 'id'] as $variable) {
    unset($this->vars[$variable]);
}
?>

<script>
    document.addEventListener('alpine:init', function() {
        Alpine.data('userSearch', function(sourceUrl, renderTemplate) {
            return {
                query: '',
                offset: 0,
                limit: 100,
                totalCount: 0,
                resultsHtml: '',

                search() {
                    var self = this;
                    var params = new URLSearchParams({
                        query: this.query,
                        template: renderTemplate,
                        sort: 'created',
                        order: 'desc',
                        offset: this.offset,
                        limit: this.limit
                    });
                    fetch(sourceUrl + '?' + params.toString())
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        self.totalCount = data.count || 0;
                        self.resultsHtml = data.rendered || '';
                    });
                },

                prevPage() {
                    if (this.offset > 0) {
                        this.offset = Math.max(0, this.offset - this.limit);
                        this.search();
                    }
                },

                nextPage() {
                    if (this.offset + this.limit < this.totalCount) {
                        this.offset += this.limit;
                        this.search();
                    }
                }
            };
        });
    });
</script>
