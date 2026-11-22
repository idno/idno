<?php
echo '[parent t=' . (isset($t) ? 'set' : 'unset') . ' this=' . ($this === $t ? 'same' : 'other') . ' vars=' . json_encode($vars) . ' a=' . json_encode($a ?? null) . ' child=' . $t->draw('child') . ']';
