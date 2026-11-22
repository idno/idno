<?php

foreach ([
              'rsvp'    => 'rsvps',
              'like'    => 'likes',
              'share'   => 'shares',
              'reply'   => 'replies',
              'mention' => 'mentions'] as $annotationType => $templateName) {

    if ($annotations = $vars['object']->getAnnotations($annotationType)) {
        echo $this->__(array('annotations' => $annotations))->draw('entity/annotations/' . $templateName);
    }

}

