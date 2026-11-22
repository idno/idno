<?php
    $user = \Idno\Core\Idno::site()->session()->currentUser();
?>
<h1 class="idno-admin-page-title">
    <?php echo \Idno\Core\Idno::site()->language()->_('Tools and Apps'); ?>
</h1>

<div class="idno-admin-card">
    <h2><?php echo \Idno\Core\Idno::site()->language()->_('Bookmarklet'); ?></h2>
    <p>
        <?php echo \Idno\Core\Idno::site()->language()->_('The Idno bookmarklet is the best way to save links, reply to posts, and share articles.'); ?>
    </p>
    <p>
        <?php echo \Idno\Core\Idno::site()->language()->_("Just drag the bookmarklet button below into your browser's Bookmark Bar."); ?>
    </p>
    <p>
        <?php echo $this->draw('entity/bookmarklet'); ?>
    </p>
</div>

<?php echo $this->draw('account/settings/tools/list')?>

<div class="idno-admin-card" style="margin-top: 1.5rem;">
    <h2>API</h2>

    <div class="idno-form-group">
        <label><?php echo \Idno\Core\Idno::site()->language()->_('Your API key'); ?></label>
        <form id="apikey_form"><?php echo $this->__(['action' => '/account/settings/tools/'])->draw('forms/token')?>
            <input type="text" id="apikey" class="idno-input" name="apikey" value="Click to show" readonly>
        </form>
        <?php
        if (!empty($user->apikey)) {
            echo '<div style="margin-top: 0.5rem;">';
            echo \Idno\Core\Idno::site()->actions()->createLink(\Idno\Core\Idno::site()->currentPage()->currentUrl(), \Idno\Core\Idno::site()->language()->_('Revoke'), array('_method' => 'revoke'), array('method' => 'POST', 'class' => 'idno-btn-disable', 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Revoking this key will mean you must update any applications that use this key!')));
            echo '</div>';
        } ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var input = document.getElementById('apikey');
        if (!input) return;
        input.addEventListener('click', function() {
            var form = document.getElementById('apikey_form');
            var params = new URLSearchParams(new FormData(form)).toString();
            fetch('<?php echo \Idno\Core\Idno::site()->currentPage()->currentUrl(); ?>?' + params, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                input.value = data;
            });
        });
    });
</script>
