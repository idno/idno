const AUTOSAVE_INTERVAL = 10000; // 10 seconds

export function initAutosave(prefix, fieldIds, overrides = {}) {
    const storageKey = `idno_autosave_${prefix}`;

    function save() {
        const data = {};
        for (const id of fieldIds) {
            const selector = overrides[id] || `#${id}`;
            const el = document.querySelector(selector);
            if (el) {
                data[id] = el.value || el.innerHTML || '';
            }
        }
        try {
            localStorage.setItem(storageKey, JSON.stringify(data));
        } catch (e) {
            // localStorage full or unavailable
        }
    }

    function restore() {
        try {
            const raw = localStorage.getItem(storageKey);
            if (!raw) return;
            const data = JSON.parse(raw);
            for (const id of fieldIds) {
                if (data[id]) {
                    const selector = overrides[id] || `#${id}`;
                    const el = document.querySelector(selector);
                    if (el && !el.value) {
                        el.value = data[id];
                    }
                }
            }
        } catch (e) {
            // parse error
        }
    }

    function clear() {
        localStorage.removeItem(storageKey);
    }

    restore();
    const interval = setInterval(save, AUTOSAVE_INTERVAL);

    return { save, restore, clear, stop: () => clearInterval(interval) };
}

// Make available globally for template inline scripts
window.idnoAutosave = initAutosave;
