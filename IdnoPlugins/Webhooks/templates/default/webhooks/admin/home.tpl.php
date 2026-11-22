<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Webhooks') ?></h1>
<p class="idno-admin-description">
    <?= \Idno\Core\Idno::site()->language()->_('Webhooks let you syndicate content to external applications very simply. The content of your post is sent to an external URL. Services like Slack, Wufoo, and Mailchimp all use Webhooks.') ?>
</p>

<div class="idno-admin-card">
    <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('About Webhooks') ?></h2>
    <p><?= \Idno\Core\Idno::site()->language()->_('When content is syndicated via Webhooks, the external URL is sent the following data') ?>:</p>
    <ul style="margin-top:0.5rem;padding-left:1.25rem;font-size:0.875rem;">
        <li><strong><?= \Idno\Core\Idno::site()->language()->_('text') ?></strong>: <?= \Idno\Core\Idno::site()->language()->_('the text of the update') ?></li>
        <li><strong><?= \Idno\Core\Idno::site()->language()->_('username') ?></strong>: <?= \Idno\Core\Idno::site()->language()->_('the username of the account-holder') ?></li>
        <li><strong>icon_url</strong>: <?= \Idno\Core\Idno::site()->language()->_("the URL of the user's icon") ?></li>
        <li><strong>content_type</strong>: <?= \Idno\Core\Idno::site()->language()->_('the type of content being sent') ?></li>
    </ul>
</div>

<?php
    // Prepare initial webhooks data for Alpine
    $existingWebhooks = [];
if (!empty(\Idno\Core\Idno::site()->config()->webhook_syndication)) {
    foreach (\Idno\Core\Idno::site()->config()->webhook_syndication as $webhook) {
        if (!empty($webhook['title']) || !empty($webhook['url'])) {
            $existingWebhooks[] = [
                'title' => $webhook['title'] ?? '',
                'url' => $webhook['url'] ?? ''
            ];
        }
    }
}
    // Always have at least one empty row
if (empty($existingWebhooks)) {
    $existingWebhooks[] = ['title' => '', 'url' => ''];
}
?>

<div class="idno-admin-card" x-data="{ webhooks: <?= htmlspecialchars(json_encode($existingWebhooks), ENT_QUOTES) ?> }">
    <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Your Webhooks') ?></h2>

    <form action="" method="post">
        <template x-for="(wh, index) in webhooks" :key="index">
            <div style="display:flex;gap:0.5rem;align-items:start;margin-bottom:0.75rem;">
                <div style="flex:1;">
                    <input type="text" :name="'titles[]'" x-model="wh.title"
                           placeholder="<?= \Idno\Core\Idno::site()->language()->_('Name of this webhook') ?>"
                           class="idno-input" style="width:100%;">
                </div>
                <div style="flex:2;">
                    <input type="text" :name="'webhooks[]'" x-model="wh.url"
                           placeholder="<?= \Idno\Core\Idno::site()->language()->_('Webhook URL') ?>"
                           class="idno-input" style="width:100%;">
                </div>
                <button type="button" @click="webhooks.splice(index, 1)" class="idno-btn"
                        style="color:var(--color-danger,#dc2626);border-color:var(--color-danger,#dc2626);flex-shrink:0;"
                        x-show="webhooks.length > 1">
                    ✕
                </button>
            </div>
        </template>

        <p style="margin-top:0.5rem;">
            <a href="#" @click.prevent="webhooks.push({title:'', url:''})" style="font-size:0.875rem;">
                + <?= \Idno\Core\Idno::site()->language()->_('Add another Webhook') ?>
            </a>
        </p>

        <div class="idno-form-actions" style="margin-top:1rem;">
            <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/webhooks/') ?>
            <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Save Webhooks') ?></button>
        </div>
    </form>
</div>
