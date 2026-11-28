<?php

// Generate a unique ID for this form and link
$uniqueID = uniqid('f');

// A link is sent as a POST, from a hidden form that holds the form token, unless it was asked
// for as GET: then it is a plain link, with no form and no token. A browser cannot send a
// form with any other method (it sends it as a GET, the token in the address), so anything
// else is drawn as a POST (Actions::linkMethod()).
$isPlainLink = \Idno\Core\Actions::linkMethod($vars['method'] ?? '') === 'GET';

// The address, class, title and confirmation text are printed escaped. The label is markup
// on purpose (callers pass an icon and text), so it is printed as given.
$linkUrl = \Idno\Core\UrlValidator::httpUrl($vars['url'] ?? '');
$confirmText = htmlspecialchars(json_encode((string) ($vars['confirm-text'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);

if ($isPlainLink) {
    $onclick = !empty($vars['confirm']) ? 'return confirm(' . $confirmText . ');' : '';
} else if (!empty($vars['confirm'])) {
    $onclick = 'if (confirm(' . $confirmText . ')) { document.getElementById(\'' . $uniqueID . '\').submit(); return false; } else { return false; } ';
} else {
    $onclick = 'document.getElementById(\'' . $uniqueID . '\').submit(); return false; ';
}

$attributes = '';
if (!$isPlainLink) {
    $attributes .= ' data-form-id="' . $uniqueID . '"';
}
if (!empty($vars['class'])) {
    $attributes .= ' class="' . htmlspecialchars((string) $vars['class'], ENT_QUOTES) . '"';
}
if (!empty($vars['title'])) {
    $attributes .= ' title="' . htmlspecialchars((string) $vars['title'], ENT_QUOTES) . '"';
}
$attributes .= ' href="' . htmlspecialchars($linkUrl, ENT_QUOTES) . '"';
if ($onclick !== '') {
    $attributes .= ' onclick="' . $onclick . '"';
}

?>
<a<?php echo $attributes ?>><?php echo ($vars['label'])?></a>
<?php

if (!$isPlainLink) {

    ob_start();

    ?>
<form action="<?php echo htmlspecialchars($linkUrl, ENT_QUOTES) ?>" style="display: none; margin: 0; padding: 0" id="<?php echo $uniqueID?>" method="post">
    <textarea name="json"><?php echo htmlspecialchars(json_encode($vars['data']))?></textarea>
    <?php echo  \Idno\Core\Idno::site()->actions()->signForm($linkUrl);?>
</form>
    <?php

    $form = ob_get_clean();
    if (\Idno\Core\Idno::site()->currentPage()->xhr) {
        global $template_postponed_link_actions; // HORRIBLE HACK, to allow links to be active in xhr inserted controls. There *must* be a better way.

        if (empty($template_postponed_link_actions)) {
            $template_postponed_link_actions = "";
        }

        $template_postponed_link_actions .= $form;
    } else {
        \Idno\Core\Idno::site()->template()->extendTemplateWithContent('shell/form-data', $form);
    }
}

    // Prevent scope pollution
    unset($this->vars['confirm-text']);
    unset($this->vars['class']);
    unset($this->vars['confirm']);
    unset($this->vars['url']);
    unset($this->vars['method']);
    unset($this->vars['data']);
    unset($this->vars['label']);
    unset($this->vars['id']);
