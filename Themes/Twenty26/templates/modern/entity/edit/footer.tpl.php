<?php

    /*
     * What follows the form on an edit page. The default look also prints a script here
     * that hides the submit button and shows a spinner; it is written for jQuery, which
     * Twenty26 does not load, so it could only fail. Twenty26 draws the Delete control alone.
     */

    echo $this->draw('entity/edit/delete');
