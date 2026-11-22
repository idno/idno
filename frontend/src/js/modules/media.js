/**
 * Media upload module — ported from jQuery to vanilla JS FormData + fetch().
 *
 * Provides:
 *   - uploadFile()    — general file upload to /file/upload/
 *   - uploadImage()   — image upload via /file/picker/ (creates thumbnail)
 *   - previewImage()  — client-side image preview before upload
 *   - initMediaInputs() — auto-wires file inputs with class .image-file-input
 */

/**
 * Upload a file to Known's file upload endpoint.
 *
 * @param {File} file – The File object to upload.
 * @param {object} [options]
 * @param {string} [options.baseUrl] – Site base URL. Defaults to known.config.displayUrl.
 * @param {function} [options.onProgress] – Progress callback (0-100).
 * @returns {Promise<string>} – The URL of the uploaded file.
 */
export function uploadFile(file, options = {}) {
    const base = options.baseUrl || window.known?.config?.displayUrl || '/';
    const url = new URL('file/upload/', base).toString();

    const formData = new FormData();
    formData.append('file', file);

    // Use XMLHttpRequest for progress support
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.withCredentials = true;
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        if (options.onProgress) {
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    options.onProgress(Math.round((e.loaded / e.total) * 100));
                }
            });
        }

        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    // The upload endpoint returns a JSON-encoded URL string
                    const fileUrl = JSON.parse(xhr.responseText);
                    resolve(fileUrl);
                } catch (e) {
                    resolve(xhr.responseText);
                }
            } else {
                reject(new Error(`Upload failed: ${xhr.status}`));
            }
        });

        xhr.addEventListener('error', () => reject(new Error('Upload network error')));
        xhr.addEventListener('abort', () => reject(new Error('Upload aborted')));

        xhr.send(formData);
    });
}

/**
 * Upload an image via the picker endpoint (creates a thumbnail).
 *
 * @param {File} file – The image File object.
 * @param {object} [options]
 * @param {string} [options.baseUrl] – Site base URL.
 * @param {function} [options.onProgress] – Progress callback (0-100).
 * @returns {Promise<string>} – The URL of the uploaded image.
 */
export async function uploadImage(file, options = {}) {
    const base = options.baseUrl || window.known?.config?.displayUrl || '/';
    const url = new URL('file/picker/', base).toString();

    const formData = new FormData();
    formData.append('file', file);

    const res = await fetch(url, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!res.ok) throw new Error(`Image upload failed: ${res.status}`);

    const text = await res.text();
    // The picker endpoint returns a script fragment; extract the file URL
    // For Idno 2026 we fall back to the plain upload endpoint
    const match = text.match(/file\/([a-f0-9]+)/);
    if (match) {
        return new URL(`file/${match[1]}`, base).toString();
    }
    return text;
}

/**
 * Preview an image file in the browser before uploading.
 *
 * @param {HTMLInputElement} input – The file input element.
 * @param {HTMLImageElement} previewImg – The <img> element to show the preview in.
 * @returns {Promise<string>} – Resolves with the data URL of the preview.
 */
export function previewImage(input, previewImg) {
    return new Promise((resolve, reject) => {
        if (!input.files || !input.files[0]) {
            reject(new Error('No file selected'));
            return;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            if (previewImg) {
                previewImg.src = e.target.result;
                previewImg.style.display = '';
            }
            resolve(e.target.result);
        };
        reader.onerror = () => reject(new Error('File read error'));
        reader.readAsDataURL(input.files[0]);
    });
}

/**
 * Wire up all .image-file-input containers on the page.
 * Each container is expected to have:
 *   - an <input type="file"> element
 *   - an <img class="preview"> element
 *   - a <span class="photo-filename"> element
 */
export function initMediaInputs() {
    document.querySelectorAll('.image-file-input input[type=file]').forEach((input) => {
        input.addEventListener('change', () => {
            const container = input.closest('.image-file-input');
            if (!container) return;

            const img = container.querySelector('.preview');
            const filenameSpan = container.querySelector('.photo-filename');

            if (input.files && input.files[0] && img) {
                previewImage(input, img);
                if (filenameSpan) {
                    const nextText = filenameSpan.getAttribute('data-nexttext');
                    if (nextText) filenameSpan.textContent = nextText;
                }
            }
        });
    });
}

// Auto-init on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMediaInputs);
} else {
    initMediaInputs();
}
