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

if (!empty(\Idno\Core\Idno::site()->config()->show_privacy) || $access != 'PUBLIC') {

    ?>
        <div class="access-control-block">
            <input type="hidden" name="access" id="access-control-id-<?php echo $id_code; ?>" value="<?php echo htmlspecialchars($access); ?>"/>

        <?php

            //if (!empty(\Idno\Core\Idno::site()->config()->experimental)) {

        ?>

            <div id="access-control-<?php echo $id_code; ?>" class="acl">
                <div class="btn-group">
                    <a class="btn btn-info access dropdown-toggle" data-toggle="dropdown" href="#" id="access-button-<?php echo $id_code; ?>">
                        <span id="acl-text"><i class="fa fa-globe"> </i> Public</span>
                        <span class="caret"></span>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="#" data-acl="PUBLIC" class="acl-ctrl-option"><i class="fa fa-globe"> </i> <?php echo \Idno\Core\Idno::site()->language()->_('Public')?></a>
                        </li>
                        <li><a href="#" data-acl="SITE" class="acl-ctrl-option"><i class="fa fa-group"> </i> <?php echo \Idno\Core\Idno::site()->language()->_('Members only')?></a></li>
                        <li><a href="#" data-acl="<?php echo \Idno\Core\Idno::site()->session()->currentUserUUID() ?>"
                               class="acl-ctrl-option"><i class="fa fa-lock"></i> <?php echo \Idno\Core\Idno::site()->language()->_('Private')?></a></li>
                    <?php
                        $acls = \Idno\Entities\AccessGroup::get(array('owner' => \Idno\Core\Idno::site()->session()->currentUserUUID()));
                    if (!empty($acls)) {
                        foreach ($acls as $acl) {

                            $icon = 'fa fa-cog';
                            if ($acl->access_group_type == 'FOLLOWING') {
                                $icon = 'fa fa-users';
                            }
                            ?>
                                    <li><a href="#" data-acl="<?php echo $acl->getUUID(); ?>" class="acl-ctrl-option"><i
                                                class="<?php echo $icon; ?>"> </i> <?php echo $acl->title; ?></a></li>
                                <?php
                        }
                    }
                    ?>
                    </ul>
                </div>
            </div>

            <?php

            //}

            ?>

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
