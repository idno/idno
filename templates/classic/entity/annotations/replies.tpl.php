<?php

if (!empty($vars['annotations']) && is_array($vars['annotations'])) {
    uasort(
        $vars['annotations'], function ($a, $b) {
            return ($a['time'] < $b['time']) ? -1 : 1;
        }
    );
    foreach ($vars['annotations'] as $locallink => $annotation) {

        // The addresses come from whoever sent the annotation: only http and https ones are printed
        $permalink = \Idno\Core\UrlValidator::httpUrl($annotation['permalink'] ? $annotation['permalink'] : $locallink);
        $owner_url = \Idno\Core\UrlValidator::httpUrl($annotation['owner_url']);
        $owner_image = \Idno\Core\UrlValidator::httpUrl($annotation['owner_image']);
        ?>
            <div class="idno-annotation row u-comment h-cite">
                <div class="idno-annotation-image col-md-1 hidden-sm">
                    <p>
                        <?php echo $this->__(['annotation' => $annotation])->draw('entity/annotations/image'); ?>
                    </p>
                </div>
                <div class="idno-annotation-content col-md-9">
                    <div class="p-summary e-content"><?php echo $this->autop($this->parseURLs(strip_tags($annotation['content']), 'rel="nofollow"')); ?></div>
                    <p>
                        <small>
                    <span class="p-author h-card">
                        <a class="u-photo" rel="nofollow" href="<?php echo htmlspecialchars($owner_image) ?>"></a>
                        <a class="p-name u-url"
                           href="<?php echo htmlspecialchars($owner_url) ?>" alt="<?php echo htmlspecialchars($annotation['owner_name']) ?>" rel="nofollow"><?php echo htmlentities($annotation['owner_name'], ENT_QUOTES, 'UTF-8') ?></a></span>,
                            <a href="<?php echo htmlspecialchars($permalink) ?>" rel="nofollow"><?php echo date('M d Y', $annotation['time']); ?></a>
                            on <a href="<?php echo htmlspecialchars($permalink) ?>" rel="nofollow" class="u-url"><?php echo htmlspecialchars((string) parse_url($permalink, PHP_URL_HOST)) ?></a>
                        </small>
                    </p>
                </div>
            <?php
                $this->annotation_permalink = $locallink;

            if ($vars['object']->canEditAnnotation($annotation)) {
                echo $this->draw('content/annotation/edit');
            }
            ?>
            </div>
            <?php

    }
}
