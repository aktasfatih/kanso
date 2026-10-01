// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The public-share inline-image re-pointing (#152) and its markdown structure
 * awareness (#10608).
 *
 * This used to be a PHP-side substitution over the whole description string
 * (PublicShareService::rewriteInlineImages), which could not tell an image from a
 * code fence quoting one and so printed the board's 64-char share token as
 * visible text. It is now a markdown-it CORE rule, and these tests are where that
 * lives or dies:
 *
 *  1. STRUCTURE — only a src markdown actually resolved as an image or a link is
 *     re-pointed. A fence, a code span, alt text and prose come back verbatim.
 *  2. ANCHORING — the five hostile shapes from PublicShareServiceTest's
 *     hostileImageSrcProvider must never receive the token. On the server that
 *     needed a negative lookbehind over free text; here the value is one complete
 *     attribute, so the pattern is anchored `^…$` and the whole attack class is
 *     structurally impossible. These cases keep it honest.
 *
 * The rule is imported from services/inlineAttachmentSrc.js and pushed onto a
 * BARE markdown-it here on purpose: services/markdown.js installs a DOMPurify
 * hook at import time and so only loads in a browser. The end-to-end pairing of
 * this rule with that sanitiser is covered by tests/e2e/public-share.spec.js.
 */

import test from 'node:test'
import assert from 'node:assert/strict'
import MarkdownIt from 'markdown-it'
import {
	isInlineAttachmentSrc,
	publicInlineAttachmentSrc,
	publicInlineSrcRule,
} from '../../src/services/inlineAttachmentSrc.js'


// 64 chars of alphanumerics, exactly as ISecureRandom mints them.
const TOKEN = 'a'.repeat(32) + 'B7'.repeat(16)
const AUTH_SRC = '/apps/kanso/api/cards/10/attachments/3/inline'
const PUBLIC_SRC = `/apps/kanso/api/public/${TOKEN}/cards/10/attachments/3/inline`

/** The same markdown-it options services/markdown.js uses, plus the rule. */
function renderer() {
	const md = new MarkdownIt({ html: false, linkify: true, breaks: true })
	md.core.ruler.push('kanso_public_inline_src', publicInlineSrcRule)
	return md
}

function renderPublic(src) {
	return renderer().render(src, { publicToken: TOKEN })
}

test('the token is 64 alphanumerics, like the server mints', () => {
	assert.equal(TOKEN.length, 64)
	assert.match(TOKEN, /^[A-Za-z0-9]{64}$/)
})

// ── the transform itself ───────────────────────────────────────────────────────

test('an authenticated inline src becomes the share-gated twin', () => {
	assert.equal(publicInlineAttachmentSrc(AUTH_SRC, TOKEN), PUBLIC_SRC)
	// …and the result is a src the sanitiser will accept, which is what keeps
	// DOMPurify a second gate rather than a rubber stamp.
	assert.ok(isInlineAttachmentSrc(PUBLIC_SRC))
})

test('a webroot/index.php prefix survives the rewrite', () => {
	assert.equal(
		publicInlineAttachmentSrc('/nc/index.php/apps/kanso/api/cards/1/attachments/2/inline', TOKEN),
		`/nc/index.php/apps/kanso/api/public/${TOKEN}/cards/1/attachments/2/inline`,
	)
})

test('a malformed share token is refused rather than pasted into a path', () => {
	for (const bad of ['', 'short', '../../etc', `${TOKEN}/x`, `${'a'.repeat(63)}`, null, undefined]) {
		assert.equal(publicInlineAttachmentSrc(AUTH_SRC, bad), null, `token ${JSON.stringify(bad)}`)
	}
})

test('a src that is not exactly an inline-attachment path is left alone', () => {
	for (const src of [
		'/apps/kanso/api/cards/10/attachments/3/inline?x=1',
		'/apps/kanso/api/cards/10/attachments/3/inline#f',
		'/apps/kanso/api/cards/10/attachments/x/inline',
		'/apps/other/api/cards/10/attachments/3/inline',
		'apps/kanso/api/cards/10/attachments/3/inline',
		'\\apps\\kanso\\api\\cards\\10\\attachments\\3\\inline',
		PUBLIC_SRC, // already public: idempotent, never double-wrapped
	]) {
		assert.equal(publicInlineAttachmentSrc(src, TOKEN), null, src)
	}
})

/**
 * The PREFIX is where "same-origin by construction" is either true or a wish.
 * A browser DELETES a raw LF/CR/TAB from a URL before parsing it, so a prefix
 * segment allowed to contain one would resolve `/<LF>/evil.example/apps/…`
 * against evil.example — with the token already spliced in. And a prefix allowed
 * to contain `?` would put the token in a query string, i.e. in an access log.
 * Neither is reachable through markdown-it today (it percent-escapes both), but
 * that is the encoder's property, not this pattern's, so the pattern owns it.
 */
test('a prefix that could change the origin, or open a query, is refused', () => {
	for (const src of [
		'/\n/evil.example/apps/kanso/api/cards/1/attachments/2/inline',
		'/\t/evil.example/apps/kanso/api/cards/1/attachments/2/inline',
		'/\r/evil.example/apps/kanso/api/cards/1/attachments/2/inline',
		'/ /evil.example/apps/kanso/api/cards/1/attachments/2/inline',
		'/x?q=1/apps/kanso/api/cards/1/attachments/2/inline',
		'/x#f/apps/kanso/api/cards/1/attachments/2/inline',
		'/u:p@host/apps/kanso/api/cards/1/attachments/2/inline',
	]) {
		assert.equal(publicInlineAttachmentSrc(src, TOKEN), null, JSON.stringify(src))
		// …and the sanitiser refuses the same shapes, so neither gate carries it.
		assert.equal(isInlineAttachmentSrc(src), false, JSON.stringify(src))
	}

	// The PERCENT-ESCAPED form is a different thing and is deliberately allowed:
	// `%0A` is an ordinary path segment, no parser deletes it, and the result is
	// still same-origin. (This is the form markdown-it hands us when an author does
	// manage to type a raw newline in a link destination.)
	const escaped = publicInlineAttachmentSrc('/%0A/apps/kanso/api/cards/1/attachments/2/inline', TOKEN)
	assert.ok(escaped?.includes(TOKEN))
	assert.equal(new URL(escaped, 'https://cloud.example/').origin, 'https://cloud.example')
})

test('whatever comes back is a path-absolute same-origin URL', () => {
	// The property the whole design rests on, asserted rather than asserted-in-prose:
	// resolve the output against an origin and it must still BE that origin.
	const out = publicInlineAttachmentSrc('/nc/index.php/apps/kanso/api/cards/1/attachments/2/inline', TOKEN)
	assert.equal(new URL(out, 'https://cloud.example/x/y').origin, 'https://cloud.example')
	assert.ok(!out.includes('?') && !out.includes('#'))
})

// ── structure awareness: #10608 ────────────────────────────────────────────────

test('a real inline image is re-pointed at the share route', () => {
	const html = renderPublic(`Look:\n\n![shot](${AUTH_SRC})\n\nEnd.`)
	assert.ok(html.includes(`src="${PUBLIC_SRC}"`), html)
	assert.ok(!html.includes(`src="${AUTH_SRC}"`), html)
})

test('a reference-style image is re-pointed too', () => {
	const html = renderPublic(`![shot][ref]\n\n[ref]: ${AUTH_SRC}`)
	assert.ok(html.includes(`src="${PUBLIC_SRC}"`), html)
})

test('an explicit link to the attachment is re-pointed', () => {
	const html = renderPublic(`[full size](${AUTH_SRC})`)
	assert.ok(html.includes(`href="${PUBLIC_SRC}"`), html)
})

test('a URL inside a fenced code block renders exactly as written', () => {
	const html = renderPublic(`\`\`\`\n![image.png](${AUTH_SRC})\n\`\`\``)
	assert.ok(html.includes(AUTH_SRC), html)
	assert.ok(!html.includes(TOKEN), html)
})

test('a URL inside an indented code block renders exactly as written', () => {
	const html = renderPublic(`    ![image.png](${AUTH_SRC})`)
	assert.ok(html.includes(AUTH_SRC), html)
	assert.ok(!html.includes(TOKEN), html)
})

test('a URL inside inline code renders exactly as written', () => {
	const html = renderPublic(`paste \`${AUTH_SRC}\` into the box`)
	assert.ok(html.includes(AUTH_SRC), html)
	assert.ok(!html.includes(TOKEN), html)
})

test('a URL mentioned in prose renders exactly as written', () => {
	const html = renderPublic(`the endpoint is ${AUTH_SRC} and it 401s`)
	assert.ok(html.includes(AUTH_SRC), html)
	assert.ok(!html.includes(TOKEN), html)
})

test('a URL used as an image alt text is not rewritten', () => {
	const html = renderPublic(`![${AUTH_SRC}](/apps/kanso/api/cards/9/attachments/9/inline)`)
	assert.ok(html.includes(`alt="${AUTH_SRC}"`), html)
	// The real src of that same image still IS rewritten.
	assert.ok(html.includes(`/public/${TOKEN}/cards/9/attachments/9/inline`), html)
})

test('without a share token nothing is rewritten at all', () => {
	// Every AUTHENTICATED surface in the app renders through the same singleton,
	// so a missing token must leave the authenticated path in place.
	const md = renderer()
	for (const env of [{}, { publicToken: '' }, { publicToken: undefined }]) {
		const html = md.render(`![shot](${AUTH_SRC})`, env)
		assert.ok(html.includes(`src="${AUTH_SRC}"`), JSON.stringify(env))
		assert.ok(!html.includes('/public/'), JSON.stringify(env))
	}
})

// ── anchoring: the five hostile shapes, unchanged from the PHP provider ────────

/**
 * PublicShareServiceTest::hostileImageSrcProvider, verbatim. Every one of these
 * is an EDIT member (who cannot read the token - getConfig is MANAGE-only) trying
 * to get the share token spliced into a URL that points at their own server.
 */
const HOSTILE = {
	'absolute https': '[click](https://evil.example/apps/kanso/api/cards/1/attachments/2/inline)',
	'protocol-relative': '[click](//evil.example/apps/kanso/api/cards/1/attachments/2/inline)',
	'userinfo authority': '[click](http://u:p@evil.example/apps/kanso/api/cards/1/attachments/2/inline)',
	'bare autolinked url': 'see https://evil.example/apps/kanso/api/cards/1/attachments/2/inline now',
	'schemeless host': 'see evil.example/apps/kanso/api/cards/1/attachments/2/inline now',
	// The provider's fifth case as PROSE cannot reach this rule at all: `.example`
	// is not a TLD linkify knows, so markdown-it emits one plain text token. Kept
	// for parity with the PHP suite, plus the LINK form below, which does reach the
	// rule and so actually exercises the anchoring.
	'schemeless host, as a link': '[click](evil.example/apps/kanso/api/cards/1/attachments/2/inline)',
}

for (const [name, source] of Object.entries(HOSTILE)) {
	test(`the token is never spliced into an absolute URL: ${name}`, () => {
		const html = renderPublic(source)
		assert.ok(!html.includes(TOKEN), html)
		assert.ok(!html.includes('/public/'), html)
	})
}

test('the same five shapes are refused by the transform directly', () => {
	for (const value of [
		'https://evil.example/apps/kanso/api/cards/1/attachments/2/inline',
		'//evil.example/apps/kanso/api/cards/1/attachments/2/inline',
		'http://u:p@evil.example/apps/kanso/api/cards/1/attachments/2/inline',
		'evil.example/apps/kanso/api/cards/1/attachments/2/inline',
	]) {
		assert.equal(publicInlineAttachmentSrc(value, TOKEN), null, value)
	}
})

/**
 * The non-vacuity proof for the anchoring, as a test rather than a promise. This
 * is the mutant: the pattern with `^` and `$` removed, which is what the server's
 * free-text version effectively was. Every hostile shape must start leaking under
 * it, or the four assertions above prove nothing.
 */
test('unanchoring the pattern would leak the token (mutation proof)', () => {
	const unanchored = /(\/(?:[^/\\][^\\]*\/)*apps\/kanso\/api)\/cards\/(\d+)\/attachments\/(\d+)\/inline/
	const leaky = (src) => {
		const m = unanchored.exec(src)
		return m === null ? null : src.replace(unanchored, `$1/public/${TOKEN}/cards/$2/attachments/$3/inline`)
	}
	let leaked = 0
	for (const value of [
		'https://evil.example/apps/kanso/api/cards/1/attachments/2/inline',
		'//evil.example/apps/kanso/api/cards/1/attachments/2/inline',
		'http://u:p@evil.example/apps/kanso/api/cards/1/attachments/2/inline',
		'evil.example/apps/kanso/api/cards/1/attachments/2/inline',
	]) {
		const out = leaky(value)
		// The mutant both matches AND hands the token to evil.example.
		assert.notEqual(out, null, value)
		assert.ok(out.includes(TOKEN), value)
		assert.ok(out.includes('evil.example'), value)
		leaked++
		// …while the real, anchored transform refuses the same input.
		assert.equal(publicInlineAttachmentSrc(value, TOKEN), null, value)
	}
	assert.equal(leaked, 4)
})
