// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useImagePaste (#3525) — paste a clipboard image into a markdown <textarea>
 * (the card description or the comment composer) and embed it inline.
 *
 * On a paste that carries an image, we:
 *   1. preventDefault (so the browser doesn't also drop a data: blob or filename
 *      into the textarea),
 *   2. insert an `![filename](uploading…)` placeholder at the caret with a brief
 *      "uploading…" affordance,
 *   3. upload the image through the EXISTING attachment endpoint
 *      (POST /api/cards/{cardId}/attachments — reusing the whole storage/ACL
 *      pipeline),
 *   4. rewrite the placeholder into `![filename](<inline-endpoint-url>)` pointing
 *      at the new attachment's inline URL, which the markdown sanitiser renders
 *      as a same-origin <img> (see services/markdown.js).
 *
 * On failure the placeholder is removed and an error surfaced; nothing is
 * inserted. Non-image pastes fall through to the browser's default (normal text
 * paste), so the mention/markdown editing is untouched.
 *
 * Text is edited via the same getText/setText/textareaRef primitive the markdown
 * toolbar and mention autocomplete use, so markdown stays the single source of
 * truth (no rich-HTML blob).
 */

import { nextTick } from 'vue'

const IMAGE_MIME_RE = /^image\//i

/**
 * @param {object} opts
 * @param {() => string}        opts.getText     current textarea value getter
 * @param {(v: string) => void} opts.setText     textarea value setter
 * @param {import('vue').Ref}   opts.textareaRef ref to the <textarea> element
 * @param {(file: File) => Promise<{id: number, filename: string}>} opts.upload
 *   uploads the file and resolves with the created attachment (id + filename)
 * @param {(attachmentId: number) => string} opts.inlineUrl
 *   builds the same-origin inline URL for an uploaded attachment id
 * @param {(msg: string) => void} [opts.onError] surface an upload error
 */
export function useImagePaste({ getText, setText, textareaRef, upload, inlineUrl, onError }) {
	/** Extract the first image File from a paste event, or null. */
	function imageFromEvent(event) {
		const items = event.clipboardData?.items
		if (!items) return null
		for (const item of items) {
			if (item.kind === 'file' && IMAGE_MIME_RE.test(item.type || '')) {
				const file = item.getAsFile()
				if (file) return file
			}
		}
		return null
	}

	/** A human filename for the pasted blob (clipboard images are usually unnamed). */
	function fileLabel(file) {
		if (file.name && file.name.trim() && file.name !== 'image.png') return file.name
		const ext = (file.type.split('/')[1] || 'png').replace(/[^a-z0-9]/gi, '') || 'png'
		return `pasted-image-${Date.now()}.${ext}`
	}

	/**
	 * Insert `snippet` at the current caret (replacing any selection), placing the
	 * caret after it. Returns the [start, end] range the snippet occupies so the
	 * caller can later replace exactly that span even if the caret has moved.
	 */
	async function insertAtCaret(snippet) {
		const el = textareaRef.value
		const text = getText()
		const start = el ? el.selectionStart : text.length
		const end = el ? el.selectionEnd : text.length
		const next = text.slice(0, start) + snippet + text.slice(end)
		setText(next)
		await nextTick()
		const caret = start + snippet.length
		if (el) {
			el.focus()
			el.setSelectionRange(caret, caret)
		}
		return { from: start, to: start + snippet.length }
	}

	/**
	 * Replace the exact `placeholder` substring (first occurrence at/after `from`)
	 * with `replacement`. Falls back to a global first-occurrence replace if the
	 * text shifted. If the placeholder is gone (user deleted it mid-upload), it is
	 * a no-op — we never blindly append.
	 */
	function replacePlaceholder(placeholder, replacement, from) {
		const text = getText()
		let idx = text.indexOf(placeholder, Math.max(0, from))
		if (idx === -1) idx = text.indexOf(placeholder)
		if (idx === -1) return false
		setText(text.slice(0, idx) + replacement + text.slice(idx + placeholder.length))
		return true
	}

	/**
	 * The paste event handler. Bind to a textarea's @paste. Returns true if it
	 * handled an image (and called preventDefault), false otherwise.
	 */
	async function onPaste(event) {
		const file = imageFromEvent(event)
		if (!file) return false
		// We own this paste: stop the browser from also inserting a data: blob or
		// the OS filename into the textarea.
		event.preventDefault()

		const label = fileLabel(file)
		// A unique placeholder token so concurrent pastes don't collide.
		const token = `uploading-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
		const placeholder = `![${label}](${token})`
		const { from } = await insertAtCaret(placeholder)

		try {
			const attachment = await upload(file)
			const src = inlineUrl(attachment.id)
			const finalLabel = attachment.filename || label
			replacePlaceholder(placeholder, `![${finalLabel}](${src})`, from)
		} catch (e) {
			// Remove the placeholder — nothing is inserted on failure.
			replacePlaceholder(placeholder, '', from)
			onError?.(e?.response?.data?.error || null)
		}
		return true
	}

	return { onPaste }
}
