// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, ncLogin, BASE, boardUrl, currentAuth } from './helpers.js'

const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }

// Local client: returns { ok, status, body } and never throws, so a test can
// assert on a 403 as easily as on a payload. `auth` is explicit because the
// denial cases act as a SECOND user.
async function call(auth, method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: auth },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const text = await r.text()
	return { ok: r.ok, status: r.status, body: text ? JSON.parse(text) : null }
}

const api = (method, path, body) => call(currentAuth, method, path, body)

// Multipart upload of a small in-memory file (the shared api client is JSON only).
async function uploadFile(cardId, filename, content) {
	const form = new FormData()
	form.append('file', new Blob([content], { type: 'text/plain' }), filename)
	const r = await fetch(API + `/cards/${cardId}/attachments`, {
		method: 'POST',
		headers: { 'OCS-APIREQUEST': 'true', Authorization: currentAuth },
		body: form,
	})
	if (!r.ok) throw new Error(`upload ${filename} failed: ${r.status}`)
	return r.json()
}

/** Open the board-wide attachment modal from the ⋯ More menu. */
async function openBoardAttachments(page) {
	await page.getByRole('button', { name: 'More' }).click()
	await page.getByRole('menuitem', { name: 'Board attachments' }).click()
	await page.waitForSelector('.board-attachments', { timeout: 15_000 })
}

/** Close whichever modal is on top and wait for it to actually go. */
async function closeCardModal(page) {
	await page.locator('.card-modal-modal .modal-container__close').click()
	await expect(page.locator('.card-modal')).toHaveCount(0)
}

// Board-wide attachment view (#10670): every file on the board in one modal,
// reachable from the ⋯ More menu, each row naming its owning card and opening it.
test.describe('Board attachments view', () => {
	let boardId = 0
	let stackId = 0
	let firstCardId = 0
	let secondCardId = 0

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Board attachments E2E' })).body.id
		stackId = (await api('POST', '/stacks', { boardId, title: 'Tasks' })).body.id
		firstCardId = (await api('POST', '/cards', { stackId, title: 'Spec card' })).body.id
		secondCardId = (await api('POST', '/cards', { stackId, title: 'Design card' })).body.id
		// Two files on two DIFFERENT cards: the point of the view is that one
		// listing spans the board, not a single card.
		await uploadFile(firstCardId, 'board-listing-spec.txt', 'spec bytes')
		await uploadFile(secondCardId, 'board-listing-logo.txt', 'logo bytes')
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('lists every board attachment and opens the owning card', async ({ page }) => {
		await ncLogin(page)
		await page.goto(boardUrl(boardId))
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: 'Board attachments' }).click()

		// Both files are listed, each next to the card it hangs off.
		const specRow = page.locator('.board-attachments__row', { hasText: 'board-listing-spec.txt' })
		const logoRow = page.locator('.board-attachments__row', { hasText: 'board-listing-logo.txt' })
		await expect(specRow).toHaveCount(1)
		await expect(logoRow).toHaveCount(1)
		await expect(specRow).toContainText('Spec card')
		await expect(logoRow).toContainText('Design card')

		// Download stays on the existing per-card endpoint, addressed by card +
		// attachment id - no second byte path was introduced for this view.
		await expect(specRow.locator('a.board-attachments__download'))
			.toHaveAttribute('href', new RegExp(`/api/cards/${firstCardId}/attachments/\\d+$`))

		// Clicking a row opens the card the file is attached to.
		await specRow.locator('.board-attachments__open').click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })
		await expect(page).toHaveURL(new RegExp(`/board/${boardId}/card/${firstCardId}$`))
	})

	// #10738 — the listing is cached under its OWN query key, and the per-card
	// attachment mutations used to invalidate only the card's list. So attaching
	// a file and looking at the board listing showed the list from BEFORE the
	// upload until the page was reloaded. Both halves are pinned here: the row
	// must appear on an upload and disappear on a removal, in one tab, with no
	// reload anywhere.
	test('an upload and a removal reach the board listing with no reload', async ({ page }) => {
		const LIVE = 'live-refresh.txt'

		await ncLogin(page)
		await page.goto(boardUrl(boardId))
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Any full page load past this point would be the very thing the card is
		// about, so count them and assert zero at the end.
		let reloads = 0
		page.on('load', () => { reloads++ })

		const liveRow = page.locator('.board-attachments__row', { hasText: LIVE })

		// ── add ────────────────────────────────────────────────────────────────
		// Open the listing once so the client HAS it cached (staleTime 30s) - a
		// cold cache would refetch on its own and prove nothing.
		let cachedAt = Date.now()
		await openBoardAttachments(page)
		await expect(liveRow).toHaveCount(0)

		// Open the owning card straight from the listing and upload there, so the
		// upload goes through the app's own mutation rather than a bare fetch.
		await page.locator('.board-attachments__row', { hasText: 'board-listing-spec.txt' })
			.locator('.board-attachments__open').click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })
		await page.setInputFiles('.card-modal__file-input', {
			name: LIVE,
			mimeType: 'text/plain',
			buffer: Buffer.from('shows up without a reload'),
		})
		await expect(page.locator('.card-modal__link-row', { hasText: LIVE })).toHaveCount(1)

		await closeCardModal(page)
		await openBoardAttachments(page)
		await expect(liveRow).toHaveCount(1)
		// Guard against a VACUOUS pass: past the 30s stale window the listing
		// would refetch on its own and this would hold with no invalidation at
		// all. Loud failure beats a green that proves nothing.
		expect(Date.now() - cachedAt,
			'this half outran the 30s cache window - the assertion above proves nothing')
			.toBeLessThan(28_000)

		// ── remove ─────────────────────────────────────────────────────────────
		// The reopen above refetched, so the stale window restarts here.
		cachedAt = Date.now()
		await liveRow.locator('.board-attachments__open').click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })
		await page.locator('.card-modal__link-row', { hasText: LIVE })
			.locator('.card-modal__child-remove').click()
		await expect(page.locator('.card-modal__link-row', { hasText: LIVE }))
			.toHaveCount(0)

		await closeCardModal(page)
		await openBoardAttachments(page)
		await expect(liveRow).toHaveCount(0)
		expect(Date.now() - cachedAt,
			'this half outran the 30s cache window - the assertion above proves nothing')
			.toBeLessThan(28_000)

		expect(reloads, 'the listing refreshed itself, so nothing should have reloaded').toBe(0)
	})
})

// #10738 — the server always took limit/offset and the modal never used them,
// so a board with more files than one page holds had rows with NO route in the
// UI at all. One page is BOARD_ATTACHMENTS_PAGE_SIZE rows; "Load more" walks
// the offsets.
test.describe('Board attachments - paging', () => {
	// Keep in step with BOARD_ATTACHMENTS_PAGE_SIZE (useBoardAttachments.js).
	const PAGE_SIZE = 25
	const TOTAL = PAGE_SIZE + 1
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		state.boardId = (await api('POST', '/boards', { title: 'Board attachments paging E2E' })).body.id
		const stack = (await api('POST', '/stacks', { boardId: state.boardId, title: 'Tasks' })).body
		const card = (await api('POST', '/cards', { stackId: stack.id, title: 'Paged card' })).body
		// Uploaded oldest-first, and the listing is newest-first, so file 00 is
		// the one that lands past the first page.
		for (let i = 0; i < TOTAL; i++) {
			await uploadFile(card.id, `paging-file-${String(i).padStart(2, '0')}.txt`, `bytes ${i}`)
		}
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`)
	})

	test('every file past the first page is reachable through Load more', async ({ page }) => {
		await ncLogin(page)
		await page.goto(boardUrl(state.boardId))
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await openBoardAttachments(page)

		const rows = page.locator('.board-attachments__row')
		const oldest = page.locator('.board-attachments__row', { hasText: 'paging-file-00.txt' })
		const loadMore = page.getByRole('button', { name: 'Load more files' })

		// One page on open - the request count does not grow with the board.
		await expect(rows).toHaveCount(PAGE_SIZE)
		await expect(oldest).toHaveCount(0)
		// …and the modal says how much of the board it is showing.
		await expect(page.locator('.board-attachments__count')).toContainText(String(TOTAL))
		await expect(page.locator('.board-attachments__shown')).toContainText(String(TOTAL))

		// The next page reaches the row the first page could not.
		await loadMore.click()
		await expect(rows).toHaveCount(TOTAL)
		await expect(oldest).toHaveCount(1)

		// Nothing left to ask for: the offer to load more goes away.
		await expect(loadMore).toHaveCount(0)
	})
})

// The denial half, over real HTTP with a real second user: board membership is
// not enough - the listing is scoped to what the CALLER may see, and a board
// they are no member of answers 403.
test.describe.serial('Board attachments - who may see what', () => {
	// Multi-user: BasicAuth per request, do not inherit the shared admin session.
	test.use({ storageState: { cookies: [], origins: [] } })

	const token = 'ba' + Math.floor(Date.now() / 1000)
	const state = { sharedBoardId: 0, unsharedBoardId: 0 }

	test.beforeAll(async ({ peer }) => {
		const board = (await api('POST', '/boards', { title: 'Board attachments shared ' + token })).body
		state.sharedBoardId = board.id
		const stack = (await api('POST', '/stacks', { boardId: board.id, title: 'Lane' })).body

		// peer is an INTERNAL member with READ - they see the board, and every
		// public card on it.
		await api('POST', `/boards/${board.id}/acl`, {
			participant: peer.user, participantType: 'user', permission: 1, role: 'internal',
		})

		// A public card's file, which the member MAY see…
		const pub = (await api('POST', '/cards', { stackId: stack.id, title: 'Shared card ' + token })).body
		await uploadFile(pub.id, `shared-${token}.txt`, 'visible to the member')

		// …and a PRIVATE card of the owner's, whose file they may not - the whole
		// point of this card: the listing must not become the way around it.
		const priv = (await api('POST', '/cards', { stackId: stack.id, title: 'Owner secret ' + token })).body
		await api('PATCH', `/cards/${priv.id}`, { visibility: 'private' })
		await uploadFile(priv.id, `owner-only-${token}.txt`, 'must never be listed')

		// Two MORE public files, uploaded last. The listing is newest-first, so
		// this ordering puts the owner-only file exactly where a paging bug would
		// surface it: off the peer's first page and onto their second (#10738).
		await uploadFile(pub.id, `shared-b-${token}.txt`, 'also visible')
		await uploadFile(pub.id, `shared-c-${token}.txt`, 'also visible')

		// A second board the peer is NO member of at all.
		const other = (await api('POST', '/boards', { title: 'Board attachments unshared ' + token })).body
		state.unsharedBoardId = other.id
		const otherStack = (await api('POST', '/stacks', { boardId: other.id, title: 'Lane' })).body
		const otherCard = (await api('POST', '/cards', { stackId: otherStack.id, title: 'Elsewhere ' + token })).body
		await uploadFile(otherCard.id, `stranger-${token}.txt`, 'another board entirely')
	})

	test.afterAll(async () => {
		for (const id of [state.sharedBoardId, state.unsharedBoardId]) {
			if (id) await api('DELETE', `/boards/${id}`).catch(() => {})
		}
	})

	test('a member never sees the file of a card hidden from them', async ({ peer }) => {
		const res = await call(peer.auth, 'GET', `/boards/${state.sharedBoardId}/attachments`)
		expect(res.status).toBe(200)

		const names = res.body.items.map((i) => i.filename)
		expect(names).toContain(`shared-${token}.txt`)
		expect(names).not.toContain(`owner-only-${token}.txt`)
		// The total is the viewer's total too - a count that saw more would leak
		// the existence of the hidden card's file.
		expect(res.body.total).toBe(3)

		// The owner, by contrast, sees all four.
		const asOwner = await api('GET', `/boards/${state.sharedBoardId}/attachments`)
		expect(asOwner.body.items.map((i) => i.filename).sort()).toEqual([
			`owner-only-${token}.txt`,
			`shared-${token}.txt`,
			`shared-b-${token}.txt`,
			`shared-c-${token}.txt`,
		].sort())
	})

	// #10738 — paging must not become the way around the scope. Page 1 of the
	// peer's listing is clean either way; the file they may not see sits at the
	// offset their SECOND page reads, which is exactly where an unscoped page
	// query would hand it over. Walked one page at a time, as the modal does.
	test('paging past the first page never reaches a hidden card\'s file', async ({ peer }) => {
		const seen = []
		let offset = 0
		let pages = 0

		for (;;) {
			const res = await call(peer.auth, 'GET',
				`/boards/${state.sharedBoardId}/attachments?limit=2&offset=${offset}`)
			expect(res.status).toBe(200)
			pages++

			// Every page is counted against the VIEWER's total, on every page.
			expect(res.body.total).toBe(3)
			for (const item of res.body.items) {
				expect(item.filename, 'a hidden card\'s file surfaced through paging')
					.not.toBe(`owner-only-${token}.txt`)
				seen.push(item.filename)
			}

			if (!res.body.capped || res.body.items.length === 0) break
			offset += res.body.items.length
			expect(pages, 'paging did not terminate').toBeLessThan(10)
		}

		// The walk really did reach a second page - otherwise this proves nothing.
		expect(pages).toBeGreaterThan(1)
		expect(seen.sort()).toEqual([
			`shared-${token}.txt`,
			`shared-b-${token}.txt`,
			`shared-c-${token}.txt`,
		].sort())

		// The owner pages over their own, larger, scope - four files, same walk.
		const ownerFirst = await api('GET', `/boards/${state.sharedBoardId}/attachments?limit=2&offset=0`)
		const ownerSecond = await api('GET', `/boards/${state.sharedBoardId}/attachments?limit=2&offset=2`)
		expect(ownerFirst.body.total).toBe(4)
		expect([...ownerFirst.body.items, ...ownerSecond.body.items].map((i) => i.filename))
			.toContain(`owner-only-${token}.txt`)
	})

	test('a non-member gets 403, not a listing', async ({ peer }) => {
		const res = await call(peer.auth, 'GET', `/boards/${state.unsharedBoardId}/attachments`)
		expect(res.status).toBe(403)
	})
})
