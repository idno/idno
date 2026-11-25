<?php

    /*
     * A portfolio drawn where any entity can be (its /view/ address, for one): its card.
     */

    echo $this->__(['portfolio' => $vars['object'] ?? null])->draw('portfolios/card');
    unset($this->vars['portfolio']);
