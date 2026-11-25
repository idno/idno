<?php

    /**
     * What an upload form accepts, stated in the form, and a check in the browser before
     * anything is sent. Draw it inside the <form>, by the file input. Used by both looks:
     * the markup has classes of its own and the script is plain JavaScript.
     *
     * The server decides (see \Idno\Core\Upload); the script only saves the member a wait
     * and keeps what they typed on the page, which a request over post_max_size cannot.
     *
     *   'kinds'    string  The kinds of file accepted, as a phrase ("JPEG, PNG or GIF"). Escaped here.
     *   'several'  bool    The form takes more than one file
     *   'required' string  If not empty: the form must carry a file, and this is said when it does not
     */

    $language = \Idno\Core\Idno::site()->language();
    $max_file = \Idno\Core\Upload::maxFileSize();
    $max_total = \Idno\Core\Upload::maxRequestSize();
    $several = !empty($vars['several']);
    $kinds = isset($vars['kinds']) ? trim((string) $vars['kinds']) : '';
    $required = isset($vars['required']) ? trim((string) $vars['required']) : '';

    // Template variables stay set between draws
    unset($this->vars['kinds'], $this->vars['several'], $this->vars['required']);

    $said = [];
    if ($kinds !== '') {
        $said[] = htmlspecialchars($kinds . '.', ENT_QUOTES, 'UTF-8');
    }
    if ($limit_text = \Idno\Core\Upload::limitText($several)) {
        $said[] = $limit_text; // Already escaped
    }

    $message_file = $max_file ? $language->_('"%s" is %s, and this site accepts files of up to %s. Please choose a smaller file.', ['{name}', '{size}', \Idno\Core\Upload::formatSize($max_file)]) : '';
    $message_total = $max_total ? $language->_('Together these files are %s, and this site accepts %s at once. Please choose smaller files, or fewer of them.', ['{size}', \Idno\Core\Upload::formatSize($max_total)]) : '';

    global $idno_upload_limits_drawn;
    $first = empty($idno_upload_limits_drawn);
    $idno_upload_limits_drawn = true;

?>
<p class="idno-upload-limits"
   data-upload-limits
   data-max-file="<?php echo (int) $max_file ?>"
   data-max-total="<?php echo (int) $max_total ?>"
   data-message-file="<?php echo htmlspecialchars($message_file, ENT_QUOTES, 'UTF-8') ?>"
   data-message-total="<?php echo htmlspecialchars($message_total, ENT_QUOTES, 'UTF-8') ?>"
   <?php if ($required !== '') { ?>data-message-required="<?php echo htmlspecialchars($required, ENT_QUOTES, 'UTF-8') ?>"<?php } ?>><?php echo implode(' ', $said) ?></p>
<p class="idno-upload-error" data-upload-error role="alert" hidden></p>
<?php if ($first) { ?>
<style>
    .idno-upload-limits { margin: 0.5em 0; font-size: 0.85em; opacity: 0.75; }
    .idno-upload-error { margin: 0.5em 0; padding: 0.6em 0.8em; border: 1px solid #c0392b; border-radius: 6px; color: #c0392b; background: rgba(192, 57, 43, 0.08); font-size: 0.9em; }
    .idno-upload-error[hidden] { display: none; }
</style>
<script>
    (function () {
        if (window.idnoUploadLimits) {
            return;
        }
        window.idnoUploadLimits = true;

        function size(bytes) {
            var units = ['bytes', 'KB', 'MB', 'GB'], unit = 0;
            while (bytes >= 1024 && unit < units.length - 1) {
                bytes /= 1024;
                unit++;
            }
            return (unit ? Math.round(bytes * 10) / 10 : bytes) + ' ' + units[unit];
        }

        function limitsOf(form) {
            return form ? form.querySelector('[data-upload-limits]') : null;
        }

        function number(limits, name) {
            return parseInt(limits.getAttribute(name), 10) || 0;
        }

        // textContent, so a file name is never read as markup
        function say(form, text) {
            var box = form.querySelector('[data-upload-error]');
            if (!box) {
                return;
            }
            box.textContent = text || '';
            box.hidden = !text;
            if (text && box.scrollIntoView) {
                box.scrollIntoView({block: 'center'});
            }
        }

        function refuse(event) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }

        // A file larger than the server keeps is refused as soon as it is chosen
        document.addEventListener('change', function (event) {
            var input = event.target;
            if (!input || input.type !== 'file' || !input.form) {
                return;
            }
            var limits = limitsOf(input.form);
            if (!limits) {
                return;
            }
            var max = number(limits, 'data-max-file');
            var files = input.files || [];
            for (var i = 0; i < files.length; i++) {
                if (max && files[i].size > max) {
                    say(input.form, limits.getAttribute('data-message-file').replace('{name}', files[i].name).replace('{size}', size(files[i].size)));
                    input.value = '';
                    var holder = input.closest('.image-file-input');
                    var preview = holder ? holder.querySelector('img.preview') : null;
                    if (preview) {
                        preview.style.display = 'none';
                        preview.removeAttribute('src');
                    }
                    // The event goes on, so that whatever shows the chosen file sees there is none now
                    return;
                }
            }
            say(input.form, '');
        }, true);

        // Nothing is sent that the server would throw away, or that has no file when one is needed
        document.addEventListener('submit', function (event) {
            var form = event.target;
            var limits = limitsOf(form);
            if (!limits) {
                return;
            }
            var total = 0, count = 0;
            form.querySelectorAll('input[type=file]').forEach(function (input) {
                for (var i = 0; input.files && i < input.files.length; i++) {
                    total += input.files[i].size;
                    count++;
                }
            });
            var max = number(limits, 'data-max-total');
            // The rest of the form travels in the same request: leave room for it
            if (max && count && total + 65536 > max) {
                refuse(event);
                say(form, limits.getAttribute('data-message-total').replace('{size}', size(total)));
            } else if (!count && limits.hasAttribute('data-message-required')) {
                refuse(event);
                say(form, limits.getAttribute('data-message-required'));
            } else {
                say(form, '');
            }
        }, true);
    })();
</script>
<?php }
