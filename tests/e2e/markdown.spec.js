// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, API } from './helpers.js'
import { deflateSync } from 'node:zlib'

/**
 * A real PNG of exactly `w`×`h` (solid black, 8-bit greyscale).
 *
 * #147 is about an image's NATURAL size, so the fixture has to carry real
 * dimensions — the 1×1 PNG the other specs use would satisfy `max-width: 100%`
 * no matter what the CSS said. Hand-rolled rather than checked in as a binary:
 * a generator states the dimensions the assertions depend on right here, and
 * `zlib.crc32` is deliberately avoided (added in Node 20.15, and CI pins no
 * minor).
 *
 * @param {number} w width in pixels
 * @param {number} h height in pixels
 * @return {Buffer} the encoded PNG
 */
function makePng(w, h) {
	const table = new Int32Array(256)
	for (let n = 0; n < 256; n++) {
		let c = n
		for (let k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1)
		table[n] = c
	}
	const crc32 = (buf) => {
		let c = -1
		for (let i = 0; i < buf.length; i++) c = table[(c ^ buf[i]) & 0xFF] ^ (c >>> 8)
		return (c ^ -1) >>> 0
	}
	const chunk = (type, data) => {
		const len = Buffer.alloc(4)
		len.writeUInt32BE(data.length, 0)
		const body = Buffer.concat([Buffer.from(type, 'ascii'), data])
		const crc = Buffer.alloc(4)
		crc.writeUInt32BE(crc32(body), 0)
		return Buffer.concat([len, body, crc])
	}
	const ihdr = Buffer.alloc(13)
	ihdr.writeUInt32BE(w, 0)
	ihdr.writeUInt32BE(h, 4)
	ihdr[8] = 8 // bit depth
	ihdr[9] = 0 // colour type: greyscale
	// Each scanline is a filter byte (0 = none) followed by w samples; all-zero
	// bytes are a valid, fully black image.
	const idat = deflateSync(Buffer.alloc((w + 1) * h))
	return Buffer.concat([
		Buffer.from([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]),
		chunk('IHDR', ihdr),
		chunk('IDAT', idat),
		chunk('IEND', Buffer.alloc(0)),
	])
}

/** Upload a generated PNG as a card attachment; returns the attachment record. */
async function uploadPng(cardId, filename, w, h) {
	const form = new FormData()
	form.append('file', new Blob([makePng(w, h)], { type: 'image/png' }), filename)
	const r = await fetch(API + `/cards/${cardId}/attachments`, {
		method: 'POST',
		headers: { 'OCS-APIREQUEST': 'true', Authorization: api.auth },
		body: form,
	})
	if (!r.ok) throw new Error(`upload ${filename} → ${r.status}: ${await r.text()}`)
	return r.json()
}

test.describe('Markdown card descriptions - render and XSS safety', () => {
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
		listCardId: 0,
		imgCardId: 0,
		wideSrc: '',
		smallSrc: '',
		boardUrl: '',
		cardUrl: '',
		listCardUrl: '',
		imgCardUrl: '',
	}

	// Deliberately wider than any description column the app renders, so the
	// natural width alone would overflow every surface under test.
	const WIDE_PX = 1600
	const SMALL_PX = 24

	const DESCRIPTION = '# Heading\n\n**bold** and [a link](https://example.com)\n\n<script>alert(1)</script>'
	// Its own card so the description-mutating tests above can't race it.
	const LIST_DESCRIPTION = '# List heading\n\n- alpha\n- beta\n\n1. one\n2. two'

	test.beforeAll(async () => {
		// Clean up any leftover test board
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Markdown Test Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Seed board + stack + card
		const board = await api.post('/boards', { title: 'Markdown Test Board' })
		state.boardId = board.id

		const stack = await api.post('/stacks', { boardId: board.id, title: 'Test Stack' })
		state.stackId = stack.id

		const card = await api.post('/cards', { stackId: stack.id, title: 'MD Card' })
		state.cardId = card.id

		// PATCH the card description with markdown + XSS payload
		await api.patch(`/cards/${card.id}`, { description: DESCRIPTION })

		const listCard = await api.post('/cards', { stackId: stack.id, title: 'MD List Card' })
		state.listCardId = listCard.id
		await api.patch(`/cards/${listCard.id}`, { description: LIST_DESCRIPTION })

		// #147: its own card again — the image test needs the description and a
		// comment to stay put while the tests above rewrite `cardId`'s.
		const imgCard = await api.post('/cards', { stackId: stack.id, title: 'MD Image Card' })
		state.imgCardId = imgCard.id
		const wide = await uploadPng(imgCard.id, 'wide.png', WIDE_PX, 60)
		const small = await uploadPng(imgCard.id, 'small.png', SMALL_PX, SMALL_PX)
		state.wideSrc = `/apps/kanso/api/cards/${imgCard.id}/attachments/${wide.id}/inline`
		state.smallSrc = `/apps/kanso/api/cards/${imgCard.id}/attachments/${small.id}/inline`
		await api.patch(`/cards/${imgCard.id}`, {
			description: `![wide](${state.wideSrc})\n\n![small](${state.smallSrc})`,
		})
		await api.post(`/cards/${imgCard.id}/comments`, { body: `![wide](${state.wideSrc})` })

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
		state.listCardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${listCard.id}`
		state.imgCardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${imgCard.id}`
		console.log('Setup complete - cardUrl:', state.cardUrl)
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('renders markdown (bold, link) and strips XSS payload', async ({ page }) => {
		// Track any alert dialogs - XSS would fire one
		let alertFired = false
		page.on('dialog', async (dialog) => {
			alertFired = true
			await dialog.dismiss()
		})

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		// Wait for the card modal to appear
		await page.waitForSelector('.card-modal__desc-rendered', { timeout: 15_000 })

		// ── Positive assertions: safe markdown is rendered ────────────────────────

		// <strong> element inside the rendered description
		const strongEl = page.locator('.card-modal__desc-rendered strong')
		await expect(strongEl).toBeVisible()

		// <a> linking to https://example.com
		const linkEl = page.locator('.card-modal__desc-rendered a[href="https://example.com"]')
		await expect(linkEl).toBeVisible()

		// Link should have safe rel + target
		await expect(linkEl).toHaveAttribute('rel', 'noopener noreferrer')
		await expect(linkEl).toHaveAttribute('target', '_blank')

		// ── Negative assertions: XSS payload is neutralised ──────────────────────

		// No <script> elements inside the description container
		const scriptCount = await page.locator('.card-modal__desc-rendered script').count()
		expect(scriptCount).toBe(0)

		// No alert dialog was fired by the XSS payload
		expect(alertFired).toBe(false)

		// The raw text "<script>" must not appear as an unescaped tag in the HTML
		const descHtml = await page.locator('.card-modal__desc-rendered').innerHTML()
		expect(descHtml).not.toMatch(/<script[\s>]/i)
	})

	// Inline card-attachment images (#3525): a same-origin inline-endpoint <img>
	// renders; any OTHER img src (external host, data:, javascript:, svg,
	// protocol-relative) is stripped by the sanitiser — no external fetch, no XSS.
	test('renders a same-origin inline-attachment image and strips every other img', async ({ page }) => {
		let alertFired = false
		page.on('dialog', async (dialog) => {
			alertFired = true
			await dialog.dismiss()
		})

		// A description mixing a legit inline-attachment image with hostile ones.
		// The inline path is what cardAttachmentInlineUrl() produces for this card.
		const inlineSrc = `/apps/kanso/api/cards/${state.cardId}/attachments/1/inline`
		const md = [
			`![ok](${inlineSrc})`,
			'![ext](https://evil.example.com/pixel.png)',
			'![data](data:image/png;base64,AAAA)',
			'![proto](//evil.example.com/x.png)',
			'![svg](/apps/kanso/api/cards/1/attachments/1/inline.svg)',
			'![js](javascript:alert(1))',
			'<img src=x onerror=alert(1)>',
		].join('\n\n')
		await api.patch(`/cards/${state.cardId}`, { description: md })

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__desc-rendered', { timeout: 15_000 })

		// Exactly ONE img element survives: the same-origin inline-attachment one.
		// Every hostile img markdown produced NO rendered <img> (external/data:/
		// protocol-relative/svg src stripped by the hook; javascript:/raw-<img>
		// never became an element at all — they stay inert, escaped plain text).
		const imgs = page.locator('.card-modal__desc-rendered img')
		await expect(imgs).toHaveCount(1)
		const src = await imgs.first().getAttribute('src')
		expect(src).toContain(`/api/cards/${state.cardId}/attachments/1/inline`)

		// The surviving img carries NO on* handler and its src is same-origin only.
		const onerror = await imgs.first().getAttribute('onerror')
		expect(onerror).toBeNull()
		expect(src).not.toContain('evil.example.com')
		expect(src).not.toContain('data:')

		// No img element anywhere points at an external / data: / javascript: src
		// (i.e. nothing hostile was rendered as an actual <img>).
		expect(await page.locator('.card-modal__desc-rendered img[src*="evil.example.com"]').count()).toBe(0)
		expect(await page.locator('.card-modal__desc-rendered img[src^="data:"]').count()).toBe(0)
		expect(await page.locator('.card-modal__desc-rendered img[src^="//"]').count()).toBe(0)
		expect(await page.locator('.card-modal__desc-rendered img[onerror]').count()).toBe(0)

		// The onerror payload survives only as INERT escaped text, never as a live
		// attribute/element — so no dialog fires.
		expect(alertFired).toBe(false)

		// Restore the original description for the reload test below.
		await api.patch(`/cards/${state.cardId}`, { description: DESCRIPTION })
	})

	test('markdown is still safe after page reload', async ({ page }) => {
		let alertFired = false
		page.on('dialog', async (dialog) => {
			alertFired = true
			await dialog.dismiss()
		})

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__desc-rendered', { timeout: 15_000 })

		// Reload to verify persistence
		await page.reload()
		await page.waitForSelector('.card-modal__desc-rendered', { timeout: 15_000 })

		// Strong and link still rendered
		await expect(page.locator('.card-modal__desc-rendered strong')).toBeVisible()
		await expect(page.locator('.card-modal__desc-rendered a[href="https://example.com"]')).toBeVisible()

		// Still no XSS
		const scriptCount = await page.locator('.card-modal__desc-rendered script').count()
		expect(scriptCount).toBe(0)
		expect(alertFired).toBe(false)

		const descHtml = await page.locator('.card-modal__desc-rendered').innerHTML()
		expect(descHtml).not.toMatch(/<script[\s>]/i)
	})

	// #139: a saved description rendered its lists as unmarked, unindented text.
	// The renderer was never at fault — Nextcloud's core/css/server.css resets
	// `ul, ol, li` to no margin/padding and `ul` to `list-style: none`, and the
	// display container declared nothing to put back. So this asserts the
	// COMPUTED style, not just the markup: the markup half passed the whole time
	// the bug was live. Deliberately not a screenshot — there is no visual-diff
	// harness here, and computed values say exactly which half regressed.
	test('renders lists with markers and indentation (not just list markup)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.listCardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal__desc-rendered ul', { timeout: 15_000 })

		const container = page.locator('.card-modal__desc-rendered')
		const ul = container.locator('ul')
		const ol = container.locator('ol')

		// Renderer half: the sanitiser kept real list markup.
		await expect(ul.locator('li')).toHaveCount(2)
		await expect(ol.locator('li')).toHaveCount(2)

		// Styling half: markers are actually drawn.
		expect(await ul.evaluate((el) => getComputedStyle(el).listStyleType)).toBe('disc')
		expect(await ol.evaluate((el) => getComputedStyle(el).listStyleType)).toBe('decimal')

		// ...and both lists are indented, via the LOGICAL property so the markers
		// stay inside the content box in RTL too.
		const ulPad = await ul.evaluate((el) => parseFloat(getComputedStyle(el).paddingInlineStart))
		const olPad = await ol.evaluate((el) => parseFloat(getComputedStyle(el).paddingInlineStart))
		expect(ulPad).toBeGreaterThan(0)
		expect(olPad).toBeGreaterThan(0)

		// The reported symptom was ordered-list numbers hanging flush against the
		// container edge. A marker is painted OUTSIDE the li's box, so the li must
		// start measurably inside the container or the number is clipped away.
		const containerLeft = await container.evaluate((el) => el.getBoundingClientRect().left)
		const firstOlItemLeft = await ol.locator('li').first().evaluate((el) => el.getBoundingClientRect().left)
		const firstUlItemLeft = await ul.locator('li').first().evaluate((el) => el.getBoundingClientRect().left)
		expect(firstOlItemLeft - containerLeft).toBeGreaterThan(8)
		expect(firstUlItemLeft - containerLeft).toBeGreaterThan(8)

		// core/css/apps.scss re-styles h2-h6 but skips h1, so `# Heading` used to
		// render at body size.
		const h1Size = await container.locator('h1').evaluate((el) => parseFloat(getComputedStyle(el).fontSize))
		const pSize = await container.evaluate((el) => parseFloat(getComputedStyle(el).fontSize))
		expect(h1Size).toBeGreaterThan(pSize)
	})

	// #147: a wide attachment image rendered at its natural pixel width once the
	// description was SAVED, blowing past the description box and the card. The
	// editor was fine the whole time (MarkdownEditor.vue constrains its own
	// .ProseMirror images), so — exactly like the list bug above — this is a
	// styling gap on the read-only surfaces, and the assertions are on measured
	// geometry, not on markup that never regressed.
	test('clamps a wide image to the description width without upscaling a small one', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.imgCardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal__desc-rendered img', { timeout: 15_000 })

		const container = page.locator('.card-modal__desc-rendered')
		const wide = container.locator(`img[src*="${state.wideSrc}"]`)
		const small = container.locator(`img[src*="${state.smallSrc}"]`)
		await expect(wide).toBeVisible()
		await expect(small).toBeVisible()

		// The sanitiser sets loading="lazy", so wait for the bytes to actually
		// arrive — an undecoded img has naturalWidth 0 and would fake a pass.
		const decoded = (loc) => expect.poll(
			async () => loc.evaluate((el) => el.complete && el.naturalWidth),
			{ timeout: 10_000 },
		)
		await decoded(wide).toBe(WIDE_PX)
		await decoded(small).toBe(SMALL_PX)

		const box = async (loc) => (await loc.boundingBox()) || { width: 0, height: 0 }
		const containerBox = await box(container)
		const wideBox = await box(wide)
		const smallBox = await box(small)

		// Guard against a vacuous pass: the fixture must really be too wide for
		// this container, or clamping proves nothing.
		expect(WIDE_PX).toBeGreaterThan(containerBox.width)

		// The fix: clamped to the container, and the aspect ratio preserved
		// (1600×60 scaled down is far shorter than its natural 60px).
		expect(wideBox.width).toBeLessThanOrEqual(containerBox.width + 1)
		expect(wideBox.height).toBeCloseTo(wideBox.width * (60 / WIDE_PX), 0)

		// ...and nothing overflows horizontally — neither the description box nor
		// the modal body scrolls sideways.
		expect(await container.evaluate((el) => el.scrollWidth - el.clientWidth)).toBeLessThanOrEqual(1)

		// `max-width`, not `width`: a small image keeps its natural size.
		expect(Math.round(smallBox.width)).toBe(SMALL_PX)
		expect(Math.round(smallBox.height)).toBe(SMALL_PX)

		// Same renderer, same shared rule — a comment body clamps too (#147 listed
		// comments, the quick preview, the project view and the public share as
		// carrying the identical defect).
		const commentImg = page.locator(`.card-modal__comment-body img[src*="${state.wideSrc}"]`).first()
		await expect(commentImg).toBeVisible()
		await decoded(commentImg).toBe(WIDE_PX)
		const commentBody = page.locator('.card-modal__comment-body').first()
		const commentImgBox = await box(commentImg)
		const commentBodyBox = await box(commentBody)
		expect(commentImgBox.width).toBeLessThanOrEqual(commentBodyBox.width + 1)
	})
})
