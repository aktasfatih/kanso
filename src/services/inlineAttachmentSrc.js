// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The two shapes an inline card-attachment `<img src>` is allowed to have, and
 * the only two transforms anything is allowed to do with them.
 *
 * This lives apart from services/markdown.js for one reason: markdown.js
 * registers a DOMPurify hook at module load, so it cannot be imported outside a
 * browser, and these patterns are the security-load-bearing half of that file.
 * Here they are plain string predicates with no DOM, so tests/unit can exercise
 * every hostile shape directly under `node --test` — including by mutation.
 */

// A pasted image is embedded as `![alt](<inline-endpoint-url>)`. We permit <img>
// but LOCK its `src` to the app's own inline-attachment endpoint — a SAME-ORIGIN,
// path-only URL of the exact shape produced by cardAttachmentInlineUrl():
//   [/<anything>]/apps/kanso/api/cards/<digits>/attachments/<digits>/inline
// …or its token-gated public-share twin (see the second pattern below).
// This deliberately allows NO external host (SSRF / tracking-pixel / exfil
// surface), NO data: URI, NO svg, NO scheme at all. The `.../inline` server
// endpoint itself only ever serves raster png/jpeg/gif/webp bytes; anything else
// 404s there. The regex is anchored end-to-end and the whole src must be a
// server-relative path (leading single "/", never "//" which is a
// protocol-relative external URL, never a scheme).
//
// The three capture groups are what makes ONE pattern serve both jobs — the
// allow-list test and the public-share rewrite. A second pattern for the rewrite
// is exactly how the two would drift apart.
//
// THE PREFIX CHARSET IS LOAD-BEARING, twice over, and is not just "not a slash":
//  - Whitespace and C0 controls are excluded because a browser STRIPS raw LF, CR
//    and TAB out of a URL before parsing it. `/\n/evil.example/apps/kanso/api/…`
//    would otherwise satisfy a "starts with one slash, contains no backslash"
//    reading of this pattern and then resolve against evil.example — with the
//    share token spliced in. It is only same-origin-by-shape if the shape cannot
//    contain a character the parser deletes.
//  - `?` and `#` are excluded so a prefix cannot open a query or a fragment. The
//    token is appended AFTER the prefix, so `/x?q=1/apps/kanso/api/…` would hand
//    a 380-bit secret to whatever reads that query string (an access log, say).
// `()<>"'@:` are excluded to keep this class identical to (in fact a subset of)
// the server's INLINE_SRC_RE prefix in lib/Service/PublicShareService.php, which
// is the authorisation gate for the same paths. Subset, never superset, is the
// direction that matters: the gate must never refuse a src this rewrites (that
// would 404 an image the page draws — #152 again).
const PATH_SEGMENT = '[^/\\\\\\s()<>"\'@:?#]+'
const INLINE_ATTACHMENT_SRC_RE = new RegExp(
	`^(/(?:${PATH_SEGMENT}/)*apps/kanso/api)/cards/(\\d+)/attachments/(\\d+)/inline$`,
)

// The PUBLIC-SHARE twin of the path above (#152). A public-share visitor has no
// session, so the authenticated endpoint 401s and the picture renders as a
// broken box; the src is re-pointed at the token-gated route instead — at RENDER
// time, by publicInlineAttachmentSrc() below, on the one src markdown actually
// parsed as an image (#10608). This is the shape that produces:
//   [/<anything>]/apps/kanso/api/public/<token>/cards/<digits>/attachments/<digits>/inline
// Same properties as its twin and no looser: anchored end-to-end, same prefix
// charset, same-origin path only, no scheme, no host, no query, no fragment. The
// token segment is pinned to the generator's own charset —
// ISecureRandom::CHAR_ALPHANUMERIC, 64 chars (PublicShareService::TOKEN_LENGTH) —
// so this alternative cannot be used to smuggle path segments, traversal or an
// extension past the check.
const PUBLIC_INLINE_ATTACHMENT_SRC_RE = new RegExp(
	`^/(?:${PATH_SEGMENT}/)*apps/kanso/api/public/[A-Za-z0-9]{64}/cards/\\d+/attachments/\\d+/inline$`,
)

// A share token as PublicShareService mints it: exactly 64 chars of
// ISecureRandom::CHAR_ALPHANUMERIC. Pinned so a caller cannot pass a value with
// a `/`, a `..`, or a `?` in it and have it land inside a path we then build.
const PUBLIC_SHARE_TOKEN_RE = /^[A-Za-z0-9]{64}$/

/**
 * True iff `src` is a safe same-origin inline card-attachment path — either the
 * authenticated one or its public-share twin. Rejects absolute/external URLs,
 * protocol-relative `//host`, data:/javascript: URIs, backslashes, query
 * strings, and fragments — only the two exact app paths pass.
 *
 * @param {string} src the raw img src attribute value
 * @returns {boolean}
 */
export function isInlineAttachmentSrc(src) {
	if (typeof src !== 'string') return false
	const s = src.trim()
	// Must be a server-relative path, not "//host" (protocol-relative) and not a
	// scheme (http:, data:, javascript:). A single leading slash is required.
	if (!s.startsWith('/') || s.startsWith('//')) return false
	return INLINE_ATTACHMENT_SRC_RE.test(s) || PUBLIC_INLINE_ATTACHMENT_SRC_RE.test(s)
}

/**
 * The public-share twin of an AUTHENTICATED inline-attachment src, or null when
 * `src` is not exactly such a path (or `token` is not a real share token).
 *
 * This is the whole of the #152 re-pointing, and the reason it is a function
 * over one complete attribute value rather than a substitution over free text
 * (#10608 — which is what the server used to do):
 *
 *  - It is ANCHORED `^…$`. The value either IS the authenticated path or it is
 *    left alone; there is no "matches somewhere inside" case. That kills the
 *    whole class of attack the server pattern needed a negative lookbehind for:
 *    `https://evil.example/apps/kanso/api/cards/1/attachments/2/inline` simply
 *    does not match, so the board's 380-bit share token can never be spliced
 *    into an attacker-controlled absolute URL.
 *  - The value it accepts starts with exactly one `/` and every segment after it
 *    is drawn from PATH_SEGMENT above, so it is a path-absolute SAME-ORIGIN URL
 *    by construction: no `\`, no whitespace or control character a URL parser
 *    would strip, no `?`, no `#`, no authority. The token, once substituted in,
 *    therefore cannot be sent off-origin, nor land in a query string, no matter
 *    what the author typed.
 *  - The token is re-validated here rather than trusted from the caller, so a
 *    malformed one can never introduce a path segment of its own.
 *
 * The result matches PUBLIC_INLINE_ATTACHMENT_SRC_RE by construction, so for an
 * `<img src>` the sanitiser in services/markdown.js is a genuine SECOND gate: it
 * re-tests the value independently before the element survives. Note the
 * asymmetry - the sanitiser's `<a>` branch does not validate `href` at all, so a
 * rewritten LINK has this anchored pattern and nothing else standing behind it.
 * That is exactly why the pattern, and not a substitution, is what decides.
 *
 * @param {string} src the authenticated inline-attachment path as stored in the text
 * @param {string} token the 64-char public share token this page was opened with
 * @returns {?string} the token-gated path, or null if nothing should change
 */
export function publicInlineAttachmentSrc(src, token) {
	if (typeof src !== 'string' || typeof token !== 'string') return null
	if (!PUBLIC_SHARE_TOKEN_RE.test(token)) return null
	const m = INLINE_ATTACHMENT_SRC_RE.exec(src)
	if (!m) return null
	const [, prefix, cardId, attachmentId] = m
	return `${prefix}/public/${token}/cards/${cardId}/attachments/${attachmentId}/inline`
}

/**
 * markdown-it CORE rule: on a public share, re-point every inline
 * card-attachment src/href at the share's own token-gated route (#152).
 *
 * Registered by services/markdown.js on the app's shared markdown-it instance.
 * It lives here so it can be exercised against a bare `new MarkdownIt()` under
 * `node --test` — the structure-awareness below is the entire fix, so it has to
 * be provable without a browser.
 *
 * Why this is a PARSER rule and not a string substitution (#10608): the server
 * used to do the re-pointing with one regex over the whole description, which has
 * no idea where in the document it is. A URL quoted inside a fenced code block
 * got the board's 64-char share token substituted into the visible text — so a
 * code fence stopped showing what was typed, and a screenshot of the board's
 * CONTENT started carrying a working access credential where before the token
 * only ever lived in the address bar. Here, `image` and `link_open` are token
 * types, and a fence, a code span, alt text and prose are OTHER token types, so
 * "only a src markdown actually resolved as an image" is something the parser
 * knows for free and a regex over text can only ever guess at. This is the same
 * property the kanso_cardref rule in services/markdown.js relies on.
 *
 * The token arrives per render through `env`, never module state: the markdown-it
 * instance is a module-level singleton shared with every AUTHENTICATED surface in
 * the app, and those must keep rendering the authenticated path. No token in
 * `env` means this no-ops — which is also what keeps flattenMarkdown()'s
 * `md.parse(src, {})` unaffected.
 *
 * `link_open` is included as well as `image`: `[full size](…/inline)` is a real
 * thing authors write, it worked on a public page while the server did the
 * re-pointing, and PublicShareService's authorisation gate already honours it, so
 * omitting it would be a silent regression rather than a tightening.
 *
 * @param {object} state markdown-it core state
 */
export function publicInlineSrcRule(state) {
	const token = state.env?.publicToken
	if (!token) return
	for (const blockToken of state.tokens) {
		if (blockToken.type !== 'inline' || !blockToken.children) continue
		for (const child of blockToken.children) {
			const attr = child.type === 'image' ? 'src' : (child.type === 'link_open' ? 'href' : null)
			if (attr === null) continue
			// markdown-it has already run normalizeLink() on this value, so it is the
			// percent-normalised form the browser would fetch — the same string the
			// sanitiser will validate, not the raw source text.
			const rewritten = publicInlineAttachmentSrc(child.attrGet(attr), token)
			if (rewritten !== null) child.attrSet(attr, rewritten)
		}
	}
}
