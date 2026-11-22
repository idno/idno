/**
 * @-mention autocomplete — lightweight vanilla JS stub.
 *
 * Replaces the legacy jQuery .mention() plugin. Fetches user data from
 * Known's /search/mentions.json endpoint and shows a simple dropdown
 * when the user types '@' followed by characters in a .mentionable element.
 *
 * This is intentionally minimal and can be fleshed out later with better
 * positioning, keyboard navigation, and richer UI.
 */

let usersCache = null;

/**
 * Fetch the list of mentionable users from the server.
 *
 * @param {string} [baseUrl] – Site base URL.
 * @returns {Promise<Array<{username: string, name: string, image: string}>>}
 */
async function fetchUsers(baseUrl) {
    if (usersCache) return usersCache;

    const base = baseUrl || window.known?.config?.displayUrl || '/';
    const url = new URL('search/mentions.json', base).toString();

    try {
        const res = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) return [];
        usersCache = await res.json();
        return usersCache;
    } catch (e) {
        return [];
    }
}

/**
 * Create the dropdown element used for showing mention suggestions.
 *
 * @returns {HTMLElement}
 */
function createDropdown() {
    const dropdown = document.createElement('div');
    dropdown.className = 'mention-dropdown';
    dropdown.style.cssText =
        'position:absolute;z-index:9999;background:#fff;border:1px solid #ccc;' +
        'border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.15);max-height:200px;' +
        'overflow-y:auto;display:none;';
    document.body.appendChild(dropdown);
    return dropdown;
}

/**
 * Find the @query at the cursor position in a textarea/input.
 *
 * @param {HTMLTextAreaElement|HTMLInputElement} el
 * @returns {{query: string, start: number, end: number}|null}
 */
function getMentionQuery(el) {
    const pos = el.selectionStart;
    const text = el.value.substring(0, pos);
    const match = text.match(/@([\w]*)$/);
    if (!match) return null;
    return {
        query: match[1].toLowerCase(),
        start: match.index,
        end: pos,
    };
}

/**
 * Attach mention autocomplete behaviour to a single element.
 *
 * @param {HTMLTextAreaElement|HTMLInputElement} el
 */
function attachMentions(el) {
    const dropdown = createDropdown();
    let active = false;

    function hide() {
        dropdown.style.display = 'none';
        dropdown.innerHTML = '';
        active = false;
    }

    async function show() {
        const mention = getMentionQuery(el);
        if (!mention) {
            hide();
            return;
        }

        const users = await fetchUsers();
        const filtered = users.filter((u) => {
            const q = mention.query;
            if (!q) return true; // show all when just '@' typed
            return (
                u.username.toLowerCase().includes(q) ||
                u.name.toLowerCase().includes(q)
            );
        }).slice(0, 8);

        if (filtered.length === 0) {
            hide();
            return;
        }

        dropdown.innerHTML = '';
        filtered.forEach((user) => {
            const item = document.createElement('div');
            item.style.cssText =
                'padding:6px 10px;cursor:pointer;display:flex;align-items:center;gap:8px;';
            item.innerHTML =
                `<img src="${user.image || ''}" alt="" style="width:24px;height:24px;border-radius:50%;object-fit:cover;">` +
                `<span><strong>${escapeHtml(user.name)}</strong> <small style="color:#666">@${escapeHtml(user.username)}</small></span>`;

            item.addEventListener('mousedown', (e) => {
                e.preventDefault(); // keep focus on textarea
                const before = el.value.substring(0, mention.start);
                const after = el.value.substring(mention.end);
                el.value = `${before}@${user.username} ${after}`;
                const newPos = mention.start + user.username.length + 2; // +2 for @ and space
                el.setSelectionRange(newPos, newPos);
                hide();
            });

            item.addEventListener('mouseenter', () => {
                item.style.background = '#f0f0f0';
            });
            item.addEventListener('mouseleave', () => {
                item.style.background = '';
            });

            dropdown.appendChild(item);
        });

        // Position dropdown near the element
        const rect = el.getBoundingClientRect();
        dropdown.style.left = `${rect.left + window.scrollX}px`;
        dropdown.style.top = `${rect.bottom + window.scrollY + 2}px`;
        dropdown.style.width = `${Math.min(rect.width, 320)}px`;
        dropdown.style.display = 'block';
        active = true;
    }

    el.addEventListener('input', show);
    el.addEventListener('blur', () => {
        // Small delay so mousedown on dropdown item fires first
        setTimeout(hide, 150);
    });
    el.addEventListener('keydown', (e) => {
        if (active && e.key === 'Escape') {
            hide();
        }
    });
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

/**
 * Initialise mention autocomplete on all .mentionable elements.
 */
export function initMentions() {
    document.querySelectorAll('textarea.mentionable, input.mentionable').forEach((el) => {
        // Avoid double-binding
        if (el.dataset.mentionsBound) return;
        el.dataset.mentionsBound = '1';
        attachMentions(el);
    });
}

// Auto-init on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMentions);
} else {
    initMentions();
}
