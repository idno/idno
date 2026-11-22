<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Custom CSS') ?></h1>
<p class="idno-admin-description">
    <?= \Idno\Core\Idno::site()->language()->_('The site styles CSS editor lets you easily modify the visual style of your Idno site by overriding the default CSS. With Custom CSS, you have more control over the fonts, colors, and visual impact of your site.') ?>
</p>

<form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/styles/" method="post" enctype="multipart/form-data">
    <div class="idno-admin-card">
        <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Stylesheet editor') ?></h2>

        <p><?= \Idno\Core\Idno::site()->language()->_("Add your changes to Idno's core CSS below.") ?></p>

        <div class="idno-form-group">
            <label style="display:inline-flex;align-items:center;gap:0.5rem;cursor:pointer;padding:0.4rem 0.8rem;border:1px solid var(--color-border,#d1d5db);border-radius:6px;font-size:0.875rem;">
                <span id="css-filename"><?= \Idno\Core\Idno::site()->language()->_('Upload a stylesheet') ?></span>
                <input type="file" name="import" accept="text/css" id="cssfileinput" style="display:none;">
            </label>
            <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_("Do you have an existing stylesheet that you'd like to use? Import a CSS file from your computer.") ?></p>
        </div>

        <div class="idno-form-group">
            <textarea name="css" class="idno-input" style="height:20em;font-family:'SF Mono','Fira Code',Courier,monospace;font-size:0.8125rem;resize:vertical;"><?= htmlspecialchars($vars['css']) ?></textarea>
        </div>

        <p style="font-size:0.875rem;">
            <?= \Idno\Core\Idno::site()->language()->_('You can also') ?>
            <a href="<?= \Idno\Core\Idno::site()->config()->url ?>styles/site/"><?= \Idno\Core\Idno::site()->language()->_('download your stylesheet') ?></a>
            <?= \Idno\Core\Idno::site()->language()->_('to work on it locally.') ?>
        </p>
    </div>

    <div class="idno-form-actions" style="margin-top:var(--spacing-section,1.5rem)">
        <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Save stylesheet') ?></button>
    </div>

    <?= \Idno\Core\Idno::site()->actions()->signForm(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/styles/') ?>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var fileInput = document.getElementById('cssfileinput');
    var filenameLabel = document.getElementById('css-filename');
    if (fileInput && filenameLabel) {
        fileInput.addEventListener('change', function() {
            filenameLabel.textContent = this.files.length ? this.files[0].name : '<?= \Idno\Core\Idno::site()->language()->_('Upload a stylesheet') ?>';
        });
    }
});
</script>
