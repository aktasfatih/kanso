// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, currentAuth, me } from './helpers.js'

const BASE = 'http://localhost:8891'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }

// Authenticated API call as the CURRENT user (the board owner/MANAGE user).
// Reads `currentAuth` at call time so it follows the worker's identity under
// isolation, not a module-load snapshot.
async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: currentAuth },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const text = await r.text()
	return { ok: r.ok, status: r.status, body: text ? JSON.parse(text) : null }
}

// UNAUTHENTICATED fetch of the public JSON payload (no session, no OCS header).
async function fetchPublic(token) {
	const r = await fetch(`${API}/public/${encodeURIComponent(token)}`, {
		headers: { 'Content-Type': 'application/json' },
	})
	const text = await r.text()
	return { status: r.status, body: text ? JSON.parse(text) : null }
}

// Public / read-only board share links (#3531). A MANAGE user mints a token; an
// unauthenticated reader gets a STRIPPED read-only board; disabling 404s it.
test.describe('Public read-only board share', () => {
	// The unauthenticated fetchPublic / anonymous page assertions below must run as
	// a true anonymous reader. Opt OUT of the shared admin storageState, otherwise
	// the public payload/page loads under the admin session and the tests
	// false-pass or false-fail.
	test.use({ storageState: { cookies: [], origins: [] } })

	let boardId = 0
	let todoStackId = 0
	let cardId = 0
	let token = ''

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Public Share E2E' })).body.id
		todoStackId = (await api('POST', '/stacks', { boardId, title: 'To do' })).body.id
		cardId = (await api('POST', '/cards', { stackId: todoStackId, title: 'Public visible card' })).body.id
		// Add people/comments that MUST NOT surface on the public view.
		await api('PUT', `/cards/${cardId}/assignees/${me}`)
		await api('POST', `/cards/${cardId}/comments`, { message: 'internal comment SHOULD NOT LEAK' })
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('off by default; config reports disabled', async () => {
		const cfg = (await api('GET', `/boards/${boardId}/public-share`)).body
		expect(cfg.enabled).toBe(false)
		expect(cfg.url).toBeFalsy()
	})

	test('MANAGE enables a link and gets a public URL + token', async () => {
		const res = await api('POST', `/boards/${boardId}/public-share`)
		expect(res.status).toBe(200)
		expect(res.body.enabled).toBe(true)
		expect(res.body.token).toBeTruthy()
		expect(res.body.token.length).toBeGreaterThanOrEqual(32)
		expect(res.body.url).toContain('/p/')
		token = res.body.token
	})

	test('unauthenticated fetch returns the STRIPPED read-only payload', async () => {
		const res = await fetchPublic(token)
		expect(res.status).toBe(200)
		expect(res.body.board.title).toBe('Public Share E2E')

		// The board object carries no owner / acl / token / webhook - only the
		// presentational fields plus the comments opt-in flag (#3949).
		expect(Object.keys(res.body.board).sort()).toEqual(['color', 'commentsEnabled', 'prefix', 'title'])

		const card = res.body.cards.find((c) => c.title === 'Public visible card')
		expect(card).toBeTruthy()

		// No people, no comments, no internal metadata anywhere in the payload.
		const json = JSON.stringify(res.body)
		expect(json).not.toContain(me) // no assignee / owner uid
		expect(json).not.toContain('SHOULD NOT LEAK') // no comments
		expect(card.assignees).toBeUndefined()
		expect(card.assigneeIds).toBeUndefined()
		expect(card.comments).toBeUndefined()
		expect(card.commentCount).toBeUndefined()
		expect(card.owner).toBeUndefined()
		expect(card.reviewState).toBeUndefined()
		expect(res.body.acl).toBeUndefined()
		expect(res.body.subscription).toBeUndefined()
	})

	test('the public page renders read-only with no edit affordances or people', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		await expect(page.locator('.public-board__title')).toHaveText('Public Share E2E')
		await expect(page.locator('.public-board__badge')).toContainText('Read-only')
		// The board CSS must actually load (it ships in public.php, since the build
		// merges all entry CSS into the authenticated main bundle the public page
		// never loads). Assert the kanban layout, not a plain text list.
		await expect(page.locator('.public-board__columns')).toHaveCSS('display', 'flex')
		await expect(page.locator('.public-card__title').filter({ hasText: 'Public visible card' })).toBeVisible()
		// No comment box, no assignee avatars, no comment text.
		await expect(page.locator('body')).not.toContainText('SHOULD NOT LEAK')
		await expect(page.locator('.public-card__title')).toHaveCount(1)
	})

	test('no mutation is possible via the public routes', async () => {
		// There is no public write route; a POST to the data route is a 404
		// (route only registered for GET), and the authenticated mutation routes
		// still require a session (401/403 without auth).
		const noSessionPatch = await fetch(`${API}/cards/${cardId}`, {
			method: 'PATCH',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ title: 'hacked' }),
		})
		expect([401, 403, 404]).toContain(noSessionPatch.status)
		// The card title is unchanged.
		const card = (await api('GET', `/cards/${cardId}`)).body
		expect(card.title).toBe('Public visible card')
	})

	test('rotating the link invalidates the previous token', async () => {
		const old = token
		const res = await api('POST', `/boards/${boardId}/public-share`)
		expect(res.body.token).toBeTruthy()
		expect(res.body.token).not.toBe(old)
		token = res.body.token

		// The old token no longer resolves.
		expect((await fetchPublic(old)).status).toBe(404)
		// The new one does.
		expect((await fetchPublic(token)).status).toBe(200)
	})

	test('disabling the link makes it 404', async () => {
		expect((await api('DELETE', `/boards/${boardId}/public-share`)).status).toBe(200)
		expect((await fetchPublic(token)).status).toBe(404)

		const cfg = (await api('GET', `/boards/${boardId}/public-share`)).body
		expect(cfg.enabled).toBe(false)
	})

	test('an invalid / unknown token is a 404', async () => {
		expect((await fetchPublic('totally-made-up-token-that-does-not-exist')).status).toBe(404)
	})
})

// The public page is an ANONYMOUS view (#3945): it must be reachable with no
// session, scroll vertically so every card is reachable, and let a reader click
// a card to read its FULL description (the tile truncates long text at 240 chars).
test.describe('Public board is interactive read-only', () => {
	// Opt OUT of the shared admin storageState: visit as a true anonymous reader,
	// otherwise the page loads under the admin session and the test false-passes.
	test.use({ storageState: { cookies: [], origins: [] } })

	// A description longer than the 240-char tile clip, so "full text visible"
	// is a meaningful assertion (the tail only appears in the expanded detail).
	// It leads with markdown (a **bold** run) so the detail can assert the body is
	// rendered as HTML, not printed as raw markdown source.
	const LONG_DESC = 'HEAD_MARKER **BOLD_MARKER_7788** ' + 'lorem ipsum dolor sit amet '.repeat(20) + 'TAIL_MARKER_UNIQUE_9317'
	const COVER = '31CC31'
	// A token from the board's 'hours' estimate scale (set in beforeAll).
	const ESTIMATE = '4'

	let boardId = 0
	let token = ''

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Public Interactive E2E' })).body.id
		const stackId = (await api('POST', '/stacks', { boardId, title: 'To do' })).body.id
		// Enough cards to push the last one below the fold, plus one with a long
		// description we open to read in full.
		for (let i = 1; i <= 15; i++) {
			await api('POST', '/cards', { stackId, title: `Card number ${i}` })
		}
		// Enable an estimate scale so the card can carry a (non-person) estimate.
		await api('PATCH', `/boards/${boardId}`, { estimateScale: 'hours' })
		const detailCard = (await api('POST', '/cards', { stackId, title: 'Card with long description' })).body.id
		// Set the richer NON-person attributes exercised below (#3951): full
		// markdown description, cover colour, start date, estimate.
		await api('PATCH', `/cards/${detailCard}`, {
			description: LONG_DESC,
			coverColor: COVER,
			startDate: '2026-03-04T00:00:00+00:00',
			estimate: ESTIMATE,
		})
		token = (await api('POST', `/boards/${boardId}/public-share`)).body.token
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('scrolls vertically and opens a read-only card detail with full description', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		await expect(page.locator('.public-board__title')).toHaveText('Public Interactive E2E')

		// The mount is a real scroll container (all cards reachable, not just the
		// top of the fold).
		const scrollable = await page.locator('#kanso-public').evaluate((el) => el.scrollHeight > el.clientHeight)
		expect(scrollable).toBe(true)

		// The long-description tile is truncated on the board (tail marker hidden).
		const tile = page.locator('.public-card').filter({ hasText: 'Card with long description' })
		await tile.scrollIntoViewIfNeeded()
		await expect(tile).not.toContainText('TAIL_MARKER_UNIQUE_9317')

		// Clicking opens a read-only detail showing the FULL description.
		await tile.click()
		const detail = page.locator('.public-detail')
		await expect(detail).toBeVisible()
		await expect(detail).toContainText('HEAD_MARKER')
		await expect(detail).toContainText('TAIL_MARKER_UNIQUE_9317')

		// The description is rendered as MARKDOWN (not raw source): the **bold** run
		// becomes a <strong>, and the raw asterisks are gone.
		await expect(detail.locator('.public-detail__desc strong')).toHaveText('BOLD_MARKER_7788')
		await expect(detail.locator('.public-detail__desc')).not.toContainText('**BOLD_MARKER_7788**')

		// The richer NON-person attributes render (#3951): a cover-colour band, the
		// start date and the estimate. No person data is shown.
		await expect(detail.locator('.public-detail__cover')).toBeVisible()
		await expect(detail.locator('.public-detail__meta')).toContainText('Start')
		await expect(detail.locator('.public-detail__meta')).toContainText('Estimate')
		await expect(detail.locator('.public-detail__meta')).toContainText(ESTIMATE)

		// No edit affordances (no inputs/textareas in the read-only detail).
		await expect(detail.locator('input, textarea')).toHaveCount(0)

		// Closes again.
		await detail.locator('.public-detail__close').click()
		await expect(page.locator('.public-detail')).toHaveCount(0)
	})
})

// Opt-in exposure toggle (#3949): the public board is person-free by default,
// but a MANAGE user may DELIBERATELY enable read-only comments. When ON, an
// anonymous reader sees the thread (author display names only); when OFF, the
// comments never surface and the payload never carries them.
test.describe('Public board comments opt-in', () => {
	// True anonymous reader (opt out of the shared admin storageState).
	test.use({ storageState: { cookies: [], origins: [] } })

	let boardId = 0
	let cardId = 0
	let token = ''

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Public Comments E2E' })).body.id
		const stackId = (await api('POST', '/stacks', { boardId, title: 'To do' })).body.id
		cardId = (await api('POST', '/cards', { stackId, title: 'Card with a discussion' })).body.id
		// A top-level comment (markdown) and a reply, both by admin.
		const top = (await api('POST', `/cards/${cardId}/comments`, { body: 'PUBLIC_TOP **bold**' })).body
		await api('POST', `/cards/${cardId}/comments`, { body: 'PUBLIC_REPLY here', parentCommentId: top.id })
		token = (await api('POST', `/boards/${boardId}/public-share`)).body.token
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('OFF by default: payload carries no comments and the page hides them', async ({ page }) => {
		const res = await fetchPublic(token)
		expect(res.status).toBe(200)
		expect(res.body.board.commentsEnabled).toBe(false)
		const card = res.body.cards.find((c) => c.title === 'Card with a discussion')
		expect(card).toBeTruthy()
		expect(card.comments).toBeUndefined()
		const json = JSON.stringify(res.body)
		expect(json).not.toContain('PUBLIC_TOP')
		expect(json).not.toContain('PUBLIC_REPLY')

		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		await page.locator('.public-card').filter({ hasText: 'Card with a discussion' }).click()
		const detail = page.locator('.public-detail')
		await expect(detail).toBeVisible()
		// No comments section when the opt-in is off.
		await expect(detail.locator('.public-comments')).toHaveCount(0)
		await expect(detail).not.toContainText('PUBLIC_TOP')
	})

	test('ON: enabling the toggle surfaces a read-only thread with author display names', async ({ page }) => {
		// MANAGE user opts in.
		const cfg = (await api('PUT', `/boards/${boardId}/public-share/comments`, { enabled: true })).body
		expect(cfg.commentsEnabled).toBe(true)

		// Payload now carries the comments (author display name only, not the uid).
		const res = await fetchPublic(token)
		expect(res.body.board.commentsEnabled).toBe(true)
		const card = res.body.cards.find((c) => c.title === 'Card with a discussion')
		expect(Array.isArray(card.comments)).toBe(true)
		expect(card.comments.length).toBe(2)
		// The comment carries the author's DISPLAY NAME (resolved from the uid, like
		// the authenticated endpoint) - a non-empty string - and its markdown body,
		// timestamps and one-level parent link. (In this dev instance the admin's
		// display name and uid coincide; the display-name resolution itself is pinned
		// by the unit test with distinct names.)
		expect(typeof card.comments[0].author).toBe('string')
		expect(card.comments[0].author.length).toBeGreaterThan(0)
		expect(card.comments[0].parentCommentId).toBeNull()
		expect(card.comments[1].parentCommentId).toBe(card.comments[0].id)
		const json = JSON.stringify(res.body)
		expect(json).toContain('PUBLIC_TOP')
		// No reactions / reactor lists / assignee data ride the public comment.
		expect(card.comments[0].reactions).toBeUndefined()
		expect(json).not.toContain('reactor')
		expect(json).not.toContain('assignee')

		// The anonymous page renders the read-only thread with a rendered markdown
		// body and an initials avatar (no NcAvatar, no reply box).
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		await page.locator('.public-card').filter({ hasText: 'Card with a discussion' }).click()
		const comments = page.locator('.public-detail .public-comments')
		await expect(comments).toBeVisible()
		await expect(comments.locator('.public-comment__body strong').first()).toHaveText('bold')
		await expect(comments).toContainText('PUBLIC_REPLY')
		await expect(comments.locator('.public-comment__avatar').first()).toBeVisible()
		// Read-only: no comment input in the public thread.
		await expect(comments.locator('input, textarea')).toHaveCount(0)
	})

	test('toggling OFF again hides the thread and drops it from the payload', async () => {
		const cfg = (await api('PUT', `/boards/${boardId}/public-share/comments`, { enabled: false })).body
		expect(cfg.commentsEnabled).toBe(false)
		const res = await fetchPublic(token)
		expect(res.body.board.commentsEnabled).toBe(false)
		const card = res.body.cards.find((c) => c.title === 'Card with a discussion')
		expect(card.comments).toBeUndefined()
		expect(JSON.stringify(res.body)).not.toContain('PUBLIC_TOP')
	})
})
