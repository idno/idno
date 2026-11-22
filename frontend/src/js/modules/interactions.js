/**
 * Interaction handlers for Known — ported from jQuery to vanilla JS fetch().
 *
 * Handles:
 *   - Star/like toggle  (POST annotation/post  with type=like)
 *   - Comment/reply      (POST annotation/post  with type=reply)
 *   - Annotation delete  (POST {objectUrl}/annotation/delete)
 *   - CSRF token refresh
 */

/**
 * Fetch a fresh CSRF token from the server.
 *
 * @param {string} [pageUrl] – The action URL to get a token for.
 *   Falls back to the current page URL exposed by Known's global config.
 * @returns {Promise<{token: string, time: number}>}
 */
export async function getCSRFToken(pageUrl) {
    const base = (window.known?.config?.displayUrl) || '/';
    const url = new URL('service/security/csrftoken/', base);
    if (pageUrl) {
        url.searchParams.set('url', pageUrl);
    }

    const res = await fetch(url.toString(), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!res.ok) throw new Error(`CSRF token request failed: ${res.status}`);
    return res.json();
}

/**
 * Toggle a star/like on an object.
 *
 * The existing jQuery code submits the form created by `createLink()` via
 * AJAX POST to `annotation/post` with fields: type, object, __bTk, __bTs.
 *
 * @param {HTMLFormElement} form – The hidden form rendered by createLink().
 * @returns {Promise<{number: number, text: string}>}
 */
export async function toggleStar(form) {
    const actionUrl = form.getAttribute('action');
    const formData = new FormData(form);

    const res = await fetch(actionUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!res.ok) throw new Error(`Star toggle failed: ${res.status}`);
    return res.json();
}

/**
 * Post a comment / reply on an object.
 *
 * @param {HTMLFormElement} form – The comment form (action = annotation/post).
 * @returns {Promise<Response>}
 */
export async function postComment(form) {
    const actionUrl = form.getAttribute('action');
    const formData = new FormData(form);

    const res = await fetch(actionUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!res.ok) throw new Error(`Comment post failed: ${res.status}`);
    return res;
}

/**
 * Delete an annotation.
 *
 * @param {HTMLFormElement} form – The delete form rendered by createLink().
 * @returns {Promise<Response>}
 */
export async function deleteAnnotation(form) {
    const actionUrl = form.getAttribute('action');
    const formData = new FormData(form);

    const res = await fetch(actionUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!res.ok) throw new Error(`Annotation delete failed: ${res.status}`);
    return res;
}

/**
 * Refresh all CSRF tokens on the page.
 * Mirrors Security.refreshTokens() from the legacy JS.
 */
export async function refreshTokens() {
    const tokenEls = document.querySelectorAll('.known-security-token');
    for (const el of tokenEls) {
        const form = el.closest('form');
        if (!form) continue;
        const actionInput = form.querySelector('input[name=__bTa]');
        const actionUrl = actionInput ? actionInput.value : undefined;
        try {
            const { token, time } = await getCSRFToken(actionUrl);
            const tkInput = form.querySelector('input[name=__bTk]');
            const tsInput = form.querySelector('input[name=__bTs]');
            if (tkInput) tkInput.value = token;
            if (tsInput) tsInput.value = time;
        } catch (e) {
            // silently ignore token refresh failures
        }
    }
}

// -----------------------------------------------------------------------
// DOM wiring — runs on DOMContentLoaded
// -----------------------------------------------------------------------

function bindStarToggles() {
    document.querySelectorAll('.interactions .annotate-icon a.stars-toggle').forEach((link) => {
        const formId = link.getAttribute('data-form-id');
        if (!formId) return;
        const form = document.getElementById(formId);
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const data = await toggleStar(form);
                // Toggle the star icon
                const star = link.querySelector('i.fa');
                if (star) {
                    if (star.classList.contains('fa-star') && star.classList.contains('far')) {
                        star.classList.remove('far');
                        star.classList.add('fas');
                    } else {
                        star.classList.remove('fas');
                        star.classList.add('far');
                    }
                }
                // Update the text
                const starText = link.closest('span.annotate-icon')?.querySelector('a.stars');
                if (starText && data.text) {
                    starText.textContent = data.text;
                }
            } catch (err) {
                console.error('Star toggle error:', err);
            }
        });
    });
}

function bindCommentForms() {
    document.querySelectorAll('form[action$="annotation/post"]').forEach((form) => {
        // Only bind forms that have type=reply (comment forms, not star forms)
        const typeInput = form.querySelector('input[name=type][value=reply]');
        if (!typeInput) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const res = await postComment(form);
                // On success reload to show the new comment
                window.location.reload();
            } catch (err) {
                console.error('Comment post error:', err);
            }
        });
    });
}

function bindCtrlEnterSubmit() {
    document.querySelectorAll('.ctrl-enter-submit').forEach((el) => {
        el.addEventListener('keypress', (event) => {
            const keyCode = event.which || event.keyCode;
            if ((keyCode === 10 || keyCode === 13) && (event.ctrlKey || event.metaKey)) {
                const form = el.closest('form');
                if (form) {
                    form.requestSubmit();
                }
            }
        });
    });
}

/**
 * Initialise all interaction handlers.
 * Called automatically on DOMContentLoaded but can also be called manually
 * after dynamically inserting new content.
 */
export function initInteractions() {
    bindStarToggles();
    bindCommentForms();
    bindCtrlEnterSubmit();
}

// Auto-init on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initInteractions);
} else {
    initInteractions();
}

// Periodically refresh CSRF tokens (every 5 minutes, matching legacy code)
setInterval(refreshTokens, 300000);
