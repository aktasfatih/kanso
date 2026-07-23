// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import MarkdownIt from 'markdown-it'
import DOMPurify from 'dompurify'

const md = new MarkdownIt({
	html: false,
	linkify: true,
	breaks: true,
})

const ALLOWED_TAGS = [
	'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
	'p', 'strong', 'em',
	'ul', 'ol', 'li',
	'a',
	'code', 'pre',
	'blockquote',
	'hr', 'br',
	'del',
]

const ALLOWED_ATTR = ['href', 'title', 'rel', 'target']

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
