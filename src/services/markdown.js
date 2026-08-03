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

// `PREFIX-123` card cross-references → a link showing the target card's TITLE
// (like GitHub #123 / Jira PROJ-123). This mirrors the mention rule: the token
// is detected here but RESOLVED cosmetically - the referenced card's id + title
// are looked up from a caller-supplied `refs` map (built from the board cache;
// see the third arg to md.render below). Board-scoped (per-board prefixes), so a
// reference is only resolvable within the board whose text is being rendered.
// An unresolved reference (unknown card, no map, cross-board) renders as the
// plain raw text, never a broken link. Clicking a resolved ref is handled by a
// delegated click listener on the render container (it reads data-kanso-card-id),
// so the anchor itself carries no javascript: href.
//
// PREFIX = an uppercase letter then uppercase letters/digits (mirrors the server
// BoardPrefix charset), then '-', then digits. The `(?<!\w)`/`(?!\w)` boundaries
// keep it from firing mid-word (e.g. the tail of an identifier like "FOOKAN-1"
// or a version like "KAN-1A"). Uppercase letters are ordinary text to
// markdown-it's inline tokenizer (unlike `@`, which the mention rule can hook as
// an inline rule), so a card reference is matched by a CORE post-processor that
// splits already-tokenized `text` tokens - the established markdown-it pattern
// for word-shaped tokens. It runs on plain text only, so it never rewrites
// inside code spans, links, or fenced blocks (those are separate token types).
const CARD_REF_RE = /(?<!\w)([A-Z][A-Z0-9]*-\d+)(?!\w)/g

md.core.ruler.push('kanso_cardref', (state) => {
	for (const blockToken of state.tokens) {
		if (blockToken.type !== 'inline' || !blockToken.children) continue
		const children = blockToken.children
		for (let i = children.length - 1; i >= 0; i--) {
			const token = children[i]
			if (token.type !== 'text') continue
			const text = token.content
			CARD_REF_RE.lastIndex = 0
			if (!CARD_REF_RE.test(text)) continue

			// Split the text token into alternating text / kanso_cardref tokens.
			const nodes = []
			let last = 0
			CARD_REF_RE.lastIndex = 0
			let m
			while ((m = CARD_REF_RE.exec(text)) !== null) {
				if (m.index > last) {
					const t = new state.Token('text', '', 0)
					t.content = text.slice(last, m.index)
					nodes.push(t)
				}
				const ref = new state.Token('kanso_cardref', '', 0)
				ref.content = m[1]
				ref.markup = m[1]
				nodes.push(ref)
				last = m.index + m[0].length
			}
			if (last < text.length) {
				const t = new state.Token('text', '', 0)
				t.content = text.slice(last)
				nodes.push(t)
			}
			children.splice(i, 1, ...nodes)
		}
	}
})

md.renderer.rules.kanso_cardref = (tokens, idx, options, env) => {
	const ref = tokens[idx].content
	const refs = env?.refs
	const hit = refs instanceof Map ? refs.get(ref) : (refs ? refs[ref] : undefined)
	if (!hit) {
		// Unresolved: render the raw reference text, no link.
		return md.utils.escapeHtml(ref)
	}
	const cardId = md.utils.escapeHtml(String(hit.cardId))
	const title = md.utils.escapeHtml(hit.title || ref)
	// Internal, board-scoped anchor. No href (navigation is handled by the
	// container's delegated click listener via data-kanso-card-id); the title
	// attribute surfaces the raw reference on hover.
	return `<a class="kanso-cardref" data-kanso-card-id="${cardId}" title="${md.utils.escapeHtml(ref)}">${title}</a>`
}

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
	'img', // ONLY same-origin inline card-attachment images (see IMG hook below)
]

// A pasted image is embedded as `![alt](<inline-endpoint-url>)`. We permit <img>
// but LOCK its `src` to the app's own inline-attachment endpoint — a SAME-ORIGIN,
// path-only URL of the exact shape produced by cardAttachmentInlineUrl():
//   [/<anything>]/apps/kanso/api/cards/<digits>/attachments/<digits>/inline
// This deliberately allows NO external host (SSRF / tracking-pixel / exfil
// surface), NO data: URI, NO svg, NO scheme at all. The `.../inline` server
// endpoint itself only ever serves raster png/jpeg/gif/webp bytes; anything else
// 404s there. The regex is anchored end-to-end and the whole src must be a
// server-relative path (leading single "/", never "//" which is a
// protocol-relative external URL, never a scheme).
const INLINE_ATTACHMENT_SRC_RE =
	/^\/(?:[^/\\][^\\]*\/)*apps\/kanso\/api\/cards\/\d+\/attachments\/\d+\/inline$/

/**
 * True iff `src` is a safe same-origin inline card-attachment path. Rejects
 * absolute/external URLs, protocol-relative `//host`, data:/javascript: URIs,
 * backslashes, query strings, and fragments — only the exact app path passes.
 *
 * @param {string} src the raw img src attribute value
 * @returns {boolean}
 */
function isInlineAttachmentSrc(src) {
	if (typeof src !== 'string') return false
	const s = src.trim()
	// Must be a server-relative path, not "//host" (protocol-relative) and not a
	// scheme (http:, data:, javascript:). A single leading slash is required.
	if (!s.startsWith('/') || s.startsWith('//')) return false
	return INLINE_ATTACHMENT_SRC_RE.test(s)
}

// `class` is allowed through here so it can survive to the afterSanitizeAttributes
// hook, which then strips it from everything except the mention chip and card-ref
// anchor (below). `data-kanso-card-id` carries the numeric target of an internal
// card cross-reference; the hook drops it from anything that is not the ref anchor.
const ALLOWED_ATTR = ['href', 'title', 'rel', 'target', 'class', 'data-kanso-card-id', 'src', 'alt']

const FORBID_TAGS = ['style', 'script']

const FORBID_ATTR = ['style', 'onerror', 'onclick', 'onload', 'onmouseover']

// Registered once at module load on the shared DOMPurify singleton. This is
// currently the only DOMPurify consumer; if a second one is added, scope this
// to a dedicated DOMPurify() instance so it doesn't inherit the link rewriting.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
	// An internal card cross-reference anchor: <a class="kanso-cardref"
	// data-kanso-card-id="N">. It carries NO href (navigation is via the
	// container's delegated click listener) and must not be rewritten into an
	// external new-tab link. Any href/rel/target the sanitizer sees on it is
	// stripped so it can only ever be an in-app, board-scoped ref.
	const isCardRef = node.tagName === 'A'
		&& node.getAttribute?.('class') === 'kanso-cardref'
		&& node.hasAttribute?.('data-kanso-card-id')

	// Defence-in-depth: `class` is only ever legitimate on the mention chip and
	// the card-ref anchor. Strip it from every other element so the app-wide
	// allowlist entry can't be (mis)used to carry class-based styling/selectors
	// on arbitrary tags.
	if (node.hasAttribute?.('class')
		&& !(node.tagName === 'SPAN' && node.getAttribute('class') === 'kanso-mention')
		&& !isCardRef) {
		node.removeAttribute('class')
	}

	// `data-kanso-card-id` is only legitimate on the card-ref anchor; drop it
	// anywhere else so the allowlist entry can't smuggle the attribute onto
	// arbitrary elements.
	if (node.hasAttribute?.('data-kanso-card-id') && !isCardRef) {
		node.removeAttribute('data-kanso-card-id')
	}

	// `src`/`alt` are only legitimate on an <img>. Strip them from anything else
	// so the app-wide allowlist entries can't be carried on other elements.
	if (node.tagName !== 'IMG') {
		if (node.hasAttribute?.('src')) node.removeAttribute('src')
		if (node.hasAttribute?.('alt')) node.removeAttribute('alt')
	}

	if (node.tagName === 'IMG') {
		// An embedded pasted image. Its src MUST be the app's own inline
		// card-attachment path (same-origin, path-only, exact shape). Anything
		// else — external host, protocol-relative //host, data:/javascript: URI,
		// svg, or a malformed value — means the whole <img> is dropped, never
		// rendered. No other attribute survives (no srcset/style/on*/width/…), so
		// there is no external-fetch, tracking-pixel, or handler surface.
		const src = node.getAttribute('src') || ''
		if (!isInlineAttachmentSrc(src)) {
			node.remove?.()
			return
		}
		const alt = node.getAttribute('alt') || ''
		// Rebuild the attribute set from scratch: only the validated src + a plain
		// text alt. removeAttribute over the live map while iterating is avoided by
		// snapshotting the names first.
		const names = node.getAttributeNames?.() || []
		for (const name of names) {
			if (name !== 'src') node.removeAttribute(name)
		}
		if (alt) node.setAttribute('alt', alt)
		// Loading is lazy + referrer suppressed as defence in depth (the src is
		// already same-origin, so no cross-origin referrer leaks, but keep it tight).
		node.setAttribute('loading', 'lazy')
		node.setAttribute('referrerpolicy', 'no-referrer')
		return
	}

	if (node.tagName === 'A') {
		if (isCardRef) {
			// Internal ref: no href/target/rel - the click handler owns navigation.
			node.removeAttribute('href')
			node.removeAttribute('target')
			node.removeAttribute('rel')
			return
		}
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
 * @param {object} [options]  Render options
 * @param {Map<string, {cardId: number, title: string}>|Record<string, {cardId: number, title: string}>} [options.refs]
 *   Board-scoped card cross-reference map keyed by uppercase `PREFIX-<seq>`
 *   (e.g. "KAN-123"). A hit renders the reference as a title link; a miss (or an
 *   omitted map) renders the raw reference text. The caller builds this from the
 *   already-cached board cards + prefix so rendering needs no extra request.
 * @returns {string}    Safe HTML string
 */
export function renderMarkdown(src, options = {}) {
	if (!src) return ''
	// `env` is markdown-it's per-render bag; the cardref renderer reads env.refs.
	const raw = md.render(src, { refs: options.refs })
	return DOMPurify.sanitize(raw, {
		ALLOWED_TAGS,
		ALLOWED_ATTR,
		FORBID_TAGS,
		FORBID_ATTR,
	})
}

/**
 * Build the `refs` map {@see renderMarkdown} expects from a board's card
 * summaries and prefix (both already in the TanStack Query cache). Keys are the
 * uppercase `PREFIX-<boardSeq>` human id; values carry the numeric id + title.
 * Cards without an assigned sequence number (pre-migration rows) are skipped.
 *
 * @param {Array<{id: number, boardSeq: ?number, title: ?string}>} cards board card summaries
 * @param {string} prefix the board prefix (e.g. "KAN")
 * @returns {Map<string, {cardId: number, title: string}>}
 */
export function buildCardRefMap(cards, prefix) {
	const map = new Map()
	if (!Array.isArray(cards) || !prefix) return map
	const p = String(prefix).toUpperCase()
	for (const c of cards) {
		if (c && c.boardSeq != null && Number(c.boardSeq) > 0) {
			map.set(`${p}-${c.boardSeq}`, { cardId: c.id, title: c.title || `${p}-${c.boardSeq}` })
		}
	}
	return map
}
