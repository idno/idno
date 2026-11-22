<?php
echo 'var=' . json_encode($slots ?? null) . ' prop=' . json_encode($this->slots) . '|';
$this->slots = 'assigned';
echo 'after=' . json_encode($this->slots) . '|';
