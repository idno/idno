<div class="row">

    <div class="col-md-10 col-md-offset-1">
                <?php echo $this->draw('admin/menu');?>

        <h1><?php echo \Idno\Core\Idno::site()->language()->_('Webhooks'); ?></h1>

        <p class="explanation">
            <?php echo \Idno\Core\Idno::site()->language()->_('Webhooks let you syndicate content to external applications very simply. The content of your post is sent to an external URL. Services like Slack, Wufoo, and Mailchimp all use Webhooks.'); ?>
        </p>
        <p class="explanation">
            <?php echo \Idno\Core\Idno::site()->language()->_('To learn more about Webhooks and what they can be used for, read the');?> <a href="https://webhooks.pbworks.com/w/page/13385124/FrontPage"><?php echo \Idno\Core\Idno::site()->language()->_('PBWiki Webhooks page'); ?></a>.</p>
        <p>
            <?php echo \Idno\Core\Idno::site()->language()->_('When content is syndicated via Webhooks, the external URL is sent the following data'); ?>:
        </p>
        <ul>
            <li><strong><em><?php echo \Idno\Core\Idno::site()->language()->_('text'); ?></em></strong>: <?php echo \Idno\Core\Idno::site()->language()->_('the text of the update'); ?></li>
            <li><strong><em><?php echo \Idno\Core\Idno::site()->language()->_('username'); ?></em></strong>: <?php echo \Idno\Core\Idno::site()->language()->_('the username of the account-holder'); ?></li>
            <li><strong><em>icon_url</em></strong>: <?php echo \Idno\Core\Idno::site()->language()->_("the URL of the user's icon"); ?></li>
            <li><strong><em>content_type</em></strong>: <?php echo \Idno\Core\Idno::site()->language()->_('the type of content being sent'); ?></li>
        </ul>

    </div>

</div>
<div class="row">
    <div class="col-md-10 col-md-offset-1">

        <h3><?php echo \Idno\Core\Idno::site()->language()->_('Create a new Webhook'); ?></h3>

        <form action="" method="post">

            <?php

            if (!empty(\Idno\Core\Idno::site()->config()->webhook_syndication)) {
                foreach (\Idno\Core\Idno::site()->config()->webhook_syndication as $webhook) {
                    if (!empty($webhook['title']) && !empty($webhook['url'])) {

                        ?>
                <div class="row">
                    <div class="col-md-4">
                        <input type="text" name="titles[]" value="<?php echo htmlspecialchars($webhook['title'])?>" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Name of this webhook'); ?>" class="form-control">
                    </div>
                    <div class="col-md-5">
                        <input type="text" name="webhooks[]" value="<?php echo htmlspecialchars($webhook['url'])?>" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Webhook URL'); ?>" class="form-control">
                    </div>
                    <div class="col-md-3" style="margin-top: 0.75em">
                        <small><a href="#" onclick="$(this).closest('.row').remove(); return false;"><i class="fa fa-times"></i> <?php echo \Idno\Core\Idno::site()->language()->_('Remove this Webhook'); ?></a></small>
                    </div>
                </div>
                        <?php
                    }

                }
            }

            ?>
            <div class="row">
                <div class="col-md-4">
                    <input type="text" value="" name="titles[]" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Name of this webhook'); ?>" class="form-control">
                </div>
                <div class="col-md-5">
                    <input type="text" value="" name="webhooks[]" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Webhook URL'); ?>" class="form-control">
                </div>
                <div class="col-md-3" style="margin-top: 0.75em">
                    <small><a href="#" onclick="$(this).closest('.row').remove(); return false;"><i class="fa fa-times"></i> <?php echo \Idno\Core\Idno::site()->language()->_('Remove this Webhook'); ?></a></small>
                </div>
            </div>
            <div id="morefields"></div>
            
                <p style="margin-top:1em; margin-bottom:1.5em">
                    <a href="#" onclick="$('#morefields').append($('#field_template').html());"><i class="fa fa-plus"></i> <?php echo \Idno\Core\Idno::site()->language()->_('Add another Webhook'); ?></a>
                </p>
            <p>
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/admin/webhooks/') ?>
                <input class="btn btn-primary" value="<?php echo \Idno\Core\Idno::site()->language()->_('Save Webhooks'); ?>" type="submit">
            </p>

        </form>
        <div id="field_template" style="display:none">
            <div class="row">
                <div class="col-md-4">
            <input type="text" value="" name="titles[]" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Name of this webhook'); ?>" class="form-control">
        </div>
                <div class="col-md-5">
            <input type="text" value="" name="webhooks[]" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Webhook URL'); ?>"  class="form-control">
        </div>
                <div class="col-md-3" style="margin-top: 0.75em">
            <small><a href="#" onclick="$(this).closest('.row').remove(); return false;"><i class="fa fa-times"></i> <?php echo \Idno\Core\Idno::site()->language()->_('Remove this Webhook'); ?></a></small>
        </div>
            </div>
        </div>

    </div>
</div>