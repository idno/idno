<?php

/*
 * A static page for the Markdown form: its body, with none of the controls an administrator sees on the page.
 */

echo $this->autop($this->parseURLs($this->parseHashtags((string) $vars['object']->body)));
