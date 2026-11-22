<?php

    // Generate a unique ID for this form and link
    $uniqueID = uniqid('f');

    // Get HTTP method (GET, POST, PUT and DELETE supported for now)
    if (empty($vars['method']) || !in_array($vars['method'], array('GET','POST','PUT','DELETE'))) $vars['method'] = 'POST';

?>
<a data-form-id="<?= $uniqueID ?>" <?php if (!empty($vars['class'])) { ?> class="<?= $vars['class'] ?>" <?php
} ?> <?php if (!empty($vars['title'])) { ?> title="<?= $vars['title'] ?>" <?php
} ?> href="<?= $vars['url'] ?>" onclick="<?php
if ($vars['confirm']) {
    ?>if (confirm('<?= addslashes($vars['confirm-text']) ?>')) { document.getElementById('<?= $uniqueID ?>').submit(); return false; } else { return false; } <?php
} else {
    ?>document.getElementById('<?= $uniqueID ?>').submit(); return false; <?php
} ?>"><?= $vars['label'] ?></a>
<?php

    ob_start();

?>
<form action="<?= $vars['url'] ?>" style="display: none; margin: 0; padding: 0" id="<?= $uniqueID ?>" method="<?= $vars['method'] ?>">
    <textarea name="json"><?= htmlspecialchars(json_encode($vars['data'])) ?></textarea>
    <?= \Idno\Core\Idno::site()->actions()->signForm($vars['url']) ?>
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
