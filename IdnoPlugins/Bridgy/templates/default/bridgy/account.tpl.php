<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Social Interactions') ?></h1>
<p class="idno-admin-description">
    <a href="https://www.brid.gy"><?= \Idno\Core\Idno::site()->language()->_('Bridgy') ?></a>
    <?= \Idno\Core\Idno::site()->language()->_('is a service that pulls social interactions - such as likes and retweets - back to your website.') ?>
</p>
<p class="idno-admin-description">
    <?= \Idno\Core\Idno::site()->language()->_('If you send content from Idno to Facebook or Twitter, use Bridgy to save comments and interactions from those networks to the original post on your Idno site.') ?>
</p>

<div class="idno-admin-card">
    <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Twitter + Bridgy') ?></h2>

    <?php if ($vars['twitter_enabled']) { ?>
        <p><?= \Idno\Core\Idno::site()->language()->_('Bridgy is pulling in replies, favorites, and retweets from Twitter. Click to disable.') ?></p>
        <form action="https://www.brid.gy/delete/start" method="post" style="margin-top:1rem;">
            <input type="hidden" name="feature" value="listen">
            <input type="hidden" name="key" value="<?= $vars['twitter_key'] ?>">
            <input type="hidden" name="callback" value="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/bridgy/disabled/?service=twitter' ?>">
            <button type="submit" class="idno-btn" style="color:var(--color-danger,#dc2626);border-color:var(--color-danger,#dc2626);">
                <?= \Idno\Core\Idno::site()->language()->_('Disconnect Twitter + Bridgy') ?>
            </button>
        </form>
    <?php } else { ?>
        <p><?= \Idno\Core\Idno::site()->language()->_('Bridgy pulls in replies, favorites, and retweets from Twitter.') ?></p>
        <p><?= \Idno\Core\Idno::site()->language()->_('To get started, activate Bridgy for the social network.') ?></p>
        <form action="https://www.brid.gy/twitter/start" method="post" style="margin-top:1rem;">
            <input type="hidden" name="feature" value="listen">
            <input type="hidden" name="callback" value="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/bridgy/enabled/?service=twitter' ?>">
            <input type="hidden" name="user_url" value="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>">
            <button type="submit" class="idno-btn idno-btn-primary">
                <?= \Idno\Core\Idno::site()->language()->_('Activate Twitter + Bridgy') ?>
            </button>
        </form>
    <?php } ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    fetch('<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/bridgy/check/', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.changed) {
            window.location.reload();
        }
    })
    .catch(function() { /* silently ignore check failures */ });
});
</script>
