// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { nextTick } from 'vue'

/**
 * Markdown formatting toolbar over a plain <textarea>. Each action mutates the
 * bound markdown string around the current selection and restores the caret —
 * markdown stays the source of truth (no rich-HTML blob), so the read view /
 * sanitisation (renderMarkdown + DOMPurify) are untouched. Mirrors the
 * slice/setText/setSelectionRange primitive used by useMentionAutocomplete.
 *
 * @param {object} opts
 * @param {() => string}        opts.getText     - getter for the current value
 * @param {(v: string) => void} opts.setText     - setter to replace the value
 * @param {import('vue').Ref}   opts.textareaRef - ref to the <textarea> element
 */
export function useMarkdownToolbar({ getText, setText, textareaRef }) {
	function selection() {
		const el = textareaRef.value
		const text = getText()
		const start = el ? el.selectionStart : text.length
		const end = el ? el.selectionEnd : text.length
		return { text, start, end }
	}

	async function apply(newText, selStart, selEnd) {
		setText(newText)
		await nextTick()
		const el = textareaRef.value
		if (el) {
			el.focus()
			el.setSelectionRange(selStart, selEnd)
		}
	}

	/** Wrap the selection with `before`/`after`; use `placeholder` when empty. */
	function wrap(before, after, placeholder) {
		const { text, start, end } = selection()
		const selected = text.slice(start, end) || placeholder
		const newText = text.slice(0, start) + before + selected + after + text.slice(end)
		const innerStart = start + before.length
		apply(newText, innerStart, innerStart + selected.length)
	}

	/** Prefix every line touched by the selection (lists, quote, headings). */
	function prefixLines(prefix) {
		const { text, start, end } = selection()
		// Extend the block to the FULL first and last touched lines so a partial
		// selection still prefixes whole lines (not mid-word on the last line).
		const lineStart = text.lastIndexOf('\n', start - 1) + 1
		const nextNl = text.indexOf('\n', end)
		const blockEnd = nextNl === -1 ? text.length : nextNl
		const block = text.slice(lineStart, blockEnd)
		const prefixed = block
			.split('\n')
			.map((line) => prefix + line)
			.join('\n')
		const newText = text.slice(0, lineStart) + prefixed + text.slice(blockEnd)
		apply(newText, lineStart, lineStart + prefixed.length)
	}

	function link() {
		const { text, start, end } = selection()
		const label = text.slice(start, end) || 'text'
		const snippet = `[${label}](url)`
		const newText = text.slice(0, start) + snippet + text.slice(end)
		// Select the placeholder "url" so the user can type over it.
		const urlStart = start + 1 + label.length + 2 // after "](".
		apply(newText, urlStart, urlStart + 3)
	}

	return {
		bold: () => wrap('**', '**', 'bold'),
		italic: () => wrap('_', '_', 'italic'),
		inlineCode: () => wrap('`', '`', 'code'),
		heading: () => prefixLines('## '),
		bulletList: () => prefixLines('- '),
		checklist: () => prefixLines('- [ ] '),
		quote: () => prefixLines('> '),
		link,
	}
}
