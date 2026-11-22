<div class="edit edit-annotation">
    <p>
        <?php echo  \Idno\Core\Idno::site()->actions()->createLink(
            $vars['object']->getDisplayUrl() . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode($vars['annotation_permalink']), //$vars['annotation_permalink'] . '/delete/',
            \Idno\Core\Idno::site()->language()->_('Delete'),
            [],
            ['method' => 'POST']
        );
?>
    </p>
</div>