<?php

if (!empty($vars['annotations']) && is_array($vars['annotations'])) {
    usort(
        $vars['annotations'], function ($a, $b) {
            return ($a['time'] < $b['time']) ? -1 : 1;
        }
    );

    $rsvps_by_response = ['yes' => '', 'maybe' => '', 'no' => '', 'etc' => ''];

    foreach($vars['annotations'] as $locallink => $annotation) {
        // The addresses come from whoever sent the annotation: only http and https ones are printed
        $permalink = \Idno\Core\UrlValidator::httpUrl(!empty($annotation['permalink']) ? $annotation['permalink'] : $locallink);
        $rsvp = !empty($annotation['rsvp']) ? strtolower(trim($annotation['rsvp'])) : 'etc';

        ob_start();
        ?>
            <div class="idno-annotation row">
                <div class="idno-annotation-image col-md-1 hidden-sm">
                    <p>
                        <?php echo $this->__(['annotation' => $annotation])->draw('entity/annotations/image'); ?>
                    </p>
                </div>
                <div class="idno-annotation-content col-md-6">
                    <p>
                        <strong><?php echo strip_tags($annotation['content']); ?></strong>
                    </p>
                    <p><small><a href="<?php echo htmlspecialchars($permalink) ?>" rel="nofollow"><?php echo date('M d Y', $annotation['time']);?></a> on <a href="<?php echo htmlspecialchars($permalink) ?>" rel="nofollow"><?php echo htmlspecialchars((string) parse_url($permalink, PHP_URL_HOST)) ?></a></small></p>
                </div>
            </div>
        <?php
        $rsvps_by_response[$rsvp] .= ob_get_clean();
    }

    foreach($rsvps_by_response as $rsvp => $list) {

        if (!empty($list)) {
            switch($rsvp) {
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
                    <div class="row">
                        <div class="col-md-7">
                            <p>
                                <strong><?php echo $title;?></strong>
                            </p>
                        </div>
                    </div>
                <?php
                echo $list;
        }

    }
}