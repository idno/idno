<?php
ob_start();
ob_start(fn ($buffer) => $buffer, 0, 0); // a buffer that cannot be removed
ob_start();
throw new \RuntimeException('thrown in a template');
