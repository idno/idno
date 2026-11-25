<?php

    /*
     * The script of the email settings form, for both looks, in plain JavaScript: choosing a
     * kind of security suggests its usual port, and the "Fill in" buttons of the help block
     * put a service's values into the form. It holds no setting and no address of the site.
     */
    ?>
<script>
    (function () {
        var form = document.getElementById('mail-settings-form');
        if (!form) {
            return;
        }
        var host = form.querySelector('[name="smtp_host"]');
        var port = form.querySelector('[name="smtp_port"]');
        var secure = form.querySelector('[name="smtp_secure"]');
        var user = form.querySelector('[name="smtp_username"]');
        var usual = {tls: '587', ssl: '465', '': '25'};

        // The port follows the security setting unless the administrator has typed another one
        function suggestPort() {
            var current = port.value.trim();
            if (current === '' || current === '25' || current === '465' || current === '587') {
                port.value = usual[secure.value] || '';
            }
        }
        if (port && secure) {
            secure.addEventListener('change', suggestPort);
        }

        var buttons = document.querySelectorAll('.mail-help-fill');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].hidden = false;
            buttons[i].addEventListener('click', function () {
                host.value = this.getAttribute('data-mail-host');
                secure.value = 'tls';
                port.value = '587';
                if (this.getAttribute('data-mail-user')) {
                    user.value = this.getAttribute('data-mail-user');
                }
                host.scrollIntoView({block: 'center'});
                (this.getAttribute('data-mail-user') ? form.querySelector('[name="smtp_password"]') : user).focus();
            });
        }
    })();
</script>
