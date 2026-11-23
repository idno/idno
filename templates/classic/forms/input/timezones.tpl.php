<?php

$tz_corrected = [];
$timezones = timezone_identifiers_list();
$now = new DateTime('now', new DateTimeZone('UTC'));
foreach ($timezones as $timezone) {
    $now->setTimezone(new DateTimeZone($timezone));

    $offset = $now->getOffset();
    $hours = intval($offset / 3600);
    $minutes = abs(intval($offset % 3600 / 60));

    $label = '(' . 'GMT' . ($offset ? sprintf('%+03d:%02d', $hours, $minutes) : '') . ') ' . str_replace('/', ', ', $timezone);

    $tz_corrected[$timezone] = $label;
}

if (empty($vars['class'])) { $vars['class'] = "input-timezone";
}
echo $this->__(
    [
    'options' => $tz_corrected
    ]
)->draw('forms/input/select');
