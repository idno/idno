<?php

if (!empty($vars['rel'])) {
    $rel = $vars['rel'];
} else {
    $rel = '';
}
    echo $this->autop(
        $this->parseURLs(
            $this->parseHashtags(
                $this->purifier->purify($vars['value'])
            ), $rel
        )
    );

