<?php
if (empty($vars['user']) && !empty($vars['object'])) {
    $vars['user'] = $vars['object'];
}
$user = $vars['user'];
?>
<div class="idno-profile h-card">
    <div class="idno-profile-header">
        <a href="<?= $user->getDisplayURL() ?>" class="u-url">
            <img class="idno-profile-avatar u-photo" src="<?= $user->getIcon() ?>"
                 alt="<?= htmlspecialchars($user->getTitle()) ?>" />
        </a>
        <div>
            <h1 class="idno-profile-name">
                <a href="<?= $user->getDisplayURL() ?>" class="u-url p-name">
                    <?= htmlentities(strip_tags($user->getTitle()), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </h1>
            <?php if ($user->canEdit() && $user->getUUID() == \Idno\Core\Idno::site()->session()->currentUserUUID()) { ?>
                <a href="<?= $user->getEditURL() ?>" class="idno-btn idno-btn-ghost idno-btn-sm">
                    <?= \Idno\Core\Idno::site()->language()->_('Edit profile') ?>
                </a>
            <?php } ?>
        </div>
    </div>
    <?php
    $description = $user->getDescription();
    if (!empty($description)) {
    ?>
        <div class="idno-profile-bio e-note">
            <?= $this->__(['value' => $description])->draw('forms/output/richtext') ?>
        </div>
    <?php } else if ($user->getUUID() == \Idno\Core\Idno::site()->session()->currentUserUUID()) { ?>
        <div class="idno-profile-bio">
            <p style="color:var(--color-text-muted)">
                <?= \Idno\Core\Idno::site()->language()->_("You haven't described yourself yet.") ?>
                <a href="<?= $user->getDisplayURL() ?>/edit/"><?= \Idno\Core\Idno::site()->language()->_('Fill in your profile.') ?></a>
            </p>
        </div>
    <?php } ?>
    <?= $this->draw('entity/User/profile/fields') ?>
</div>
