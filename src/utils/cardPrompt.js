// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * cardPrompt - build a single markdown/plain-text block from a card and its
 * comments, ready to paste into an LLM.
 *
 * Pure and side-effect free so it can be unit-tested and reused. It takes the
 * card detail (title + description, already loaded in the modal) and the flat
 * comment array (already loaded via useComments) and returns one string.
 *
 * Comments are emitted in chronological order (oldest first). Replies are kept
 * with their parent thread and indented one level so the structure survives the
 * flattening. The comment BODY is copied as its raw markdown source, never the
 * rendered HTML.
 */

/**
 * Format a comment timestamp (unix seconds) as a stable, self-contained date
 * string. A prompt should not carry relative times like "5 min ago", so we emit
 * an absolute local datetime. Falls back to an empty string when there is no
 * usable timestamp.
 *
 * @param {number|string} unixTs seconds since epoch
 * @returns {string}
 */
export function formatPromptDate(unixTs) {
	const seconds = Number(unixTs)
	if (!Number.isFinite(seconds) || seconds <= 0) return ''
	const d = new Date(seconds * 1000)
	if (Number.isNaN(d.getTime())) return ''
	return d.toLocaleString(undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	})
}

/**
 * Flatten the one-level comment thread into a chronological, indentation-aware
 * list. Top-level comments keep their server order (oldest first); each reply
 * follows its parent and is marked as a reply.
 *
 * This mirrors the ordering of `buildCommentTree` in useComments.js but is
 * inlined here so the helper stays free of the Vue/TanStack import chain and
 * remains importable in a plain-Node unit test.
 *
 * @param {Array} flatComments
 * @returns {Array<{comment: object, isReply: boolean}>}
 */
function flattenThread(flatComments) {
	if (!Array.isArray(flatComments)) return []

	const isTopLevel = (c) => c.parentCommentId === null || c.parentCommentId === undefined
	// Sort chronologically (oldest first) so ordering is explicit and does not
	// rely on the server returning the array pre-sorted.
	const byCreatedAt = (a, b) => (Number(a.createdAt) || 0) - (Number(b.createdAt) || 0)

	const repliesByParent = new Map()
	for (const c of flatComments) {
		if (!isTopLevel(c)) {
			const pid = Number(c.parentCommentId)
			if (!repliesByParent.has(pid)) repliesByParent.set(pid, [])
			repliesByParent.get(pid).push(c)
		}
	}

	const out = []
	const emittedReplyIds = new Set()
	for (const comment of flatComments.filter(isTopLevel).sort(byCreatedAt)) {
		out.push({ comment, isReply: false })
		for (const reply of (repliesByParent.get(Number(comment.id)) ?? []).sort(byCreatedAt)) {
			out.push({ comment: reply, isReply: true })
			emittedReplyIds.add(reply.id)
		}
	}

	// Never drop a reply whose parent is missing from this page: append any
	// orphans at the end so the exported prompt stays complete.
	const orphans = flatComments
		.filter((c) => !isTopLevel(c) && !emittedReplyIds.has(c.id))
		.sort(byCreatedAt)
	for (const orphan of orphans) {
		out.push({ comment: orphan, isReply: true })
	}

	return out
}

/**
 * Render a single comment block. Reply bodies are indented with a blockquote
 * marker so the threading is visible in the pasted text.
 *
 * @param {object} comment
 * @param {boolean} isReply
 * @returns {string}
 */
function renderComment(comment, isReply) {
	const author = comment.authorDisplayName || comment.author || 'Unknown'
	const date = formatPromptDate(comment.createdAt)
	const heading = date ? `**${author}** (${date}):` : `**${author}**:`
	const body = String(comment.body ?? '').trim()

	if (isReply) {
		// Prefix each line with "> " so replies read as a nested quote.
		const quoted = [heading, body]
			.filter((s) => s.length > 0)
			.join('\n')
			.split('\n')
			.map((line) => `> ${line}`.trimEnd())
			.join('\n')
		return quoted
	}

	return [heading, body].filter((s) => s.length > 0).join('\n')
}

/**
 * Build the full prompt string for a card.
 *
 * @param {object} card card detail; uses `title` and `description`
 * @param {Array} comments flat comment array (as returned by useComments)
 * @returns {string}
 */
export function buildCardPrompt(card, comments) {
	const title = String(card?.title ?? '').trim()
	const description = String(card?.description ?? '').trim()

	const parts = []
	parts.push(`# ${title || 'Untitled card'}`)

	if (description) {
		parts.push(description)
	}

	const flat = Array.isArray(comments) ? comments : []
	if (flat.length > 0) {
		parts.push('## Comments')
		const rendered = flattenThread(flat).map(({ comment, isReply }) =>
			renderComment(comment, isReply),
		)
		parts.push(rendered.join('\n\n'))
	}

	// Blank line between blocks; single trailing newline.
	return parts.join('\n\n') + '\n'
}
