<span class="idno-annotation-delete">
    <?= \Idno\Core\Idno::site()->actions()->createLink(
        $vars['object']->getDisplayUrl() . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode($vars['annotation_permalink']),
        \Idno\Core\Idno::site()->language()->_('Delete'),
        [],
        ['method' => 'POST']
    ) ?>
</span>
