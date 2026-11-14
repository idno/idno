import Alpine from 'alpinejs';
import { createEditor } from './editor-core.js';

Alpine.data('tiptapEditor', (uniqueId) => {
    // The editor instance lives in this closure rather than in Alpine's
    // reactive data. Alpine wraps reactive data in a Proxy, and ProseMirror
    // rejects transactions built through one ("Applying a mismatched
    // transaction"), which breaks every toolbar command.
    let editor = null;

    return {
        // Bumped on every editor transaction so that bindings which read
        // `editor` (e.g. the toolbar's active states) re-evaluate.
        tick: 0,

        get editor() {
            void this.tick;
            return editor;
        },

        init() {
            const container = document.getElementById(`${uniqueId}-editor`);
            const textarea = document.getElementById(uniqueId);

            editor = createEditor(container, {
                content: textarea.value,
                onChange: (html) => {
                    textarea.value = html;
                },
                onTransaction: () => {
                    this.tick++;
                },
            });
            this.tick++;
        },

        toggleLink() {
            if (this.editor.isActive('link')) {
                this.editor.chain().focus().unsetLink().run();
            } else {
                const url = prompt('Enter URL');
                if (url) {
                    this.editor.chain().focus().setLink({ href: url }).run();
                }
            }
        },

        addImage() {
            const url = prompt('Enter image URL');
            if (url) {
                this.editor.chain().focus().setImage({ src: url }).run();
            }
        },

        setHeading(value) {
            if (value === 'paragraph') {
                this.editor.chain().focus().setParagraph().run();
            } else {
                this.editor.chain().focus().toggleHeading({ level: parseInt(value) }).run();
            }
        },
    };
});
