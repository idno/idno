<?php

    $hidenav = \Idno\Core\Idno::site()->currentPage()->getInput('hidenav', false);

if (empty($vars['hidenav']) && empty($hidenav)) {
    echo $this->draw('shell/toolbar/main');
} else {
    ?>
        <style>
            body {
                padding-top: 0px !important; /* 60px to make the container go all the way to the bottom of the topbar */
            }
        </style>
        <div style="height: 1em;"><br/></div>
    <?php

} // End hidenav test

    echo $this->draw('shell/beforecontainer');

