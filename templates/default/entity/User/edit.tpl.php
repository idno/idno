<?php
    /* @var \Idno\Core\Template $this */

    // Build URLs array from user profile data
    if (!empty($vars['user']->profile['url'])) {
        $urls = is_array($vars['user']->profile['url'])
            ? $vars['user']->profile['url']
            : [$vars['user']->profile['url']];
        $urls = array_values(array_filter(array_map(function($u) {
            return !empty($u) ? $this->fixURL($u) : '';
        }, $urls)));
    }
    if (empty($urls)) {
        $urls = [''];
    }

    // Pre-escape icon URL for safe Alpine.js embedding
    $icon_url = htmlspecialchars(json_encode($vars['user']->getIcon()), ENT_QUOTES, 'UTF-8');
?>

<form action="<?php echo $vars['user']->getDisplayURL(); ?>" method="post" enctype="multipart/form-data">

    <div class="idno-editor">

        <h4 class="idno-editor-heading"><?php echo \Idno\Core\Idno::site()->language()->_('Edit your profile'); ?></h4>

        <!-- Avatar section -->
        <div class="idno-form-field" x-data="{ preview: null, icon: <?php echo $icon_url; ?> }" x-cloak>
            <div style="display:flex;align-items:center;gap:1rem">
                <img :src="preview || icon"
                     alt="<?php echo \Idno\Core\Idno::site()->language()->_('Profile photo'); ?>"
                     style="width:80px;height:80px;border-radius:50%;object-fit:cover">
                <div>
                    <label for="avatar" class="idno-btn idno-btn-ghost" style="cursor:pointer;display:inline-flex;align-items:center;gap:0.375rem">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
                        <?php echo \Idno\Core\Idno::site()->language()->_('Change photo'); ?>
                    </label>
                    <input type="file" name="avatar" id="avatar" accept="image/*" style="display:none"
                           @change="if ($event.target.files[0]) { const r = new FileReader(); r.onload = e => preview = e.target.result; r.readAsDataURL($event.target.files[0]); }">
                    <p class="idno-form-help"><?php echo \Idno\Core\Idno::site()->language()->_('JPG, PNG or GIF'); ?></p>
                </div>
            </div>
        </div>

        <!-- Name field -->
        <div class="idno-form-field">
            <label class="idno-label" for="name"><?php echo \Idno\Core\Idno::site()->language()->_('Your name'); ?></label>
            <input class="idno-input" type="text" id="name" name="name"
                   value="<?php echo htmlspecialchars($vars['user']->getTitle()); ?>">
        </div>

        <!-- Bio field -->
        <div class="idno-form-field">
            <label class="idno-label" for="body"><?php echo \Idno\Core\Idno::site()->language()->_('About you'); ?></label>
            <textarea class="idno-textarea" name="profile[description]" id="body"
                      placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Tell people about yourself...'); ?>"
            ><?php echo htmlspecialchars($vars['user']->getDescription()); ?></textarea>
        </div>

        <!-- Websites section -->
        <div class="idno-form-field" x-data="{ urls: <?php echo htmlspecialchars(json_encode($urls), ENT_QUOTES, 'UTF-8'); ?> }" x-cloak>
            <label class="idno-label"><?php echo \Idno\Core\Idno::site()->language()->_('Your websites'); ?></label>
            <p class="idno-form-help" style="margin-bottom:0.5rem"><?php echo \Idno\Core\Idno::site()->language()->_('Other places on the web where people can find you.'); ?></p>

            <template x-for="(url, index) in urls" :key="index">
                <div style="display:flex;gap:0.5rem;align-items:center;margin-bottom:0.5rem">
                    <input type="url" name="profile[url][]" class="idno-input"
                           x-model="urls[index]" placeholder="https://">
                    <button type="button" @click="urls.splice(index, 1)" x-show="urls.length > 1"
                            style="background:none;border:none;cursor:pointer;color:var(--color-text-muted);padding:0.25rem;flex-shrink:0"
                            title="<?php echo \Idno\Core\Idno::site()->language()->_('Remove'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            </template>

            <button type="button" @click="urls.push('')"
                    style="background:none;border:none;cursor:pointer;color:var(--color-accent);font-size:var(--font-size-sm);display:inline-flex;align-items:center;gap:0.25rem;padding:0">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?php echo \Idno\Core\Idno::site()->language()->_('Add another website'); ?>
            </button>
        </div>

        <!-- CSRF token -->
        <?php echo \Idno\Core\Idno::site()->actions()->signForm('/profile/' . $vars['user']->getHandle()); ?>

        <!-- Action buttons -->
        <div style="display:flex;gap:0.5rem;margin-top:var(--spacing-section)">
            <button type="submit" class="idno-btn idno-btn-primary">
                <?php echo \Idno\Core\Idno::site()->language()->_('Save Changes'); ?>
            </button>
            <a href="<?php echo $vars['user']->getDisplayURL(); ?>" class="idno-btn idno-btn-ghost">
                <?php echo \Idno\Core\Idno::site()->language()->_('Cancel'); ?>
            </a>
        </div>

    </div>

</form>
