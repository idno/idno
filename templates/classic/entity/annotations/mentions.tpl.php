<?php

if (!empty($vars['annotations']) && is_array($vars['annotations'])) {
    uasort(
        $vars['annotations'], function ($a, $b) {
            return ($a['time'] < $b['time']) ? -1 : 1;
        }
    );
    foreach($vars['annotations'] as $locallink => $annotation) {

        // The addresses come from whoever sent the annotation: only http and https ones are printed
        $permalink = \Idno\Core\UrlValidator::httpUrl($annotation['permalink'] ? $annotation['permalink'] : $locallink);
        $owner_url = \Idno\Core\UrlValidator::httpUrl($annotation['owner_url']);
        ?>
            <div class="idno-annotation row">
                <div class="idno-annotation-image col-md-1 hidden-sm">
                    <p>
                        <?php echo $this->__(['annotation' => $annotation])->draw('entity/annotations/image'); ?>
                    </p>
                </div>
                <div class="idno-annotation-content col-md-6">
                    <p><?php echo \Idno\Core\Idno::site()->language()->_('Mentioned in'); ?>: <a href="<?php echo htmlspecialchars($permalink) ?>" rel="nofollow"><?php

                    if (!empty($annotation['title'])) {
                        echo htmlspecialchars($annotation['title']);
                    } else {
                        echo htmlspecialchars($permalink);
                    }

                    ?></a></p>
                    <p><small><a href="<?php echo htmlspecialchars($owner_url)?>" rel="nofollow"><?php echo htmlentities($annotation['owner_name'], ENT_QUOTES, 'UTF-8')?></a>,
                            <a href="<?php echo htmlspecialchars($permalink) ?>" rel="nofollow"><?php echo date('M d Y', $annotation['time']);?></a>
                            on <a href="<?php echo htmlspecialchars($permalink) ?>" rel="nofollow"><?php echo htmlspecialchars((string) parse_url($permalink, PHP_URL_HOST)) ?></a></small></p>
                </div>
                <?php
                $this->annotation_permalink = $locallink;

                if ($vars['object']->canEditAnnotation($locallink)) {
                    echo $this->draw('content/annotation/edit');
                }
                ?>
            </div>
        <?php

    }
}
