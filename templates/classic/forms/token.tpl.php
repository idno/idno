<?php

if (empty($vars['time'])) {
    $vars['time'] = time();
}

    $tokenid = "tid".md5(mt_rand());
    $csrf = \Idno\Core\Bonita\Forms::token($vars['action'], $vars['time']);

?>
<span class="known-security-token" style="display: none;" id="<?php echo $tokenid; ?>"></span>
<input type="hidden" name="__bTs" value="<?php echo $vars['time']?>" />
<input type="hidden" name="__bTk" value="<?php echo $csrf; ?>" />
<input type="hidden" name="__bTa" value="<?php echo htmlentities($vars['action'])?>" />

<?php
if (!isset($this->vars['csrf'])) {
    $this->vars['csrf'] = [];
}
    $this->vars['csrf'][] = [
        'tid' => $tokenid,
        'action' => $vars['action'],
        'time' => $vars['time'],
        'token' => $csrf
    ];

    unset($this->vars['time']);
    unset($this->vars['action']);

