import { useEffect, useId, useRef, useState } from 'react';

const COLORS = [
    { label: 'Black', value: '#000000' },
    { label: 'Dark Green', value: '#14532d' },
    { label: 'Red', value: '#b42318' },
];

const BLOCKED_TAGS = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'TEMPLATE']);
const INLINE_TAGS = new Map([
    ['STRONG', 'strong'], ['B', 'strong'], ['EM', 'em'], ['I', 'em'], ['U', 'u'],
]);

function colorValue(element) {
    const style = element.getAttribute('style') || '';
    const declaration = style.split(';').map(item => item.split(':', 2)).find(([property]) => property?.trim().toLowerCase() === 'color');
    const raw = declaration?.[1] || element.getAttribute('color') || '';
    const value = raw.toLowerCase().replace(/\s/g, '');
    if (value === '#000000' || value === 'black') return '#000000';
    if (value === '#14532d' || value === 'rgb(20,83,45)' || value === 'darkgreen') return '#14532d';
    if (value === '#b42318' || value === 'rgb(180,35,24)' || value === 'darkred') return '#b42318';
    return null;
}

function sanitizeEditorHtml(value) {
    if (typeof document === 'undefined') return value || '';

    const source = document.createElement('div');
    source.innerHTML = value || '';
    const target = document.createElement('div');

    const copy = (node, parent) => {
        node.childNodes.forEach(child => {
            if (child.nodeType === Node.TEXT_NODE) {
                parent.appendChild(document.createTextNode(child.nodeValue || ''));
                return;
            }
            if (child.nodeType !== Node.ELEMENT_NODE) return;

            const tag = child.tagName;
            if (BLOCKED_TAGS.has(tag)) return;
            if (tag === 'BR') {
                parent.appendChild(document.createElement('br'));
                return;
            }
            if (tag === 'P' || tag === 'DIV') {
                const paragraph = document.createElement('p');
                copy(child, paragraph);
                parent.appendChild(paragraph);
                return;
            }
            if (INLINE_TAGS.has(tag)) {
                const inline = document.createElement(INLINE_TAGS.get(tag));
                copy(child, inline);
                parent.appendChild(inline);
                return;
            }
            if (tag === 'SPAN' || tag === 'FONT') {
                const color = colorValue(child);
                if (color) {
                    const span = document.createElement('span');
                    span.style.color = color;
                    copy(child, span);
                    parent.appendChild(span);
                } else {
                    copy(child, parent);
                }
                return;
            }

            copy(child, parent);
        });
    };

    copy(source, target);
    return target.innerHTML;
}

function plainTextHtml(value) {
    if (typeof document === 'undefined') return value || '';
    const container = document.createElement('div');
    container.textContent = value || '';
    return container.innerHTML.replace(/\r?\n/g, '<br>');
}

function editorHtml(value) {
    const stringValue = String(value || '');
    return stringValue.includes('<') ? sanitizeEditorHtml(stringValue) : plainTextHtml(stringValue);
}

function removeFormattingFromSelection(editor) {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0 || !editor.contains(selection.anchorNode)) return false;

    const range = selection.getRangeAt(0);
    if (range.collapsed) return false;

    const fragment = range.extractContents();
    const clean = node => {
        Array.from(node.childNodes).forEach(child => {
            if (child.nodeType !== Node.ELEMENT_NODE) return;
            if (BLOCKED_TAGS.has(child.tagName)) {
                child.remove();
                return;
            }
            if (['STRONG', 'B', 'EM', 'I', 'U', 'SPAN', 'FONT'].includes(child.tagName)) {
                while (child.firstChild) child.parentNode.insertBefore(child.firstChild, child);
                child.remove();
                return;
            }
            clean(child);
        });
    };

    clean(fragment);
    range.insertNode(fragment);
    selection.removeAllRanges();
    selection.addRange(range);
    return true;
}

export default function ComplianceRichTextEditor({ id, label, value = '', onChange, disabled = false, placeholder = '', error = null, rows = 3 }) {
    const generatedId = useId();
    const editorId = id || 'rich-text-editor-' + generatedId.replace(/:/g, '');
    const editorRef = useRef(null);
    const [colorMenuOpen, setColorMenuOpen] = useState(false);
    const [selectionState, setSelectionState] = useState({ bold: false, italic: false, underline: false });

    useEffect(() => {
        const html = editorHtml(value);
        if (editorRef.current && editorRef.current.innerHTML !== html) editorRef.current.innerHTML = html;
    }, [value]);

    const sync = () => {
        if (!editorRef.current) return;
        const html = sanitizeEditorHtml(editorRef.current.innerHTML);
        if (editorRef.current.innerHTML !== html) editorRef.current.innerHTML = html;
        onChange(html);
    };

    const refreshSelectionState = () => {
        if (typeof document === 'undefined') return;
        setSelectionState({
            bold: document.queryCommandState?.('bold') || false,
            italic: document.queryCommandState?.('italic') || false,
            underline: document.queryCommandState?.('underline') || false,
        });
    };

    const command = (name, commandValue = null) => {
        if (disabled || !editorRef.current) return;
        editorRef.current.focus();
        document.execCommand?.('styleWithCSS', false, name === 'foreColor');
        document.execCommand?.(name, false, commandValue);
        sync();
        refreshSelectionState();
    };

    const regular = () => {
        if (disabled || !editorRef.current) return;
        editorRef.current.focus();
        if (!removeFormattingFromSelection(editorRef.current)) document.execCommand?.('removeFormat', false, null);
        sync();
        refreshSelectionState();
    };

    const toolbarButton = (text, title, onClick, active = false) => <button
        type="button"
        title={title}
        aria-label={title}
        aria-pressed={active}
        disabled={disabled}
        onMouseDown={event => event.preventDefault()}
        onClick={onClick}
        className={`rounded px-2 py-1 text-xs font-bold transition ${active ? 'bg-green-700 text-white' : 'text-gray-700 hover:bg-gray-200 dark:text-gray-200 dark:hover:bg-gray-700'} disabled:cursor-not-allowed disabled:opacity-50`}
    >{text}</button>;

    return <div className="min-w-0 space-y-1.5">
        <label htmlFor={editorId} className="block min-w-0 break-words text-xs font-semibold leading-4 text-gray-700 dark:text-gray-300">{label}</label>
        <div className="overflow-visible rounded-xl border border-gray-300 bg-white dark:border-gray-700 dark:bg-gray-950">
            <div className="flex flex-wrap items-center gap-1 border-b border-gray-200 bg-gray-50 p-1 dark:border-gray-700 dark:bg-gray-900">
                {toolbarButton('Regular', 'Remove formatting', regular)}
                {toolbarButton('B', 'Bold', () => command('bold'), selectionState.bold)}
                {toolbarButton('I', 'Italic', () => command('italic'), selectionState.italic)}
                {toolbarButton('U', 'Underline', () => command('underline'), selectionState.underline)}
                <div className="relative">
                    <button type="button" title="Font Color" aria-label="Font Color" aria-expanded={colorMenuOpen} disabled={disabled} onMouseDown={event => event.preventDefault()} onClick={() => setColorMenuOpen(open => !open)} className="flex items-center gap-1 rounded px-2 py-1 text-xs font-bold text-gray-700 hover:bg-gray-200 disabled:cursor-not-allowed disabled:opacity-50 dark:text-gray-200 dark:hover:bg-gray-700">
                        <span>Font Color</span><span className="h-3 w-3 rounded-full border border-gray-400 bg-black" aria-hidden="true" />
                    </button>
                    {colorMenuOpen && <div className="absolute left-0 top-full z-20 mt-1 flex min-w-max gap-1 rounded-lg border border-gray-200 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                        {COLORS.map(color => <button key={color.value} type="button" title={color.label} aria-label={color.label} onMouseDown={event => event.preventDefault()} onClick={() => { command('foreColor', color.value); setColorMenuOpen(false); }} className="flex items-center gap-1 rounded px-2 py-1 text-xs hover:bg-gray-100 dark:hover:bg-gray-800"><span className="h-3 w-3 rounded-full border border-gray-400" style={{ backgroundColor: color.value }} />{color.label}</button>)}
                    </div>}
                </div>
            </div>
            <div
                ref={editorRef}
                id={editorId}
                contentEditable={!disabled}
                suppressContentEditableWarning
                role="textbox"
                aria-multiline="true"
                aria-invalid={error ? 'true' : undefined}
                aria-describedby={error ? `${editorId}-error` : undefined}
                data-placeholder={placeholder}
                onInput={sync}
                onKeyUp={refreshSelectionState}
                onMouseUp={refreshSelectionState}
                onBlur={() => { sync(); setColorMenuOpen(false); }}
                className="min-h-28 w-full whitespace-normal p-3 text-sm leading-6 text-gray-800 outline-none empty:before:pointer-events-none empty:before:text-gray-400 empty:before:content-[attr(data-placeholder)] dark:text-gray-100"
                style={{ minHeight: `${Math.max(3, rows) * 1.5}rem` }}
            />
        </div>
        {error && <p id={`${editorId}-error`} className="text-xs text-red-600 dark:text-red-400" role="alert">{error}</p>}
    </div>;
}
