// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import MarkdownIt from 'markdown-it'
import DOMPurify from 'dompurify'

const md = new MarkdownIt({
	html: false,
	linkify: true,
	breaks: true,
})

// `@username` mentions → a display chip. This render is COSMETIC: the server
// independently re-parses the raw body for who actually gets notified/subscribed
// (see lib/Service/MentionService.php), so tampering with the rendered HTML
// cannot cause a side effect. The accepted charset mirrors the server pattern.
const MENTION_RE = /^@([a-zA-Z0-9_.-]+)/

md.inline.ruler.push('kanso_mention', (state, silent) => {
	const start = state.pos
	if (state.src.charCodeAt(start) !== 0x40 /* @ */) return false
	// Only at a boundary: a preceding word char (or @) means it's an email/handle,
	// not a mention (matches the server's negative-lookbehind).
	if (start > 0 && /[\w@]/.test(state.src[start - 1])) return false

	const match = MENTION_RE.exec(state.src.slice(start))
	if (!match) return false

	if (!silent) {
		const token = state.push('kanso_mention', '', 0)
		token.content = match[1]
		token.markup = match[0]
	}
	state.pos += match[0].length
	return true
})

md.renderer.rules.kanso_mention = (tokens, idx) =>
	`<span class="kanso-mention">@${md.utils.escapeHtml(tokens[idx].content)}</span>`

const ALLOWED_TAGS = [
	'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
	'p', 'strong', 'em',
	'ul', 'ol', 'li',
	'a',
	'code', 'pre',
	'blockquote',
	'hr', 'br',
	'del',
	'span', // only used for the mention chip (class="kanso-mention")
]

const ALLOWED_ATTR = ['href', 'title', 'rel', 'target', 'class']

const FORBID_TAGS = ['style', 'script']

const FORBID_ATTR = ['style', 'onerror', 'onclick', 'onload', 'onmouseover']

// Registered once at module load on the shared DOMPurify singleton. This is
// currently the only DOMPurify consumer; if a second one is added, scope this
// to a dedicated DOMPurify() instance so it doesn't inherit the link rewriting.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
	if (node.tagName === 'A') {
		const href = node.getAttribute('href') || ''
		// Strip javascript: and data: hrefs
		if (/^(javascript|data):/i.test(href.trim())) {
			node.removeAttribute('href')
		}
		node.setAttribute('rel', 'noopener noreferrer')
		node.setAttribute('target', '_blank')
	}
})

/**
 * Render markdown source to a sanitized HTML string safe for v-html.
 *
 * Security properties:
 *  - markdown-it runs with html:false (raw HTML blocks are escaped, not passed through)
 *  - DOMPurify allowlist strips every tag/attribute not in the explicit permit lists
 *  - javascript: and data: hrefs are removed by the afterSanitizeAttributes hook
 *  - All links get rel="noopener noreferrer" and target="_blank"
 *
 * @param {string} src  Raw markdown string (user-supplied, untrusted)
 * @returns {string}    Safe HTML string
 */
export function renderMarkdown(src) {
	if (!src) return ''
	const raw = md.render(src)
	return DOMPurify.sanitize(raw, {
		ALLOWED_TAGS,
		ALLOWED_ATTR,
		FORBID_TAGS,
		FORBID_ATTR,
	})
}
