/*
 * Portfolios (IdnoPlugins/Portfolios). No dependencies; used by every look.
 *
 * "Add to portfolio" in a post's menu is a link to the picker page. Here the picker is
 * fetched and shown in a dialog, and saved without leaving the page. Without this script,
 * or when anything fails, the link and the form work as plain pages.
 */
(function () {
    'use strict';

    var dialog = null;

    function supported() {
        return !!(window.fetch && window.DOMParser && window.FormData && window.HTMLDialogElement);
    }

    function getDialog() {
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.className = 'idno-portfolio-dialog';
            dialog.setAttribute('aria-labelledby', 'portfolio-picker-title');
            document.body.appendChild(dialog);
            // A click on the backdrop closes it
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    dialog.close();
                }
            });
        }

        return dialog;
    }

    function toast(text) {
        var note = document.createElement('div');
        note.className = 'idno-portfolio-toast';
        note.setAttribute('role', 'status');
        note.textContent = text;
        document.body.appendChild(note);
        window.setTimeout(function () {
            note.remove();
        }, 2500);
    }

    function openPicker(url) {
        var box = getDialog();
        box.textContent = '';
        var loading = document.createElement('p');
        loading.className = 'idno-portfolio-dialog-loading';
        loading.textContent = '…';
        box.appendChild(loading);
        box.showModal();

        fetch(url, {credentials: 'same-origin'}).then(function (response) {
            if (!response.ok) {
                throw new Error('status ' + response.status);
            }

            return response.text();
        }).then(function (html) {
            var form = new DOMParser().parseFromString(html, 'text/html').querySelector('form[data-portfolio-picker]');
            if (!form) {
                throw new Error('no picker');
            }
            form = document.importNode(form, true);
            // Come back to this page when the form is posted the plain way
            var back = form.querySelector('input[name="return"]');
            if (back) {
                back.value = window.location.href;
            }
            box.textContent = '';
            box.appendChild(form);
            var first = form.querySelector('input[type="checkbox"], input[type="text"]');
            if (first) {
                first.focus();
            }
        }).catch(function () {
            box.close();
            window.location.href = url;
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target.closest ? event.target : null;
        if (!target) {
            return;
        }
        var link = target.closest('a[data-portfolio-pick]');
        if (link && supported() && !event.metaKey && !event.ctrlKey && !event.shiftKey && event.button === 0) {
            event.preventDefault();
            var menu = link.closest('details');
            if (menu) {
                menu.open = false;
            }
            openPicker(link.href);

            return;
        }
        var cancel = target.closest('[data-portfolio-picker-cancel]');
        if (cancel && dialog && dialog.contains(cancel)) {
            event.preventDefault();
            dialog.close();
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches) {
            return;
        }
        // Deleting a portfolio asks first
        if (form.matches('form[data-portfolio-confirm]')) {
            if (!window.confirm(form.getAttribute('data-portfolio-confirm'))) {
                event.preventDefault();
            }

            return;
        }
        if (!form.matches('form[data-portfolio-picker]') || !dialog || !dialog.contains(form) || !supported()) {
            return;
        }
        event.preventDefault();
        var button = form.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
        }
        fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin'}).then(function (response) {
            if (!response.ok) {
                throw new Error('status ' + response.status);
            }
            dialog.close();
            // On a portfolio's own page the list has changed: show it
            if (document.querySelector('[data-portfolio]')) {
                window.location.reload();

                return;
            }
            toast(form.getAttribute('data-saved-text') || 'Saved');
        }).catch(function () {
            // Fall back to posting the form as a plain page
            form.removeAttribute('data-portfolio-picker');
            form.submit();
        });
    });
})();
