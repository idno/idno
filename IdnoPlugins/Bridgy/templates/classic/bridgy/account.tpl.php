<div class="row">

    <div class="col-md-10 col-md-offset-1">

        <?php echo $this->draw('account/menu') ?>
        <h1><?php echo \Idno\Core\Idno::site()->language()->_('Social Interactions'); ?></h1>
        <h2><?php echo \Idno\Core\Idno::site()->language()->_('Connect with Bridgy'); ?></h2>

    </div>

    <div class="col-md-10 col-md-offset-1">

        <p class="explanation">
            <a href="https://www.brid.gy"><?php echo \Idno\Core\Idno::site()->language()->_('Bridgy'); ?></a> <?php echo \Idno\Core\Idno::site()->language()->_('is a service that pulls social interactions - such as likes and retweets - back to your website.'); ?></p>

        <p class="explanation"><?php echo \Idno\Core\Idno::site()->language()->_('If you send content from Idno to Facebook or Twitter, use Bridgy to save comments and interactions from those networks to the original post on your Idno site.'); ?></p>

        <p class="explanation"><?php echo \Idno\Core\Idno::site()->language()->_('To get started, activate Bridgy for the social network.'); ?></p>

    </div>

</div>
<div class="row" id="account-area">

        <div class="col-md-6 col-md-offset-1">
        <?php if ($vars['twitter_enabled']) { ?>
        <form action="https://www.brid.gy/delete/start" method="post">
            <input type="hidden" name="feature" value="listen" />
            <input type="hidden" name="key" value="<?php echo $vars['twitter_key']?>" />
            <input type="hidden" name="callback" value="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/bridgy/disabled/?service=twitter'?>" />
            <p>
                <button class="connect fb connected"><?php echo \Idno\Core\Idno::site()->language()->_('Twitter + Bridgy connected'); ?></button>
            </p>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('Bridgy is pulling in replies, favorites, and retweets from Twitter. Click to disable.'); ?>
            </p>
        </form>
        <?php } else { ?>
        <form action="https://www.brid.gy/twitter/start" method="post">
            <input type="hidden" name="feature" value="listen" />
            <input type="hidden" name="callback" value="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/bridgy/enabled/?service=twitter'?>" />
            <input type="hidden" name="user_url" value="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>" />
            <p>
                <button class="connect tw"><?php echo \Idno\Core\Idno::site()->language()->_('Activate Twitter + Bridgy'); ?></button>
            </p>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('Bridgy pulls in replies, favorites, and retweets from Twitter.'); ?>
            </p>
        </form>
        <?php } ?>
    </div>

</div>


<script>
$(function(){
    function refreshAccountArea() {
        $.get(
            "<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL().'account/bridgy/'?>",
            function(page) {
                // swap out the account area with the re-rendered area
                $('#account-area').replaceWith(
                    $('#account-area', $(page)));
            });
    }

    // check whether the account statuses have changed
    $.get(
        "<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL().'account/bridgy/check/'?>",
        function(data) {
            if (data.changed) {
                refreshAccountArea();
            }
        });
});
</script>
