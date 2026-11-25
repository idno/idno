/*
 * Moderation plugin: the report dialog and the confirmation dialog. No dependencies.
 *
 * Without this script "Report" is a link to the report form on its own page, and the
 * moderators' buttons act at once.
 */
(function () {
    'use strict';

    if (typeof HTMLDialogElement === 'undefined' || !window.fetch || !window.DOMParser) {
        return;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    /**
     * A modal dialog with a heading and a close button. The browser keeps focus inside it,
     * closes it on Escape and gives focus back to what opened it.
     */
    function makeDialog(title, closeLabel) {
        var dialog = el('dialog', 'mod-dialog');
        var head = el('div', 'mod-dialog-head');
        var heading = el('h2', '', title);
        heading.id = 'mod-dialog-title-' + Math.random().toString(36).slice(2);
        var close = el('button', 'mod-dialog-close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', closeLabel || 'Close');
        var body = el('div', 'mod-dialog-body');
        head.appendChild(heading);
        head.appendChild(close);
        dialog.appendChild(head);
        dialog.appendChild(body);
        dialog.setAttribute('aria-labelledby', heading.id);
        close.addEventListener('click', function () {
            dialog.close();
        });
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close(); // A click on the backdrop
            }
        });
        dialog.addEventListener('close', function () {
            dialog.remove();
        });
        document.body.appendChild(dialog);
        return { dialog: dialog, heading: heading, body: body };
    }

    // ------------------------------------------------------------------ Reporting

    function openReport(link) {
        var opener = link.closest('details') ? link.closest('details').querySelector('summary') : link;
        fetch(link.href, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('status ' + response.status);
                }
                return response.text();
            })
            .then(function (html) {
                var page = new DOMParser().parseFromString(html, 'text/html');
                var form = page.querySelector('form[data-mod-report-form]');
                if (!form) {
                    throw new Error('no form');
                }
                var menu = link.closest('details');
                if (menu) {
                    menu.open = false;
                }
                showReport(document.importNode(form, true), opener);
            })
            .catch(function () {
                window.location.href = link.href;
            });
    }

    function showReport(form, opener) {
        var title = form.querySelector('[data-mod-report-title]');
        var parts = makeDialog(title ? title.textContent : '', form.getAttribute('data-close-label'));
        var wrapper = el('div', 'mod-report-page');
        wrapper.appendChild(form);
        parts.body.appendChild(wrapper);

        var steps = form.querySelectorAll('[data-mod-step]');
        var actions = form.querySelector('.mod-report-actions');
        var cancel = form.querySelector('[data-mod-cancel]');
        var error = form.querySelector('[data-mod-report-error]');
        var textarea = form.querySelector('textarea[name="description"]');
        var counter = form.querySelector('[data-mod-count]');
        var chosen = form.querySelector('[data-mod-chosen-reason]');

        function show(step) {
            Array.prototype.forEach.call(steps, function (node) {
                node.hidden = node.getAttribute('data-mod-step') !== String(step);
            });
            if (actions && steps.length) {
                actions.hidden = step === 1;
            }
            if (step === 2 && textarea) {
                textarea.focus();
            }
        }

        if (cancel) {
            // In the dialog "Cancel" becomes "Back": the close button cancels
            var back = el('button', 'mod-btn mod-btn-ghost', '‹ ' + (form.getAttribute('data-back-label') || 'Back'));
            back.type = 'button';
            cancel.replaceWith(back);
            back.addEventListener('click', function () {
                if (steps.length) {
                    show(1);
                    var checked = form.querySelector('input[name="reason"]:checked');
                    (checked || form.querySelector('input[name="reason"]')).focus();
                } else {
                    parts.dialog.close();
                }
            });
        }

        Array.prototype.forEach.call(form.querySelectorAll('input[name="reason"]'), function (radio) {
            var label = radio.closest('label');
            function choose() {
                radio.checked = true;
                if (chosen) {
                    chosen.textContent = label.querySelector('strong').textContent;
                }
                show(2);
            }
            // A click (mouse, touch, or Space / Enter on the focused option) moves on;
            // arrow keys only move between the options
            label.addEventListener('click', function (event) {
                event.preventDefault();
                choose();
            });
            radio.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    choose();
                }
            });
        });

        if (textarea && counter) {
            var count = function () {
                counter.textContent = String(textarea.value.length);
            };
            textarea.addEventListener('input', count);
            count();
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var button = form.querySelector('button[type="submit"]');
            var data = new FormData(form);
            data.append('response', 'json');
            if (button) {
                button.disabled = true;
            }
            if (error) {
                error.hidden = true;
            }
            fetch(form.action, { method: 'POST', body: new URLSearchParams(data), credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (response) {
                    return response.json().then(function (json) {
                        return { ok: response.ok && json.ok, message: json.message };
                    });
                })
                .then(function (result) {
                    if (!result.ok) {
                        throw new Error(result.message || '');
                    }
                    showDone(parts, form);
                })
                .catch(function (problem) {
                    if (button) {
                        button.disabled = false;
                    }
                    if (error) {
                        error.textContent = (problem && problem.message) || form.getAttribute('data-error-text') || '';
                        error.hidden = false;
                        error.focus && error.focus();
                    }
                });
        });

        if (steps.length) {
            show(1);
        }
        parts.dialog.addEventListener('close', function () {
            if (opener && opener.focus) {
                opener.focus();
            }
        });
        parts.dialog.showModal();
        var first = form.querySelector('input[name="reason"]');
        if (first) {
            first.focus();
        }
    }

    function showDone(parts, form) {
        var done = el('div', 'mod-done');
        var mark = el('div', 'mod-done-mark', '✓');
        mark.setAttribute('aria-hidden', 'true');
        var heading = el('h3', '', form.getAttribute('data-done-title') || '');
        heading.tabIndex = -1;
        done.appendChild(mark);
        done.appendChild(heading);
        done.appendChild(el('p', '', form.getAttribute('data-done-text') || ''));
        done.appendChild(el('p', 'mod-muted', form.getAttribute('data-done-emergency') || ''));
        var button = el('button', 'mod-btn mod-btn-primary', form.getAttribute('data-done-button') || 'Done');
        button.type = 'button';
        button.addEventListener('click', function () {
            parts.dialog.close();
        });
        done.appendChild(button);
        done.setAttribute('role', 'status');
        parts.body.textContent = '';
        parts.body.appendChild(done);
        button.focus();
    }

    // ------------------------------------------------------------------ Confirmations

    function confirmSubmit(form) {
        var parts = makeDialog(form.getAttribute('data-mod-confirm-title') || '', 'Close');
        var box = el('div', 'mod-confirm');
        box.appendChild(el('p', '', form.getAttribute('data-mod-confirm-text') || ''));
        var buttons = el('div', 'mod-confirm-buttons');
        var cancel = el('button', 'mod-btn mod-btn-ghost', 'Cancel');
        cancel.type = 'button';
        var go = el('button', 'mod-btn mod-btn-danger', form.getAttribute('data-mod-confirm-button') || 'Confirm');
        go.type = 'button';
        buttons.appendChild(cancel);
        buttons.appendChild(go);
        box.appendChild(buttons);
        parts.body.appendChild(box);
        cancel.addEventListener('click', function () {
            parts.dialog.close();
        });
        go.addEventListener('click', function () {
            go.disabled = true;
            form.setAttribute('data-mod-confirmed', '1');
            form.submit();
        });
        parts.dialog.showModal();
        cancel.focus();
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-mod-report]') : null;
        if (link && !event.metaKey && !event.ctrlKey && !event.shiftKey && event.button === 0) {
            event.preventDefault();
            openReport(link);
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.hasAttribute && form.hasAttribute('data-mod-confirm-title') && !form.hasAttribute('data-mod-confirmed')) {
            event.preventDefault();
            confirmSubmit(form);
        }
    });

    // The counter on the report form's own page
    document.addEventListener('input', function (event) {
        var textarea = event.target;
        if (textarea.matches && textarea.matches('.mod-report textarea[name="description"]')) {
            var counter = textarea.form.querySelector('[data-mod-count]');
            if (counter) {
                counter.textContent = String(textarea.value.length);
            }
        }
    });
})();
