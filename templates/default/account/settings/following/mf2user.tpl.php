<?php
$mf2_user = $vars['mf2'];

$properties = $mf2_user['properties'];
//if (isset($mf2_user['properties']['author'])) // If there's an author, then use that
     //$properties =$mf2_user['properties']['author'][0];

$name = $properties['name'][0];
$urls = array_unique($properties['url']);
$photo =  $properties['photo'][0];
if (empty($photo)) {

    // No photo URL found, lets fake one for niceness

    $bn = hexdec(substr(md5($properties['url'][0]), 0, 15));
    $number = 1 + ($bn % 5);
    $photo = \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/users/default-'. str_pad($number, 2, '0', STR_PAD_LEFT) .'.png';
} else {
    $photo = \Idno\Core\UrlValidator::httpUrl(\Idno\Core\Idno::site()->template()->getProxiedImageUrl($properties['photo'][0], 300, 'square'));
}

$email =  $properties['email'][0];
if (strpos('mailto:', 'mailto:')!==false) { $email = substr($email, 7); // Sanitise email
}

$nickname =  $properties['nickname'][0];

?>
<form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/settings/following/bookmarklet" method="post" class="form-horizontal">
    <div class="row idno-entry following-user">

    <div class="col-md-1 col-md-offset-1 owner h-card hidden-sm">
        <p>
        <span class="u-url icon-container"><img class="u-photo" src="<?php echo htmlspecialchars($photo) ?>" /></span><br />
        <?php echo htmlspecialchars($name); ?>
        </p>
    </div>
    <div class="col-md-8 idno-object idno-content">
        <div class="visible-sm">
        <p class="p-author author h-card vcard">
            <img class="u-logo logo u-photo photo" src="<?php echo htmlspecialchars($photo) ?>" alt="<?php echo htmlspecialchars($name) ?>" />
            <span class="p-name fn"><?php echo htmlspecialchars($name) ?></span>
        </p>
        </div>
        <div class="e-content entry-content">
        <?php
        foreach ($urls as $url) {
            ?>
            <input type="hidden" name="urls[]" value="<?php echo htmlspecialchars($url); ?>" />
            <?php
        }
        ?>
                <input type="hidden" name="photo" value="<?php echo htmlspecialchars($photo) ?>" />
                
        <div class="control-group">
            <label class="control-label" for="inputName"><?php echo \Idno\Core\Idno::site()->language()->_('Name'); ?></label>

            <div class="controls">
            <input id="inputName" type="text" class="col-md-4" name="name" required
                   value="<?php echo htmlspecialchars($name) ?>">
            </div>
        </div>
        
        <div class="control-group">
            <label class="control-label" for="inputNickname"><?php echo \Idno\Core\Idno::site()->language()->_('Nickname'); ?></label>

            <div class="controls">
            <input id="inputNickname" type="text" class="col-md-4" name="nickname" required placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Handle (for your reference)'); ?>"
                   value="<?php echo htmlspecialchars($nickname) ?>">
            </div>
        </div>
        
        <div class="control-group">
            <label class="control-label" for="inputEmail"><?php echo \Idno\Core\Idno::site()->language()->_('Email'); ?></label>

            <div class="controls">
            <input id="inputName" type="email" class="col-md-4" name="email" required
                   value="<?php echo htmlspecialchars($email) ?>">
            </div>
        </div>
        
        <div class="control-group">
            <label class="control-label" for="inputUrl"><?php echo \Idno\Core\Idno::site()->language()->_('Profile URL'); ?></label>
            
            <div class="controls">
            <?php if (count($urls)>1) { ?>
            <select name="uuid">
                <?php
                foreach($urls as $url) {
                    ?>
                <option><?php echo htmlspecialchars($url);?></option>
                    <?php
                }
                ?>
            </select>
            <?php } else { ?>
                <a href="<?php echo htmlspecialchars(\Idno\Core\UrlValidator::httpUrl($url)); ?>" target="_blank"><?php echo htmlspecialchars($url); ?></a>
                <input type="hidden" name="uuid" value="<?php echo htmlspecialchars($url); ?>" />
            <?php } ?>
            </div>

        </div>
        
        <div class="control-group">
            <div class="controls">
            <button type="submit" class="btn"><?php echo \Idno\Core\Idno::site()->language()->_('Add as friend...'); ?></button>
            </div>
        </div>
        </div>
        <div class="footer">

        </div>

    </div>

    </div>


    <?php echo \Idno\Core\Idno::site()->actions()->signForm('/account/settings/following/bookmarklet') ?>

</form>
