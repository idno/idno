<?php
    $unique_id = !empty($vars['unique_id']) ? $vars['unique_id'] : 'editor-' . rand(0, 9999);
    $name = !empty($vars['name']) ? $vars['name'] : 'body';
    $value = !empty($vars['value']) ? $vars['value'] : '';
    $placeholder = !empty($vars['placeholder']) ? $vars['placeholder'] : \Idno\Core\Idno::site()->language()->_('Write something...');
    $required = !empty($vars['required']);
?>
<div class="idno-editor" x-data="tiptapEditor('<?= $unique_id ?>')">
    <div class="idno-editor-toolbar">
        <button type="button" class="idno-editor-toolbar-btn" title="Bold" x-on:click="editor.chain().focus().toggleBold().run()" :class="{ 'active': editor?.isActive('bold') }">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 12h9a4 4 0 0 1 0 8H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h7a4 4 0 0 1 0 8"/></svg>
        </button>
        <button type="button" class="idno-editor-toolbar-btn" title="Italic" x-on:click="editor.chain().focus().toggleItalic().run()" :class="{ 'active': editor?.isActive('italic') }">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" x2="10" y1="4" y2="4"/><line x1="14" x2="5" y1="20" y2="20"/><line x1="15" x2="9" y1="4" y2="20"/></svg>
        </button>
        <button type="button" class="idno-editor-toolbar-btn" title="Strikethrough" x-on:click="editor.chain().focus().toggleStrike().run()" :class="{ 'active': editor?.isActive('strike') }">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4H9a3 3 0 0 0-2.83 4"/><path d="M14 12a4 4 0 0 1 0 8H6"/><line x1="4" x2="20" y1="12" y2="12"/></svg>
        </button>
        <button type="button" class="idno-editor-toolbar-btn" title="Inline code" x-on:click="editor.chain().focus().toggleCode().run()" :class="{ 'active': editor?.isActive('code') }">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
        </button>
        <div class="idno-editor-toolbar-divider"></div>
        <!-- Paragraph style dropdown -->
        <select class="idno-editor-toolbar-btn" style="width:auto;padding:0 0.5rem;font-size:var(--font-size-sm)"
                x-on:change="setHeading($event.target.value)" x-ref="headingSelect">
            <option value="paragraph">Paragraph</option>
            <option value="1">Heading 1</option>
            <option value="2">Heading 2</option>
            <option value="3">Heading 3</option>
        </select>
        <div class="idno-editor-toolbar-divider"></div>
        <button type="button" class="idno-editor-toolbar-btn" title="Link" x-on:click="toggleLink()">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        </button>
        <button type="button" class="idno-editor-toolbar-btn" title="Image" x-on:click="addImage()">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
        </button>
        <div class="idno-editor-toolbar-divider"></div>
        <button type="button" class="idno-editor-toolbar-btn" title="Blockquote" x-on:click="editor.chain().focus().toggleBlockquote().run()" :class="{ 'active': editor?.isActive('blockquote') }">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 6H3"/><path d="M21 12H8"/><path d="M21 18H8"/><path d="M3 12v6"/></svg>
        </button>
        <button type="button" class="idno-editor-toolbar-btn" title="Bullet list" x-on:click="editor.chain().focus().toggleBulletList().run()" :class="{ 'active': editor?.isActive('bulletList') }">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/></svg>
        </button>
        <button type="button" class="idno-editor-toolbar-btn" title="Numbered list" x-on:click="editor.chain().focus().toggleOrderedList().run()" :class="{ 'active': editor?.isActive('orderedList') }">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="10" x2="21" y1="6" y2="6"/><line x1="10" x2="21" y1="12" y2="12"/><line x1="10" x2="21" y1="18" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>
        </button>
        <div class="idno-editor-toolbar-divider"></div>
        <button type="button" class="idno-editor-toolbar-btn" title="Code block" x-on:click="editor.chain().focus().toggleCodeBlock().run()" :class="{ 'active': editor?.isActive('codeBlock') }">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 9.5 8 12l2 2.5"/><path d="m14 9.5 2 2.5-2 2.5"/><rect width="18" height="18" x="3" y="3" rx="2"/></svg>
        </button>
        <button type="button" class="idno-editor-toolbar-btn" title="Horizontal rule" x-on:click="editor.chain().focus().setHorizontalRule().run()">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" x2="19" y1="12" y2="12"/></svg>
        </button>
    </div>
    <div class="idno-editor-content" id="<?= $unique_id ?>-editor"></div>
    <textarea name="<?= htmlspecialchars($name) ?>" id="<?= $unique_id ?>" style="display:none" <?= $required ? 'required' : '' ?>><?= htmlspecialchars($value) ?></textarea>
</div>
