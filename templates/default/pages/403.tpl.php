<?php
    // Display the login link, if the user is not currently logged in.
    // If they're logged out, this is probably why they're denied.

    $login_url = \Idno\Core\Idno::site()->currentPage()->forwardToLogin($_SERVER['REQUEST_URI'], true);

if (!\Idno\Core\Idno::site()->session()->isLoggedIn()) {
    if (!\Idno\Core\Idno::site()->config()->isPublicSite()) {
        // A private site: say so, for a visitor whose browser does not send them on
        ?>
        <noscript>
            <h1><?php echo \Idno\Core\Idno::site()->language()->esc_('%s is private', [\Idno\Core\Idno::site()->config()->title]); ?></h1>
            <p><?php echo \Idno\Core\Idno::site()->language()->_('Only members can read this community. If you are a member, sign in. If you have been invited, use the link in your invitation email to create your account.'); ?></p>
        </noscript>
        <?php
    }
    ?>
        <a id="soft-forward"
           href="<?php echo htmlspecialchars($login_url); ?>"><?php echo \Idno\Core\Idno::site()->language()->_('Click here to log in.'); ?></a>
        <script>
            $('#soft-forward').hide();  // JS users will be forwarded anyway
        </script>
        <?php
} else {
    ?>
        <div class="container result-403">
            <div class="row" style="margin-bottom: 2em; margin-top: 6em">
                <div class="col-md-offset-1 col-md-5">
                    <h1 class="p-name" style="margin-bottom: 2em;">
                    <?php echo \Idno\Core\Idno::site()->language()->_("Hold on. You don't have access to this content."); ?>
                    </h1>
                    <p><?php echo \Idno\Core\Idno::site()->language()->_("It's nothing personal. You just don't have the right permissions to see what's here."); ?></p>
                    <p>
                    <?php echo \Idno\Core\Idno::site()->language()->_('Find something else to view on the <a href="%s">%s homepage</a>.', [\Idno\Core\Idno::site()->config()->getDisplayURL(), \Idno\Core\Idno::site()->config()->title]); ?>
                    </p>
                </div>
                <div class="col-md-5">
                    <img src="<?php echo \Idno\Core\Idno::site()->config()->getStaticURL()?>gfx/robots/aleph_403.png" alt="Robot with a stop sign">
                </div>
            </div>
        </div>
<?php }
