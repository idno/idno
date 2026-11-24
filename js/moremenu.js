/*
 * Behaviour of the "More" menu of posts, comments and profiles
 * (templates/default/shell/moremenu.tpl.php). No dependencies; used by every look.
 * Without this script the menu is a plain <details> element and still works.
 */
(function () {
    'use strict';

    var selector = 'details[data-idno-more-menu]';

    function items(menu) {
        return Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]'));
    }

    function close(menu, focus) {
        if (!menu.open) {
            return;
        }
        menu.open = false;
        if (focus) {
            menu.querySelector('summary').focus();
        }
    }

    function closeOthers(except) {
        Array.prototype.forEach.call(document.querySelectorAll(selector + '[open]'), function (menu) {
            if (menu !== except) {
                close(menu, false);
            }
        });
    }

    function say(menu, text) {
        var status = menu.querySelector('.idno-more-status');
        if (!status) {
            return;
        }
        status.textContent = text;
        window.setTimeout(function () {
            status.textContent = '';
        }, 2000);
    }

    document.addEventListener('toggle', function (event) {
        var menu = event.target;
        if (!menu.matches || !menu.matches(selector)) {
            return;
        }
        menu.querySelector('summary').setAttribute('aria-expanded', menu.open ? 'true' : 'false');
        if (menu.open) {
            closeOthers(menu);
        }
    }, true);

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('[data-idno-copy-link]') : null;
        if (link && navigator.clipboard && navigator.clipboard.writeText) {
            var menu = link.closest(selector);
            event.preventDefault();
            navigator.clipboard.writeText(link.href).then(function () {
                close(menu, true);
                say(menu, link.getAttribute('data-copied-text') || 'Link copied');
            }, function () {
                window.location.href = link.href;
            });
            return;
        }
        var inside = event.target.closest ? event.target.closest(selector) : null;
        closeOthers(inside);
    });

    document.addEventListener('keydown', function (event) {
        var menu = event.target.closest ? event.target.closest(selector) : null;
        if (!menu) {
            return;
        }
        var list = items(menu);
        var index = list.indexOf(document.activeElement);
        if (event.key === 'Escape') {
            if (menu.open) {
                event.preventDefault();
                close(menu, true);
            }
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (!list.length) {
                return;
            }
            event.preventDefault();
            if (!menu.open) {
                menu.open = true;
            }
            var step = event.key === 'ArrowDown' ? 1 : -1;
            list[(index + step + list.length) % list.length].focus();
        } else if (event.key === 'Home' && index !== -1) {
            event.preventDefault();
            list[0].focus();
        } else if (event.key === 'End' && index !== -1) {
            event.preventDefault();
            list[list.length - 1].focus();
        } else if (event.key === 'Tab' && menu.open) {
            close(menu, false);
        }
    });
})();
