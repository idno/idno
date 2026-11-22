<?php

if (empty($vars['message'])) { return; }
$message = $vars['message'];

// Map Bootstrap alert types to Idno 2026 styling
$type = $message['message_type'] ?? 'alert-info';
$colors = match ($type) {
    'alert-danger'  => 'background:var(--color-error-bg, #fef2f2);color:var(--color-error-text, #991b1b);border-color:var(--color-error-border, #fecaca)',
    'alert-success' => 'background:var(--color-success-bg, #f0fdf4);color:var(--color-success-text, #166534);border-color:var(--color-success-border, #bbf7d0)',
    'alert-warning' => 'background:var(--color-warning-bg, #fffbeb);color:var(--color-warning-text, #92400e);border-color:var(--color-warning-border, #fde68a)',
    default         => 'background:var(--color-info-bg, #eff6ff);color:var(--color-info-text, #1e40af);border-color:var(--color-info-border, #bfdbfe)',
};

?>
<div class="idno-message" style="<?= $colors ?>;border:1px solid;border-radius:var(--radius-sm);padding:0.75rem 1rem;margin-bottom:0.75rem;display:flex;align-items:flex-start;gap:0.5rem;font-size:var(--font-size-sm)"
     x-data="{ show: true }" x-show="show" x-cloak>
    <span style="flex:1"><?= $message['message'] ?></span>
    <button type="button" x-on:click="show = false" style="background:none;border:none;cursor:pointer;padding:0;line-height:1;font-size:1.25rem;opacity:0.5;color:inherit" aria-label="Dismiss">&times;</button>
</div>
