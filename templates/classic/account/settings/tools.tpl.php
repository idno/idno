<?php
    $user = \Idno\Core\Idno::site()->session()->currentUser();
?>
<div class="row">

    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('account/menu') ?>
        <h1>
            <?php echo \Idno\Core\Idno::site()->language()->_('Tools and Apps'); ?>
        </h1>

        <div>
            <h2><?php echo \Idno\Core\Idno::site()->language()->_('Bookmarklet'); ?></h2>
        </div>
    </div>
</div>


<div class="row">
    <div class="col-md-4 col-md-offset-1">
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('The Idno bookmarklet is the best way to save links, reply to posts, and share articles.'); ?></p> 
                <p><?php echo \Idno\Core\Idno::site()->language()->_("Just drag the bookmarklet button below into your browser's Bookmark Bar."); ?>
            </p>
            <p>
                <?php echo $this->draw('entity/bookmarklet'); ?>             </p>
        </div>
    <div class="col-md-4 col-md-offset-1">
        <p>
            <img src="<?php echo \Idno\Core\Idno::site()->config()->getStaticURL() ?>gfx/other/bookmarklet-mouse.png" alt="bookmarklet-mouse" class="img-responsive" />
        </p>
        </div>
</div>


<div class="row">
    <div class="col-md-4 col-md-offset-1">
        <img src="<?php echo \Idno\Core\Idno::site()->config()->getStaticURL() ?>gfx/other/bookmarklet.png" alt="bookmarklet" class="img-responsive"  />
    </div>
    <div class="col-md-4 col-md-offset-1">
        <p>
            <strong><?php echo \Idno\Core\Idno::site()->language()->_("Don't see a bookmarks bar?"); ?></strong>
        </p>
        <p>
            <?php echo \Idno\Core\Idno::site()->language()->_("Sometimes web browsers have their bookmarked links bar hidden by default. Here's how to reveal it:"); ?></p>
            <p>
            <strong><?php echo \Idno\Core\Idno::site()->language()->_('Chrome'); ?>:</strong> <?php echo \Idno\Core\Idno::site()->language()->_('Select <em>Always Show Bookmarks Bar</em> from the <em>View</em> menu.'); ?><br>
            <strong><?php echo \Idno\Core\Idno::site()->language()->_('Firefox'); ?>:</strong> <?php echo \Idno\Core\Idno::site()->language()->_('Select <em>View</em>, then <em>Toolbars</em>, and make sure'); ?>
            <em><?php echo \Idno\Core\Idno::site()->language()->_('Bookmarks Toolbar'); ?></em> <?php echo \Idno\Core\Idno::site()->language()->_('is checked.'); ?><br>
            <strong><?php echo \Idno\Core\Idno::site()->language()->_('Internet Explorer'); ?>:</strong> <?php echo \Idno\Core\Idno::site()->language()->_('Select <em>Tools</em>, then make sure <em>Favorites Bar</em> is checked.'); ?>
        </p>
    </div>
</div>

<?php echo $this->draw('account/settings/tools/list')?>

<div class="row" style="margin-top: 2em">

    <div class="col-md-10 col-md-offset-1">

        <h2>API</h2>
        
        <div class="form-group">
            <div class="col-md-2">
                <label class="control-label"><?php echo \Idno\Core\Idno::site()->language()->_('Your API key'); ?>: </label>
            </div>
            <div class="col-md-8">
                
                <form id="apikey_form"><?php echo $t->__(['action' => '/account/settings/tools/'])->draw('forms/token')?>
                    <input type="text" id="apikey" class=" form-control" name="apikey" value="Click to show" readonly></form>
            </div>

            <div class="col-md-1">
                    <?php
                    if (!empty($user->apikey)) {
                        echo \Idno\Core\Idno::site()->actions()->createLink(\Idno\Core\Idno::site()->currentPage()->currentUrl(), \Idno\Core\Idno::site()->language()->_('Revoke'), array('_method' => 'revoke'), array('method' => 'POST', 'class' => 'btn btn-danger', 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Revoking this key will mean you must update any applications that use this key!')));
                    } ?>
            </div>
        </div>
        <p>
            
        </p>

    </div>

</div>
<script>
    $(document).ready(function() {
        $('#apikey').click(function() {
            var ctrl = $(this);
            
            $.ajax('<?php echo \Idno\Core\Idno::site()->currentPage()->currentUrl(); ?>', {
                dataType: 'json',
                data: $('#apikey_form').serialize(),
                success: function(data) {
                    ctrl.val(data);
                    $('#apikey-revoke').fadeIn();
                }
            })
        });
    });
</script>