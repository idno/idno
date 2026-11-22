<?php
    use Idno\Core\Idno;
    $baseURL = Idno::site()->config()->getDisplayURL();
    $user = Idno::site()->session()->currentUser();
?>
<h1 class="idno-admin-page-title"><?= Idno::site()->language()->_('Micropub Accounts') ?></h1>
<p class="idno-admin-description">
    <?= Idno::site()->language()->_('Manage third-party apps authorized to post to your site via Micropub.') ?>
</p>

<?php if (empty($user->indieauth_tokens)) { ?>
<div class="idno-admin-card">
    <p><?= Idno::site()->language()->_('There are currently no micropub accounts associated with this site.') ?></p>
</div>
<?php } else { ?>
    <?php foreach ((array)$user->indieauth_tokens as $token => $details) { ?>
    <div class="idno-admin-card">
        <h2 class="idno-admin-card-title">
            <a href="<?= htmlspecialchars($details['client_id']) ?>" target="_blank"><?= htmlspecialchars($details['client_id']) ?></a>
        </h2>
        <p style="font-size:0.875rem;color:var(--color-muted,#6b7280);">
            <?= Idno::site()->language()->_('Authorized') ?>
            <strong><?= date('Y-m-d', $details['issued_at']) ?></strong>
            <?= Idno::site()->language()->_('with the scope') ?>
            <strong><?= htmlspecialchars($details['scope']) ?></strong>
        </p>
        <p style="font-size:0.875rem;color:var(--color-muted,#6b7280);margin-top:0.25rem;">
            <?= Idno::site()->language()->_('Redirect URI') ?>: <?= htmlspecialchars($details['redirect_uri']) ?>
        </p>
        <form action="<?= $baseURL ?>account/indiepub/revoke" method="POST" style="margin-top:0.75rem;">
            <input name="token" type="hidden" value="<?= htmlspecialchars($token) ?>">
            <button type="submit" class="idno-btn" style="color:var(--color-danger,#dc2626);border-color:var(--color-danger,#dc2626);"
                    onclick="return confirm('<?= Idno::site()->language()->_('Are you sure you want to revoke this token?') ?>');">
                <?= Idno::site()->language()->_('Revoke Access') ?>
            </button>
            <?= Idno::site()->actions()->signForm('account/indiepub/revoke') ?>
        </form>
    </div>
    <?php } ?>
<?php } ?>

<div x-data="{ showAddForm: false }">
    <p style="margin-top:1rem;">
        <a href="#" @click.prevent="showAddForm = !showAddForm" style="font-size:0.875rem;">
            <?= Idno::site()->language()->_('Add Micropub Account') ?>
        </a>
    </p>

    <div class="idno-admin-card" x-show="showAddForm" x-cloak style="margin-top:0.75rem;">
        <h2 class="idno-admin-card-title"><?= Idno::site()->language()->_('Add Micropub Account') ?></h2>
        <p><?= Idno::site()->language()->_('To manually add a micropub client account and generate an API token, enter the details below.') ?></p>

        <form action="<?= $baseURL ?>account/indiepub/add" method="post" style="margin-top:0.75rem;">
            <div class="idno-form-group">
                <label class="idno-label" for="indiepub-client-id"><?= Idno::site()->language()->_('Client ID') ?></label>
                <input type="text" id="indiepub-client-id" name="client_id" class="idno-input" required>
            </div>
            <div class="idno-form-group">
                <label class="idno-label" for="indiepub-redirect-uri"><?= Idno::site()->language()->_('Redirect URI') ?></label>
                <input type="text" id="indiepub-redirect-uri" name="redirect_uri" class="idno-input" required>
            </div>
            <div class="idno-form-actions">
                <button type="submit" class="idno-btn idno-btn-primary"><?= Idno::site()->language()->_('Save') ?></button>
            </div>
            <?= Idno::site()->actions()->signForm('account/indiepub/add') ?>
        </form>
    </div>
</div>
