<?php

if (!empty($vars['annotations']) && is_array($vars['annotations'])) {
    usort(
        $vars['annotations'], function ($a, $b) {
            return ($a['time'] < $b['time']) ? -1 : 1;
        }
    );

    $rsvps_by_response = ['yes' => '', 'maybe' => '', 'no' => '', 'etc' => ''];

    foreach ($vars['annotations'] as $locallink => $annotation) {
        // The addresses come from whoever sent the annotation: only http and https ones are printed
        $permalink = \Idno\Core\UrlValidator::httpUrl(!empty($annotation['permalink']) ? $annotation['permalink'] : $locallink);
        $owner_image = \Idno\Core\UrlValidator::httpUrl($annotation['owner_image']);
        $rsvp = !empty($annotation['rsvp']) ? strtolower(trim($annotation['rsvp'])) : 'etc';

        ob_start();
?>
            <div class="idno-annotation">
                <?php if ($owner_image !== '') { ?>
                <img class="idno-annotation-avatar"
                     src="<?= htmlspecialchars($owner_image) ?>"
                     alt="<?= htmlspecialchars($annotation['owner_name']) ?>" />
                <?php } ?>
                <div class="idno-annotation-content">
                    <span><strong><?= strip_tags($annotation['content']) ?></strong></span>
                    <br>
                    <span class="idno-entry-meta">
                        <a href="<?= htmlspecialchars($permalink) ?>" rel="nofollow"><?= date('M j, Y', $annotation['time']) ?></a>
                        on <a href="<?= htmlspecialchars($permalink) ?>" rel="nofollow"><?= htmlspecialchars((string) parse_url($permalink, PHP_URL_HOST)) ?></a>
                    </span>
                </div>
                <?php
                $this->annotation_permalink = $locallink;
                if ($vars['object']->canEditAnnotation($annotation)) {
                    echo $this->draw('content/annotation/edit');
                }
                ?>
            </div>
<?php
        $rsvps_by_response[$rsvp] .= ob_get_clean();
    }

    foreach ($rsvps_by_response as $rsvp => $list) {
        if (!empty($list)) {
            switch ($rsvp) {
                case 'yes':
                    $title = \Idno\Core\Idno::site()->language()->_('Attending');
                    break;
                case 'maybe':
                    $title = \Idno\Core\Idno::site()->language()->_('Maybe attending');
                    break;
                case 'no':
                    $title = \Idno\Core\Idno::site()->language()->_('Not attending');
                    break;
                case 'etc':
                    $title = \Idno\Core\Idno::site()->language()->_('Other responses');
                    break;
            }
?>
            <div class="idno-annotation-section">
                <h4><?= $title ?></h4>
            </div>
<?php
            echo $list;
        }
    }
}
