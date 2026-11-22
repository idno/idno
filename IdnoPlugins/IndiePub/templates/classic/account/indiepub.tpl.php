<?php

use Idno\Core\Idno;

$baseURL = Idno::site()->config()->getDisplayURL();
$user = Idno::site()->session()->currentUser();
?>
<div class="row">
    <div class="col-md-offset-1 col-md-10">

        <?php echo $this->draw('account/menu') ?>
        <h1><?php echo \Idno\Core\Idno::site()->language()->_('Micropub Accounts'); ?></h1>


        <?php
        if (empty($user->indieauth_tokens)) {
            ?>
            <div class="explanation">
                <p>
                    <?php echo \Idno\Core\Idno::site()->language()->_('There are currently no micropub accounts associated with this site.'); ?>
                </p>
            </div>
            <?php
        } else {
            foreach ((array) $user->indieauth_tokens as $token => $details) { ?>
            <div class="panel panel-default">
                <div class="panel-heading">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Client ID'); ?>: <a href="<?php echo $details['client_id'] ?>" target="_blank"><?php echo $details['client_id'] ?></a>
                </div>
                <div class="panel-body" >
                    <p>
                        <?php echo \Idno\Core\Idno::site()->language()->_('Authorized'); ?> <strong><?php echo strftime('%Y-%m-%d', $details['issued_at']) ?></strong>
                        <?php echo \Idno\Core\Idno::site()->language()->_('with the scope'); ?> <strong><?php echo $details['scope'] ?></strong>.
                    </p>
                    <p>
                        <?php echo \Idno\Core\Idno::site()->language()->_('Redirect URI'); ?>: <?php echo $details['redirect_uri']; /*substr($token, 0, 5) */ ?>
                    </p>
                    <p>
                      <?php echo \Idno\Core\Idno::site()->language()->_('Token'); ?>: <?php echo $token; /*substr($token, 0, 5) */ ?>
                    </p>
                    <form action="<?php echo Idno::site()->config()->getDisplayURL() ?>account/indiepub/revoke" method="POST">
                        <input name="token" type="hidden" value="<?php echo $token ?>">
                        <button class="btn btn-warning" type="submit"><?php echo \Idno\Core\Idno::site()->language()->_('Revoke Access'); ?></button>
                        <?php echo Idno::site()->actions()->signForm('account/indiepub/revoke') ?>
                    </form>
                </div>
            </div>
                <?php
            }
        }?>

        <p id="addtoken-link">
            <a href="#" onclick="$('#addtoken').show(); $('#addtoken-link').hide(); return false;"><?php echo \Idno\Core\Idno::site()->language()->_('Add Micropub Account'); ?></a>
        </p>
        <div id="addtoken" style="display: none; width: 100%">
            <h3>
                <?php echo \Idno\Core\Idno::site()->language()->_('Add Micropub Account'); ?>
            </h3>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('To manually add a micropub client account and generate an API token, enter the details below.'); ?>
            </p>
            <form action="<?php echo Idno::site()->config()->getDisplayURL() ?>account/indiepub/add" method="post">
                <div>
                    <label>
                        <?php echo \Idno\Core\Idno::site()->language()->_('Client ID'); ?><br>
                        <input type="text" name="client_id" value="" required />
                    </label>
                </div>
                <div>
                    <label>
                        <?php echo \Idno\Core\Idno::site()->language()->_('Redirect URI'); ?><br>
                        <input type="text" name="redirect_uri" value="" required />
                    </label>
                </div>
                <button class="btn btn-warning" type="submit"><?php echo \Idno\Core\Idno::site()->language()->_('Save'); ?></button>
                <?php echo Idno::site()->actions()->signForm('account/indiepub/add') ?>
            </form>
        </div>

</div>
</div>