<?php

if (!empty($vars['object']) && $vars['object'] instanceof \IdnoPlugins\StaticPages\StaticPage) {
    echo $vars['object']->draw();
}
