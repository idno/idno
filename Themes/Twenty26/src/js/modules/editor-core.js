import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import Placeholder from '@tiptap/extension-placeholder';
import CodeBlock from '@tiptap/extension-code-block';

export function createEditor(element, options = {}) {
    const { content = '', placeholder = 'Write something...', onChange, onTransaction } = options;

    const editor = new Editor({
        element,
        extensions: [
            StarterKit.configure({
                codeBlock: false,
                // Tiptap 3's StarterKit bundles these. Link is registered
                // separately below with our own options; the other two are
                // off so saved HTML stays the same as it was under Tiptap 2.
                link: false,
                underline: false,
                trailingNode: false,
            }),
            Link.configure({
                openOnClick: false,
                HTMLAttributes: {
                    rel: 'noopener noreferrer',
                },
            }),
            Image,
            Placeholder.configure({ placeholder }),
            CodeBlock,
        ],
        content,
        onUpdate({ editor }) {
            if (onChange) {
                onChange(editor.getHTML());
            }
        },
        onTransaction() {
            if (onTransaction) {
                onTransaction();
            }
        },
    });

    // Tell Alpine's reactivity system (@vue/reactivity) never to wrap the
    // editor in a Proxy, even when it is returned from reactive data.
    Object.defineProperty(editor, '__v_skip', { value: true });

    return editor;
}
