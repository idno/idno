<?php

    // Generate a unique ID for this form and link
    $uniqueID = uniqid('f');

    // Get HTTP method (GET, POST, PUT and DELETE supported for now)
    if (empty($vars['method']) || !in_array($vars['method'], array('GET','POST','PUT','DELETE'))) $vars['method'] = 'POST';

    // The address, class, title and confirmation text are printed escaped. The label is markup
    // on purpose (callers pass an icon and text), so it is printed as given.
    $linkUrl = \Idno\Core\UrlValidator::linkUrl($vars['url'] ?? '');

    // The confirmation text is one single-quoted JavaScript string, then escaped for the attribute it is in.
    $confirmJs = "'" . strtr((string) ($vars['confirm-text'] ?? ''), ['\\' => '\\\\', "'" => "\\'", "\r" => '\\r', "\n" => '\\n', "\u{2028}" => '\\u2028', "\u{2029}" => '\\u2029']) . "'";

?>
<a data-form-id="<?php echo $uniqueID; ?>" <?php if (!empty($vars['class'])) { ?> class="<?php echo htmlspecialchars((string) $vars['class'], ENT_QUOTES) ?>" <?php
} ?> <?php if (!empty($vars['title'])) { ?> title="<?php echo htmlspecialchars((string) $vars['title'], ENT_QUOTES) ?>" <?php
} ?> href="<?php echo htmlspecialchars($linkUrl, ENT_QUOTES) ?>" onclick="<?php
if ($vars['confirm']) {
    ?>if (confirm(<?php echo htmlspecialchars($confirmJs, ENT_COMPAT | ENT_SUBSTITUTE) ?>)) { $('#<?php echo $uniqueID?>').submit(); return false; } else { return false; } <?php
} else {
    ?>$('#<?php echo $uniqueID?>').submit(); return false; <?php
} ?>"><?php echo ($vars['label'])?></a>
<?php

    ob_start();

?>
<form action="<?php echo htmlspecialchars($linkUrl, ENT_QUOTES) ?>" style="display: none; margin: 0; padding: 0" id="<?php echo $uniqueID?>" method="<?php echo htmlspecialchars($vars['method'], ENT_QUOTES) ?>">
    <textarea name="json"><?php echo htmlspecialchars(json_encode($vars['data']))?></textarea>
    <?php echo  \Idno\Core\Idno::site()->actions()->signForm($linkUrl);?>
</form>
<?php

    $form = ob_get_clean();
if (\Idno\Core\Idno::site()->currentPage()->xhr) {
    global $template_postponed_link_actions; // HORRIBLE HACK, to allow links to be active in xhr inserted controls. There *must* be a better way.

    if (empty($template_postponed_link_actions))
        $template_postponed_link_actions = "";

    $template_postponed_link_actions .= $form;
} else {
    \Idno\Core\Idno::site()->template()->extendTemplateWithContent('shell/form-data', $form);
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
