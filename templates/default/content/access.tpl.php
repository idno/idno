<?php

    $access = 'PUBLIC';
if (!empty($vars['object'])) {
    if (!empty($vars['object']->access)) {
        $access = $vars['object']->access;
    }
}
if (!empty($vars['default-access'])) {
    $access = $vars['default-access'];
}

    $id_code = 'acl-' . md5(mt_rand());

    // Build the options list for Alpine.js
    $current_user_uuid = \Idno\Core\Idno::site()->session()->currentUserUUID();

    // Map access value to initial label and icon
    $initial_label = \Idno\Core\Idno::site()->language()->_('Public');
    $initial_icon = 'globe';

    $access_options = [
        ['value' => 'PUBLIC', 'label' => \Idno\Core\Idno::site()->language()->_('Public'), 'icon' => 'globe'],
        ['value' => 'SITE', 'label' => \Idno\Core\Idno::site()->language()->_('Members only'), 'icon' => 'users'],
        ['value' => $current_user_uuid, 'label' => \Idno\Core\Idno::site()->language()->_('Private'), 'icon' => 'lock'],
    ];

    // Add custom access groups
    $acls = \Idno\Entities\AccessGroup::get(array('owner' => $current_user_uuid));
    if (!empty($acls)) {
        foreach ($acls as $acl) {
            $icon = ($acl->access_group_type == 'FOLLOWING') ? 'users' : 'cog';
            $access_options[] = ['value' => $acl->getUUID(), 'label' => $acl->title, 'icon' => $icon];
        }
    }

    // Determine initial label/icon from current access value
    foreach ($access_options as $opt) {
        if ($opt['value'] === $access) {
            $initial_label = $opt['label'];
            $initial_icon = $opt['icon'];
            break;
        }
    }

if (!empty(\Idno\Core\Idno::site()->config()->show_privacy) || $access != 'PUBLIC') {

    ?>
        <div class="access-control-block">
            <input type="hidden" name="access" id="access-control-id-<?php echo $id_code; ?>" value="<?php echo htmlspecialchars($access); ?>"/>

            <?php
            $alpine_data = htmlspecialchars(json_encode([
                'open' => false,
                'selected' => $access,
                'label' => $initial_label,
                'icon' => $initial_icon,
            ]), ENT_QUOTES, 'UTF-8');
            ?>
            <div class="idno-access-dropdown" x-data="<?php echo $alpine_data; ?>">
                <button type="button" class="idno-access-trigger" @click="open = !open">
                    <template x-if="icon === 'globe'">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                    </template>
                    <template x-if="icon === 'users'">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </template>
                    <template x-if="icon === 'lock'">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </template>
                    <template x-if="icon === 'cog'">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    </template>
                    <span x-text="label"></span>
                    <svg class="chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                </button>

                <div class="idno-access-menu" x-show="open" @click.outside="open = false" x-cloak>
                    <?php foreach ($access_options as $opt) {
                        $esc_value = htmlspecialchars(json_encode($opt['value']), ENT_QUOTES, 'UTF-8');
                        $esc_label = htmlspecialchars(json_encode($opt['label']), ENT_QUOTES, 'UTF-8');
                        $esc_icon = htmlspecialchars(json_encode($opt['icon']), ENT_QUOTES, 'UTF-8');
                    ?>
                    <button type="button"
                            class="idno-access-option"
                            :class="{ 'active': selected === <?php echo $esc_value; ?> }"
                            @click="selected = <?php echo $esc_value; ?>; label = <?php echo $esc_label; ?>; icon = <?php echo $esc_icon; ?>; document.getElementById('access-control-id-<?php echo $id_code; ?>').value = <?php echo $esc_value; ?>; open = false">
                        <?php if ($opt['icon'] === 'globe') { ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        <?php } elseif ($opt['icon'] === 'users') { ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <?php } elseif ($opt['icon'] === 'lock') { ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <?php } else { ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        <?php } ?>
                        <?php echo htmlspecialchars($opt['label']); ?>
                        <svg class="check" x-show="selected === <?php echo $esc_value; ?>" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
                    </button>
                    <?php } ?>
                </div>
            </div>

        </div>

    <?php

} else {

    ?>
        <input type="hidden" name="access" id="access-control-id-<?php echo $id_code; ?>" value="<?php echo htmlspecialchars($access); ?>"/>
        <?php

}

    /**
 * Document the control for the api
*/
    $this->documentFormControl(
        'access', [
        'id' => 'access-control-id-' .$id_code,
        'description' => 'Access control',
        ]
    );
