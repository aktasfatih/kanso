// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// BASE/API come from helpers.js so this spec honours E2E_BASE_URL like every
// other spec; it used to hardcode http://localhost:8891 and silently ignore it.
import { test, expect, currentAuth, me, BASE, API, OCS, adminAuth, provisionUser, deleteUser } from './helpers.js'

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
//
// The endpoint is #[BruteForceProtection(action: 'kansoPublicShare')]
// (lib/Controller/PublicShareController.php:107,126), and this spec deliberately
// hits it with four rejected tokens per run (rotated, disabled, made-up). The
// throttle is per source IP and OUTLIVES the run, so a few repeated local runs
// against a long-lived dev instance start answering 429 with an HTML page for
// every request — which used to surface as `SyntaxError: Unexpected token '<'`
// from the JSON parse below and looked like a product failure. CI never sees it
// (fresh instance per run). dev/setup.sh now turns brute-force protection off in
// the dev stack; this guard is the backstop that names the cause if it is ever
// on again, instead of failing as an unreadable parse error.
async function fetchPublic(token) {
	const r = await fetch(`${API}/public/${encodeURIComponent(token)}`, {
		headers: { 'Content-Type': 'application/json' },
	})
	const text = await r.text()
	if (text && !(r.headers.get('content-type') || '').includes('json')) {
		throw new Error(
			`Public endpoint answered ${r.status} with a non-JSON body — this is an environment `
			+ 'problem, not a payload assertion. A 429 here means Nextcloud is brute-force '
			+ 'throttling this IP for the `kansoPublicShare` action. Clear it with '
			+ '`occ security:bruteforce:reset <ip>` — note that command silently no-ops while '
			+ '`auth.bruteforce.protection.enabled` is false (Throttler::resetDelayForIP returns '
			+ 'early), so set it true, reset, then set it back to false. '
			+ `First 200 chars: ${text.slice(0, 200)}`,
		)
	}
	return { status: r.status, body: text ? JSON.parse(text) : null }
}

// The EXHAUSTIVE key set of one anonymous card object, mirrored from the closed
// literal at lib/Service/PublicShareService.php:247-274. `comments` is the ONE
// conditional addition, and only when a MANAGE user opts in (:276-278).
//
// Asserting the whole KEY SET — not just the absence of a few known-bad VALUES —
// is what makes the leak guard hold. A raw-string check over the payload can only
// catch a leak whose text happens to match a fixture string, so a future
// person-bearing field on $cardPayload (an owner uid, an author, an email) slips
// past it whenever its value doesn't collide with one. An exact key set fails on
// the drift itself, whatever the value turns out to be.
const PUBLIC_CARD_KEYS = [
	'allDay', 'checklist', 'checklistItems', 'childIds', 'coverColor',
	'description', 'duedate', 'estimate', 'humanId', 'id', 'labels', 'priority',
	'stackId', 'startDate', 'status', 'title', 'type',
].sort()

// And the key set of one checklist ITEM (#135). The steps used to reach the
// anonymous reader as a bare "1/2" count; now they ship as content, which is
// the one place on this payload where a person field could newly land — a
// ChecklistItem row carries `assignedUser` (a login uid) and `assignedRole`
// (the internal EXTERNAL/INTERNAL side). The server hand-writes {title, done};
// this pins it so a future switch to the entity serializer fails here.
const PUBLIC_CHECKLIST_ITEM_KEYS = ['done', 'title'].sort()

// Likewise for one stack (PublicShareService.php:218-222): presentational only,
// and never the internal board id.
const PUBLIC_STACK_KEYS = ['color', 'id', 'title'].sort()

// And likewise for one entry of the card's nested `labels` array, built at
// PublicShareService.php:242. The card/stack/comment key sets above are pinned
// but this one was not (#10292), and it is the other nested object on the public
// payload — so the same drift a flat key set catches (a label gaining a
// createdBy, an owner, a lastEditedBy) would have slipped through here. Note the
// internal label `id` is deliberately NOT exposed.
const PUBLIC_LABEL_KEYS = ['color', 'name'].sort()

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
		// The field is `body` — CommentController::create() takes `string $body`.
		// This said `message`, so $body defaulted to '' and CommentService rejected
		// it as empty: NO comment was ever created, and the "no comments leak"
		// assertion below was vacuous. Assert the comment exists so a future rename
		// breaks loudly instead of silently disarming the leak guard.
		const comment = await api('POST', `/cards/${cardId}/comments`, { body: 'internal comment SHOULD NOT LEAK' })
		expect(comment.status).toBe(200)
		expect(comment.body.body).toContain('SHOULD NOT LEAK')
		// A label on the public card, so the nested-labels key assertion below runs
		// against a real entry instead of passing vacuously over an empty array.
		const label = await api('POST', '/labels', { boardId, title: 'Public label', color: '31CC7C' })
		expect(label.status).toBe(200)
		expect((await api('PUT', `/cards/${cardId}/labels/${label.body.id}`)).status).toBe(200)
	})

	// The token is minted by the 'MANAGE enables a link' test below, so every
	// later test used to depend on that one having run in the same worker. It
	// doesn't survive a retry (Playwright discards the worker after a failure and
	// re-runs beforeAll, leaving token='') or a single-test `-g` run — the empty
	// token then 404s into an HTML error page and the JSON parse blows up, which
	// reads like a product failure but is only test wiring. Mint on demand
	// instead, and never let an empty token reach an assertion.
	async function ensureToken() {
		if (!token) {
			token = (await api('POST', `/boards/${boardId}/public-share`)).body.token
		}
		expect(token).toBeTruthy()
		return token
	}

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
		const res = await fetchPublic(await ensureToken())
		expect(res.status).toBe(200)
		expect(res.body.board.title).toBe('Public Share E2E')

		// The board object carries no owner / acl / token / webhook - only the
		// presentational fields, the comments opt-in flag (#3949) and the
		// built-in-section switches (#5894, six booleans about the BOARD, never
		// about a person — CardFeatures::ALL) so the public link honours what the
		// manager hid.
		expect(Object.keys(res.body.board).sort()).toEqual(['cardFeatures', 'color', 'commentsEnabled', 'prefix', 'title'])

		const card = res.body.cards.find((c) => c.title === 'Public visible card')
		expect(card).toBeTruthy()

		// The card and stack objects carry EXACTLY the public field lists — the same
		// exhaustive treatment the board envelope gets above. This is the assertion
		// that catches drift: a person-bearing field added to the payload fails here
		// on its KEY, whatever its value happens to be.
		expect(Object.keys(card).sort()).toEqual(PUBLIC_CARD_KEYS)
		expect(res.body.stacks.length).toBe(1) // so the loop below can't pass by being empty
		for (const stack of res.body.stacks) {
			expect(Object.keys(stack).sort()).toEqual(PUBLIC_STACK_KEYS)
		}
		// The nested `labels` entries get the same exhaustive treatment. The length
		// check is what stops it passing over an empty array — beforeAll assigns
		// exactly one label to this card.
		expect(card.labels.length).toBe(1)
		for (const label of card.labels) {
			expect(Object.keys(label).sort()).toEqual(PUBLIC_LABEL_KEYS)
		}

		// Raw-string SUPPLEMENTS to the key-set assertions above — deliberately not
		// identity checks. `me` is both the acting uid AND its display name in the
		// e2e env (helpers.js:269 provisions displayName === username), so a trip on
		// the line below cannot tell a leaked uid from a leaked display name; it is a
		// substring match over the whole serialized payload for two exact fixture
		// strings, nothing more. Their value is coverage BREADTH (board and stack
		// envelopes too, not just the card keys), not precision.
		const json = JSON.stringify(res.body)
		expect(json).not.toContain(me)
		expect(json).not.toContain('SHOULD NOT LEAK') // no comment bodies while the opt-in is off
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
		await page.goto(`${BASE}/index.php/apps/kanso/p/${await ensureToken()}`)
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

	// #10446: the public page used to get no <title> of its own, so core's public
	// layout fell through to the instance name alone and EVERY public link on the
	// server shared one identical tab title — indistinguishable in a tab strip, a
	// bookmark or browser history.
	test('the public page carries the board name in the tab title', async ({ page }) => {
		const live = await ensureToken()
		await page.goto(`${BASE}/index.php/apps/kanso/p/${live}`)

		// "<board> - <instance>", using core's own separator. The instance name is
		// themable so it is matched loosely; the board name is the load-bearing part.
		await expect(page).toHaveTitle(/^\s*Public Share E2E - \S/)
		// Never a stray/empty prefix or a stringified undefined.
		await expect(page).not.toHaveTitle(/undefined|^\s*-\s/)

		// Server-rendered, not painted in later by the Vue app: the title is already
		// in the raw HTML, so the tab is right at first paint (this is the page most
		// likely to be opened cold on a slow connection) and with JS disabled. This
		// also pins WHERE the fix lives — the public entry point is deliberately
		// router-free and must stay that way.
		const html = await (await fetch(`${BASE}/index.php/apps/kanso/p/${live}`)).text()
		expect(html).toMatch(/<title>\s*Public Share E2E - \S/)
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
		const old = await ensureToken()
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
		const live = await ensureToken()
		expect((await api('DELETE', `/boards/${boardId}/public-share`)).status).toBe(200)
		expect((await fetchPublic(live)).status).toBe(404)

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
	// It ends with a markdown list: templates/public.php is a SEPARATE css surface
	// from the app bundle's src/styles/markdown.css, so the #139 list fix has to be
	// asserted here too or the public share can regress on its own.
	const LONG_DESC = 'HEAD_MARKER **BOLD_MARKER_7788** ' + 'lorem ipsum dolor sit amet '.repeat(20)
		+ 'TAIL_MARKER_UNIQUE_9317\n\n- LIST_ITEM_ALPHA\n- LIST_ITEM_BETA'
	const COVER = '31CC31'
	// A token from the board's 'hours' estimate scale (set in beforeAll).
	const ESTIMATE = '4'
	const PARENT_TITLE = 'Parent with a sub-card'
	const CHILD_TITLE = 'The sub-card itself'

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
		// A nested pair (#135): the public payload used to carry no parent/child
		// linkage at all, so a shared card's sub-cards were invisible on the link.
		const parentId = (await api('POST', '/cards', { stackId, title: PARENT_TITLE })).body.id
		const childId = (await api('POST', '/cards', { stackId, title: CHILD_TITLE })).body.id
		expect((await api('PUT', `/cards/${childId}/parent`, { parentCardId: parentId })).status).toBe(200)
		token = (await api('POST', `/boards/${boardId}/public-share`)).body.token
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('a card detail lists its sub-cards, resolved from the cards the visitor already holds', async ({ page }) => {
		const res = await fetchPublic(token)
		expect(res.status).toBe(200)
		const parent = res.body.cards.find((c) => c.title === PARENT_TITLE)
		const child = res.body.cards.find((c) => c.title === CHILD_TITLE)
		expect(parent).toBeTruthy()
		expect(child).toBeTruthy()
		// IDs only, and every id addresses a card this same snapshot carries in
		// full — which is exactly why naming the edge discloses nothing new.
		expect(parent.childIds).toEqual([child.id])
		// The child is an ordinary tile on the board too (the kanban surface draws
		// no levels), and names no children of its own.
		expect(child.childIds).toEqual([])
		// A new permitted key, nothing more: the field list is still exactly the
		// public one.
		expect(Object.keys(parent).sort()).toEqual(PUBLIC_CARD_KEYS)

		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		const tile = page.locator('.public-card').filter({ hasText: PARENT_TITLE })
		await tile.scrollIntoViewIfNeeded()
		await tile.click()
		const detail = page.locator('.public-detail')
		await expect(detail).toBeVisible()
		const subs = detail.locator('.public-subcards')
		await expect(subs).toBeVisible()
		await expect(subs.locator('.public-subcard')).toHaveCount(1)
		await expect(subs).toContainText(CHILD_TITLE)
		// Still read-only: a reference, not an editor.
		await expect(subs.locator('input, textarea')).toHaveCount(0)

		// Clicking a reference opens THAT card's own read-only detail…
		await subs.locator('.public-subcard').click()
		await expect(detail.locator('.public-detail__title')).toHaveText(CHILD_TITLE)
		// …which has none of its own, so the section is absent there rather than
		// rendered empty.
		await expect(detail.locator('.public-subcards')).toHaveCount(0)
	})

	test('scrolls inside the column and opens a read-only card detail with full description', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		await expect(page.locator('.public-board__title')).toHaveText('Public Interactive E2E')

		// #117: this used to assert the OPPOSITE — that #kanso-public itself
		// overflows — which pinned the bug as the spec. Two vertical scrollers
		// fought each other: the column was capped at `calc(100vh - 180px)` while
		// its real box is the window minus NC's header and the board's own chrome,
		// so the page over-scrolled by a constant at every viewport size. The mount
		// is now a fixed-height shell that does NOT scroll…
		const outer = await page.locator('#kanso-public').evaluate((el) => ({
			scrollHeight: el.scrollHeight,
			clientHeight: el.clientHeight,
		}))
		expect(outer.scrollHeight).toBeLessThanOrEqual(outer.clientHeight)

		// …and the card list inside the column is the thing that scrolls (18 cards
		// do not fit a phone- or laptop-height column).
		const inner = await page.locator('.public-col__cards').first().evaluate((el) => ({
			scrollHeight: el.scrollHeight,
			clientHeight: el.clientHeight,
		}))
		expect(inner.scrollHeight).toBeGreaterThan(inner.clientHeight)

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

		// ...and a list is drawn AS a list (#139). Core's server.css resets `ul` to
		// `list-style: none` with no padding on every Nextcloud page, this one
		// included, so the computed style is what proves the markers survived —
		// the markup alone passed all through the bug.
		const shareUl = detail.locator('.public-detail__desc ul')
		await expect(shareUl.locator('li')).toHaveCount(2)
		expect(await shareUl.evaluate((el) => getComputedStyle(el).listStyleType)).toBe('disc')
		expect(await shareUl.evaluate((el) => parseFloat(getComputedStyle(el).paddingInlineStart))).toBeGreaterThan(0)

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

// #117: the public share has to be a PROPER page — fill the window, and keep
// its scrolling INSIDE the board instead of around it.
//
// Two columns is the case that exposes the shrink-wrap: #kanso-public is a flex
// item of NC's #content (display: flex), and with no flex/width it fell back to
// `flex: 0 1 auto` and sized to max-content, so a 2-column board drew in a 648px
// strip on a 1600px screen with 936px dead. Six columns would hide it — their
// max-content already exceeds the viewport.
test.describe('Public board layout fills the window', () => {
	// True anonymous reader (opt out of the shared admin storageState) — under
	// the admin session NC renders its own app chrome around #content and these
	// width/overflow numbers would be measured on a different page.
	test.use({ storageState: { cookies: [], origins: [] } })

	let boardId = 0
	let token = ''

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Public Layout E2E' })).body.id
		const todo = (await api('POST', '/stacks', { boardId, title: 'To do' })).body.id
		await api('POST', '/stacks', { boardId, title: 'Done' })
		// Enough cards that the first column's list must scroll at any viewport
		// tested below — that inner scroll is the one that has to exist.
		for (let i = 1; i <= 25; i++) {
			await api('POST', '/cards', { stackId: todo, title: `Layout card ${i}` })
		}
		token = (await api('POST', `/boards/${boardId}/public-share`)).body.token
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	// Desktop, laptop and phone. The bug was viewport-INDEPENDENT (the same
	// shrink-wrapped strip, and an outer overflow of a constant ~60-90px at every
	// size), so one viewport would not have shown it was arithmetic.
	for (const vp of [{ width: 1600, height: 900 }, { width: 1280, height: 800 }, { width: 390, height: 844 }]) {
		test(`fills #content and scrolls inside the column at ${vp.width}x${vp.height}`, async ({ page }) => {
			await page.setViewportSize(vp)
			await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
			await expect(page.locator('.public-board__title')).toHaveText('Public Layout E2E')
			await expect(page.locator('.public-col')).toHaveCount(2)

			const m = await page.evaluate(() => {
				const q = (s) => document.querySelector(s)
				const content = q('#content')
				const mount = q('#kanso-public')
				const cols = q('.public-board__columns')
				const list = q('.public-col__cards')
				const footer = q('.public-board__footer')
				return {
					contentWidth: content.getBoundingClientRect().width,
					boardWidth: q('.public-board').getBoundingClientRect().width,
					outerOverflow: mount.scrollHeight - mount.clientHeight,
					docOverflow: document.documentElement.scrollHeight - document.documentElement.clientHeight,
					colsOverflowY: cols.scrollHeight - cols.clientHeight,
					listOverflow: list.scrollHeight - list.clientHeight,
					footerBottom: footer.getBoundingClientRect().bottom,
					innerHeight: window.innerHeight,
				}
			})

			// The board fills the row it sits in rather than shrink-wrapping to its
			// columns (this was 648 vs 1584 at 1600px wide).
			expect(m.boardWidth).toBeGreaterThanOrEqual(m.contentWidth - 1)

			// Nothing scrolls vertically AROUND the board: no outer scroller on the
			// mount, the document, or the stack row. This is the assertion that used
			// to be inverted — it asserted the mount DOES overflow, which pinned the
			// two-fighting-scrollers bug as the expected behaviour.
			expect(m.outerOverflow).toBeLessThanOrEqual(0)
			expect(m.docOverflow).toBeLessThanOrEqual(0)
			expect(m.colsOverflowY).toBeLessThanOrEqual(0)

			// The column's card list is what scrolls instead.
			expect(m.listOverflow).toBeGreaterThan(0)

			// And the footer is on screen without any outer scrolling. Honest note:
			// core's `#body-public footer` rule pins it to the viewport bottom
			// (computed `position: fixed`), so this holds by construction rather
			// than by the flex shell — it is a tripwire for a future change that
			// takes it out of that rule, not the discriminating assertion above.
			expect(m.footerBottom).toBeLessThanOrEqual(m.innerHeight + 1)
		})
	}
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
		// Opting in adds EXACTLY one key to the card — `comments` — and the comment
		// object carries exactly {id, parentCommentId, author, body, timestamps}.
		// This is the one place a person-data regression can actually land (an
		// `authorUid` alongside the display name), so pin both key sets here too.
		expect(Object.keys(card).sort()).toEqual([...PUBLIC_CARD_KEYS, 'comments'].sort())
		expect(Object.keys(card.comments[0]).sort()).toEqual(['author', 'body', 'createdAt', 'editedAt', 'id', 'parentCommentId'])
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

// A card title may legitimately CONTAIN the acting user's uid inside a longer word
// ("administrator notes" contains "admin"), and such a card must still be served
// VERBATIM on the public link — the fix for a person-data leak is a narrower
// payload, never scrubbing uid-shaped text out of board content. Nothing else in
// the suite covers that: every other fixture title is uid-free, so an over-eager
// redaction would pass unnoticed. The board is its own because the raw-string
// supplement in the leak test above would read this title as a leak — the two
// cannot share a payload, which is itself the point.
test.describe('Public payload key sets are substring-immune', () => {
	// Match the sibling describes and keep the shared admin storageState out of it.
	// (Nothing here drives a browser — fetchPublic is cookieless and the local api()
	// carries its own Authorization — so this is consistency, not load-bearing.)
	test.use({ storageState: { cookies: [], origins: [] } })

	let boardId = 0
	let token = ''
	let title = ''

	test.beforeAll(async () => {
		// Built here, not at module scope: `me` is rebound by the worker fixture
		// (helpers.js:269), and a describe body runs at collection time — before the
		// rebind — so a top-level snapshot would embed 'admin' instead of the worker.
		title = `${me}istrator notes`
		boardId = (await api('POST', '/boards', { title: 'Public Substring E2E' })).body.id
		const stackId = (await api('POST', '/stacks', { boardId, title: 'To do' })).body.id
		await api('POST', '/cards', { stackId, title })
		token = (await api('POST', `/boards/${boardId}/public-share`)).body.token
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('a card title that embeds the uid in a longer word is not a leak', async () => {
		const res = await fetchPublic(token)
		expect(res.status).toBe(200)
		// The title is served verbatim, uid substring and all — no redaction of
		// legitimate board content. This is the assertion that can fail here.
		const card = res.body.cards.find((c) => c.title === title)
		expect(card).toBeTruthy()
		// And no false red from the structural guard: keys are value-blind, so the
		// key set is still exactly the public field list.
		expect(Object.keys(card).sort()).toEqual(PUBLIC_CARD_KEYS)
	})
})

// The built-in card sections a manager can switch off (#5894) are a BOARD-level
// setting, and the public link is the same board — so the anonymous view follows
// the same switches. tests/e2e/card-features.spec.js asserts the checklist switch
// on the authenticated tile and card modal, plus attachments and time tracking;
// the public share was uncovered, and it is the only surface whose audience is
// anonymous. (For `coverColor`, this is the only place in the suite that asserts
// the switch changes anything ON SCREEN at all — card-features.spec.js checks its
// settings checkbox and its payload flag, never a rendered cover band.)
//
// Two keys are exercised, because it is one test shape used twice: `checklist`
// (src/views/PublicBoard.vue:55, :118 and the `hasMeta` computed at :213) and
// `coverColor` (:84). Deleting any of those guards must turn this test red.
//
// SCOPE, stated so nobody later reads this as more than it is: hiding a section
// is PRESENTATION-ONLY by design (lib/Db/CardFeatures.php:28-38 — "Enforcement:
// CLIENT-SIDE ONLY (deliberate)"). The payload assertions at the end pin exactly
// that: the anonymous JSON still carries the checklist counts and the cover
// colour while both sections are hidden. This is a RENDERING contract, not a
// confidentiality one; making it one would be a server change, not a test change.
test.describe('Public board honours the hidden card sections', () => {
	// True anonymous reader (opt out of the shared admin storageState). This is
	// load-bearing here, not decoration: under the admin session the page would
	// still render and every "…is hidden" assertion would pass for the wrong
	// reason. The test asserts its own anonymity below rather than trusting this.
	test.use({ storageState: { cookies: [], origins: [] } })

	const CARD_TITLE = 'Card with a cover and a checklist'
	const DATED_TITLE = 'Card with a due date and a checklist'
	const COVER = 'cc3311'

	let boardId = 0
	let token = ''

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Public Card Features E2E' })).body.id
		const stackId = (await api('POST', '/stacks', { boardId, title: 'To do' })).body.id
		const cardId = (await api('POST', '/cards', { stackId, title: CARD_TITLE })).body.id
		await api('PATCH', `/cards/${cardId}`, { coverColor: COVER })
		// One step of two ticked, so every surface has a 1/2 to show. This card
		// deliberately carries NO other meta (no priority, due/start date or
		// estimate): that makes the checklist the only thing keeping the detail's
		// meta ROW alive, which is what pins the `hasMeta` guard.
		const step = (await api('POST', `/cards/${cardId}/checklist`, { title: 'Step one' })).body
		await api('PATCH', `/checklist/${step.id}`, { done: true })
		await api('POST', `/cards/${cardId}/checklist`, { title: 'Step two' })

		// A SECOND card whose meta row survives the checklist being hidden, because
		// it also has a due date. Without it the FIELD guard inside the meta row is
		// untestable: `hasMeta` already removes the whole row for the card above, so
		// the two guards could only ever be proven together. Here the row stays and
		// only the 0/1 must go.
		const datedId = (await api('POST', '/cards', { stackId, title: DATED_TITLE })).body.id
		await api('PATCH', `/cards/${datedId}`, { duedate: '2026-05-06T12:00:00+00:00' })
		await api('POST', `/cards/${datedId}/checklist`, { title: 'Only step' })

		token = (await api('POST', `/boards/${boardId}/public-share`)).body.token
		expect(token).toBeTruthy()
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('both sections render while they are on, and vanish once the board hides them', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		await expect(page.locator('.public-board__title')).toHaveText('Public Card Features E2E')

		// This browser context really is anonymous — asserted, not assumed. An
		// authenticated Kanso call from the page's own origin is refused, which it
		// would not be if the shared admin storageState had leaked in: a live session
		// answers 200, or 412 (CSRF, no requesttoken on a bare fetch). Neither is in
		// the accepted set, so the guard catches a leak either way. Without it the
		// "hidden" assertions below could all be vacuous.
		//
		// `Accept: application/json` is load-bearing, not decoration: NC's
		// SecurityMiddleware answers an unauthenticated request with a JSON 401 only
		// for a JSON-ish Accept, and with a 303 to the login page for `text/html`
		// (which fetch would follow into a 200). The path comes from `API` rather
		// than a literal so this honours E2E_BASE_URL under a webroot subdirectory,
		// the same reason the header of this file gives for not hardcoding a host.
		const apiPath = new URL(API).pathname
		const authedStatus = await page.evaluate(async (p) => {
			const r = await fetch(p + '/boards', {
				headers: { Accept: 'application/json', 'OCS-APIREQUEST': 'true' },
			})
			return r.status
		}, apiPath)
		expect([401, 403]).toContain(authedStatus)

		const tile = page.locator('.public-card').filter({ hasText: CARD_TITLE })
		const datedTile = page.locator('.public-card').filter({ hasText: DATED_TITLE })
		const detail = page.locator('.public-detail')
		const meta = detail.locator('.public-detail__meta')

		// --- Both features ON (the default): the badge, the cover band and the
		//     checklist meta field are all there.
		await expect(tile.locator('.public-card__check')).toHaveText('1/2')
		await tile.click()
		await expect(detail).toBeVisible()
		await expect(detail.locator('.public-detail__cover')).toBeVisible()
		// The checklist is this card's ONLY meta, so the whole row reads '1/2'.
		await expect(meta).toHaveText('1/2')
		// …and the steps THEMSELVES are readable (#135), in their own section below
		// the meta row — the bare count was all an anonymous visitor ever got, which
		// is the bug. Read-only: a styled tick, never an <input>.
		const steps = detail.locator('.public-checklist')
		await expect(steps).toBeVisible()
		await expect(steps.locator('.public-checklist__item')).toHaveCount(2)
		await expect(steps).toContainText('Step one')
		await expect(steps).toContainText('Step two')
		await expect(steps.locator('input, textarea')).toHaveCount(0)
		// The ticked one is marked done, so "1 of 2" is legible as more than a number.
		await expect(steps.locator('.public-checklist__item--done')).toHaveCount(1)
		await expect(steps.locator('.public-checklist__item--done')).toContainText('Step one')
		await detail.locator('.public-detail__close').click()
		await expect(detail).toHaveCount(0)

		// The dated card shows its progress alongside the due date.
		await expect(datedTile.locator('.public-card__check')).toHaveText('0/1')
		await datedTile.click()
		await expect(detail).toBeVisible()
		await expect(meta).toContainText('Due')
		await expect(meta).toContainText('0/1')
		await detail.locator('.public-detail__close').click()
		await expect(detail).toHaveCount(0)

		// --- The manager hides both sections on the board.
		const patched = await api('PATCH', `/boards/${boardId}`, {
			cardFeatures: { checklist: false, coverColor: false },
		})
		expect(patched.status).toBe(200)
		// Read it back before touching the page, so a switch that never landed fails
		// here with a clear cause instead of as a confusing "still visible" below.
		const stored = (await api('GET', `/boards/${boardId}`)).body.board.cardFeatures
		expect(stored.checklist).toBe(false)
		expect(stored.coverColor).toBe(false)

		// --- Both features OFF: the anonymous view drops them. The card itself is
		//     still listed — hiding a section is not hiding the card.
		await page.reload()
		await expect(page.locator('.public-board__title')).toHaveText('Public Card Features E2E')
		await expect(tile).toBeVisible()
		// The badge is GONE, not merely emptied.
		await expect(tile.locator('.public-card__check')).toHaveCount(0)
		await expect(tile).not.toContainText('1/2')

		await tile.click()
		await expect(detail).toBeVisible()
		await expect(detail.locator('.public-detail__cover')).toHaveCount(0)
		// The checklist was this card's only meta, so the row goes with it — the
		// `hasMeta` guard.
		await expect(meta).toHaveCount(0)
		await expect(detail).not.toContainText('1/2')
		// The step LIST follows the same switch as the count it belongs to (#135):
		// gone, not merely emptied, and no step title survives anywhere in the
		// detail.
		await expect(detail.locator('.public-checklist')).toHaveCount(0)
		await expect(detail).not.toContainText('Step one')
		await expect(detail).not.toContainText('Step two')
		// Still the same read-only detail otherwise.
		await expect(detail.locator('.public-detail__title')).toHaveText(CARD_TITLE)
		await detail.locator('.public-detail__close').click()
		await expect(detail).toHaveCount(0)

		// The dated card's meta row SURVIVES (it still has a due date) — and the
		// progress is gone from inside it. This is the field guard on its own, the
		// one `hasMeta` would otherwise mask.
		// Assert the tile is there BEFORE counting what it must not contain, so the
		// count-0 can't pass by the tile itself having gone missing.
		await expect(datedTile).toBeVisible()
		await expect(datedTile.locator('.public-card__check')).toHaveCount(0)
		await datedTile.click()
		await expect(detail).toBeVisible()
		await expect(meta).toBeVisible()
		await expect(meta).toContainText('Due')
		await expect(meta).not.toContainText('0/1')

		// --- Presentation-only, exactly as documented: the anonymous PAYLOAD is
		//     unchanged by the switches. The counts and the colour are still served;
		//     only the rendering above respects the flags.
		const res = await fetchPublic(token)
		expect(res.status).toBe(200)
		const payloadCard = res.body.cards.find((c) => c.title === CARD_TITLE)
		expect(payloadCard).toBeTruthy()
		expect(payloadCard.checklist).toEqual({ total: 2, done: 1 })
		// Same for the items (#135) — still served while the section is hidden, and
		// still {title, done} ONLY. The key-set assertion is the leak guard: a
		// checklist row also carries `assignedUser` / `assignedRole`, which the
		// hand-written shape drops and a delegated serializer would not.
		expect(payloadCard.checklistItems).toEqual([
			{ title: 'Step one', done: true },
			{ title: 'Step two', done: false },
		])
		expect(Object.keys(payloadCard.checklistItems[0]).sort()).toEqual(PUBLIC_CHECKLIST_ITEM_KEYS)
		expect(payloadCard.coverColor).toBe(COVER)
		expect(res.body.board.cardFeatures.checklist).toBe(false)
		expect(res.body.board.cardFeatures.coverColor).toBe(false)
	})
})

// A `@mention` is the one way a real LOGIN uid rides the anonymous payload as
// "board content": mentions have no entity table, so they are stored as the
// literal string `@uid` inside a card description and a comment body, and the
// public page renders them as chips. The key-set assertions above cannot catch
// this — the uid arrives inside the VALUE of a permitted key — so it needs its
// own board and its own fixture.
//
// This is the DEFAULT configuration, not an opt-in: the description case holds
// with the comments toggle off, which is why the first test does not touch it.
//
// Why a dedicated provisioned account instead of `me`: helpers.js:269 provisions
// the worker's own user with displayName === username, so substituting a display
// name for that uid changes nothing and every assertion here would pass
// vacuously. This account's display name deliberately differs from its uid, and
// beforeAll asserts that it really does.
test.describe('Public payload redacts @mention uids', () => {
	// Nothing here drives a browser, but keep the shared admin storageState out of
	// it like every sibling describe — fetchPublic must stay cookieless.
	test.use({ storageState: { cookies: [], origins: [] } })

	// SCOPE, so the whole-payload assertion below is not over-trusted: only the two
	// FREE-TEXT fields are redacted. A `@name` typed into a card/stack/board title or
	// a label name still ships verbatim by design — those are not mention surfaces
	// and the sibling 'substring-immune' describe pins them as byte-exact. The
	// `not.toContain(mentioned)` checks hold here because this board's titles are
	// mention-free, not because the payload redacts everything.
	//
	// The three tests share one fixture and run in declaration order (the config is
	// serial per file: workers default to 1 and fullyParallel is off), which the
	// second one relies on — it flips the comments toggle the first asserts is off.

	const DISPLAY_NAME = 'Mona Mentioned'
	const PASS = 'Public#Mention2026'
	const CARD_TITLE = 'Card that mentions a board member'
	// `@`-shaped text that is NOT an account and must survive byte-identical: an
	// email address, a social handle, a time, an unknown uid. Over-eager
	// substitution here would corrupt real board content, so these are asserted as
	// explicitly as the redaction itself.
	const DECOYS = ['foo@bar.com', '@nextcloud', '@9.30', '@nosuchuser-42']

	let mentioned = ''
	let description = ''
	let boardId = 0
	let cardId = 0
	let token = ''

	test.beforeAll(async ({}, workerInfo) => {
		// Per-worker unique, so parallel workers never fight over the account.
		mentioned = `kanso_pubmention_w${workerInfo.workerIndex}`
		// Delete-then-create: the shared provisionUser is idempotent, so a leftover
		// account from an earlier run would keep its OLD display name and the
		// assertions below would be comparing against the wrong string.
		await deleteUser(mentioned)
		await provisionUser(mentioned, PASS, { displayName: DISPLAY_NAME })
		// Assert the display name actually landed AND that it differs from the uid.
		// Without this the whole describe can pass vacuously: if the display name
		// were the uid (Nextcloud's fallback when none is set), substituting one for
		// the other is a no-op and "the uid is gone" could never fail.
		const info = await fetch(`${OCS}/users/${encodeURIComponent(mentioned)}`, {
			headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json', Authorization: adminAuth },
		})
		const stored = (await info.json()).ocs.data.displayname
		expect(stored).toBe(DISPLAY_NAME)
		expect(stored).not.toContain(mentioned)

		boardId = (await api('POST', '/boards', { title: 'Public Mention E2E' })).body.id
		// A real board member — this is a member's login uid, not a stranger's.
		expect((await api('POST', `/boards/${boardId}/acl`, {
			participant: mentioned, participantType: 'user', permission: 3,
		})).status).toBe(200)
		const stackId = (await api('POST', '/stacks', { boardId, title: 'To do' })).body.id
		cardId = (await api('POST', '/cards', { stackId, title: CARD_TITLE })).body.id
		// Two shapes of the same mention: one followed by a space, and one ENDING a
		// sentence — `.` is a legal uid character, so the second token is `<uid>.` and
		// only resolves after the trailing punctuation is trimmed. Writing an ordinary
		// English sentence was the bypass.
		description = `Assigned to @${mentioned} for review. Also ping @${mentioned}. Decoys: ${DECOYS.join(' ')}`
		expect((await api('PATCH', `/cards/${cardId}`, { description })).status).toBe(200)
		expect((await api('POST', `/cards/${cardId}/comments`, {
			body: `cc @${mentioned} — please look`,
		})).status).toBe(200)
		token = (await api('POST', `/boards/${boardId}/public-share`)).body.token
		expect(token).toBeTruthy()
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
		await deleteUser(mentioned)
	})

	test('the default payload serves the description with display names, never uids', async () => {
		const res = await fetchPublic(token)
		expect(res.status).toBe(200)
		// Comments opt-in untouched: this is the out-of-the-box configuration.
		expect(res.body.board.commentsEnabled).toBe(false)

		const card = res.body.cards.find((c) => c.title === CARD_TITLE)
		expect(card).toBeTruthy()
		expect(card.description).toContain(DISPLAY_NAME)
		// THE assertion: no board member's uid anywhere in what the token serves.
		expect(card.description).not.toContain(mentioned)
		expect(JSON.stringify(res.body)).not.toContain(mentioned)
		// …and nothing else was mangled on the way.
		for (const decoy of DECOYS) {
			expect(card.description).toContain(decoy)
		}
		// Redaction is a VALUE change, so the field list must be untouched.
		expect(Object.keys(card).sort()).toEqual(PUBLIC_CARD_KEYS)
	})

	test('the opted-in comment body is redacted too', async () => {
		expect((await api('PUT', `/boards/${boardId}/public-share/comments`, { enabled: true })).body.commentsEnabled).toBe(true)

		const res = await fetchPublic(token)
		const card = res.body.cards.find((c) => c.title === CARD_TITLE)
		expect(card.comments.length).toBe(1)
		expect(card.comments[0].body).toContain(DISPLAY_NAME)
		expect(card.comments[0].body).not.toContain(mentioned)
		// Widening the link with comments must not widen it to uids: the whole
		// payload — description, comment body and author byline together — is uid-free.
		expect(JSON.stringify(res.body)).not.toContain(mentioned)
	})

	test('the stored row keeps its raw @mention for authenticated viewers', async () => {
		// Redaction is payload-only. If it ever became a write-back, the mention would
		// stop notifying and stop rendering as a chip for members — and no migration
		// may rewrite user content.
		const stored = (await api('GET', `/cards/${cardId}`)).body
		expect(stored.description).toBe(description)
		expect(stored.description).toContain(`@${mentioned}`)
		const comments = (await api('GET', `/cards/${cardId}/comments`)).body
		expect(JSON.stringify(comments)).toContain(`@${mentioned}`)
	})
})

// Public-link EXPIRY (#10466). `public_share_expires_at` was persisted and
// enforced from the start, but nothing could ever set it — the enforcement
// branch was live code that had never executed. Now the owner can set, change
// and clear it, so this is where that branch is actually exercised end to end.
//
// Two things are asserted together, because either one alone is a half-feature:
//  - a link past its expiry REFUSES an anonymous visitor, and
//  - it says WHY. A visitor who cannot tell "expired" from "wrong address" goes
//    back to the owner asking them to re-check a URL that was always correct.
test.describe('Public link expiry', () => {
	// MANDATORY: the assertions below are about what an ANONYMOUS visitor gets.
	// The suite reuses one global admin storageState for speed, and a page that
	// inherits it is not anonymous — an expired-link assertion would then be
	// testing the admin's session, not the share, and could false-pass. The
	// `api()` helper above carries its own Authorization header, so the owner-side
	// calls in this block are unaffected by opting out.
	test.use({ storageState: { cookies: [], origins: [] } })

	// Each case here writes the expiry the next one reads, so the order is load-
	// bearing. Say so, instead of leaning on the default single-worker-per-file
	// behaviour: in serial mode a failure stops the chain rather than cascading
	// into four confusing follow-on failures.
	test.describe.configure({ mode: 'serial' })

	let boardId = 0
	let token = ''

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Public Expiry E2E' })).body.id
		const stackId = (await api('POST', '/stacks', { boardId, title: 'To do' })).body.id
		await api('POST', '/cards', { stackId, title: 'Card behind an expiring link' })
		token = (await api('POST', `/boards/${boardId}/public-share`)).body.token
		expect(token).toBeTruthy()
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	// Prove the page is anonymous, so every refusal assertion below means what it
	// says. Without the test.use() above this is the case that would go red.
	test('the visitor really is anonymous (storageState opt-out is in effect)', async ({ page }) => {
		const state = await page.context().storageState()
		expect(state.cookies).toHaveLength(0)
		// And the board is genuinely reachable for an anonymous reader right now,
		// so a later 404 is the expiry talking and not a broken fixture.
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		await expect(page.locator('.public-board__title')).toHaveText('Public Expiry E2E')
	})

	test('an expiry in the future leaves the link working', async () => {
		const future = Math.floor(Date.now() / 1000) + 3600
		const cfg = (await api('PUT', `/boards/${boardId}/public-share/expiry`, { expiresAt: future })).body
		expect(cfg.expiresAt).toBe(future)
		// The link itself is untouched — an expiry is not a rotate.
		expect(cfg.token).toBe(token)
		expect((await fetchPublic(token)).status).toBe(200)
	})

	test('an expiry in the PAST refuses the anonymous visitor, and says why', async ({ page }) => {
		const past = Math.floor(Date.now() / 1000) - 60
		expect((await api('PUT', `/boards/${boardId}/public-share/expiry`, { expiresAt: past })).body.expiresAt).toBe(past)

		// The payload route: refused, with the same uniform 404 every other
		// rejection gets (deliberately no reason on the machine-readable surface).
		expect((await fetchPublic(token)).status).toBe(404)

		// The PAGE route: refused too, and this one names the cause. The board
		// content must be nowhere on it.
		const response = await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		expect(response.status()).toBe(404)
		await expect(page.locator('body')).toContainText(/expired/i)
		await expect(page.locator('body')).not.toContainText('Card behind an expiring link')
		await expect(page.locator('.public-board__title')).toHaveCount(0)
	})

	test('a wrong token still gets the indistinguishable page, not the expiry one', async ({ page }) => {
		// The expired page must not become an enumeration oracle: a token that
		// never existed has to keep getting the generic message.
		const response = await page.goto(`${BASE}/index.php/apps/kanso/p/a-token-that-never-existed-10466`)
		expect(response.status()).toBe(404)
		await expect(page.locator('body')).not.toContainText(/expired/i)
	})

	test('a malformed expiry is refused, never read as "no expiry"', async () => {
		// The dangerous direction: if a value the server cannot read were treated
		// as a clear, a garbage request would answer 200 and leave a link that was
		// supposed to be closed wide open. Set a real expiry, then try to break it.
		const future = Math.floor(Date.now() / 1000) + 3600
		expect((await api('PUT', `/boards/${boardId}/public-share/expiry`, { expiresAt: future })).body.expiresAt).toBe(future)

		for (const bad of ['garbage', '2031-01-15', 1.5, true]) {
			const res = await api('PUT', `/boards/${boardId}/public-share/expiry`, { expiresAt: bad })
			expect(res.status, `expected 400 for ${JSON.stringify(bad)}`).toBe(400)
		}
		// Untouched.
		expect((await api('GET', `/boards/${boardId}/public-share`)).body.expiresAt).toBe(future)
	})

	test('clearing the expiry reopens the link', async ({ page }) => {
		const cfg = (await api('PUT', `/boards/${boardId}/public-share/expiry`, { expiresAt: null })).body
		expect(cfg.expiresAt).toBeFalsy()
		expect((await fetchPublic(token)).status).toBe(200)
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		await expect(page.locator('.public-board__title')).toHaveText('Public Expiry E2E')
	})

	test('setting an expiry needs MANAGE, like every other public-link operation', async () => {
		const outsider = `kanso-expiry-outsider-${Date.now()}`
		await provisionUser(outsider, 'Outsider-pw-10466!')
		try {
			const res = await fetch(`${API}/boards/${boardId}/public-share/expiry`, {
				method: 'PUT',
				headers: {
					...HEADERS,
					Authorization: 'Basic ' + Buffer.from(`${outsider}:Outsider-pw-10466!`).toString('base64'),
				},
				body: JSON.stringify({ expiresAt: Math.floor(Date.now() / 1000) - 60 }),
			})
			expect([403, 404]).toContain(res.status)
			// And the link is still live — the denied write changed nothing.
			expect((await fetchPublic(token)).status).toBe(200)
		} finally {
			await deleteUser(outsider)
		}
	})
})

// Tile description excerpts are PLAIN TEXT (#10605). The tile used to interpolate
// the raw markdown SOURCE, so every construct leaked as syntax — and an embedded
// image was the worst case: its inline-attachment URL is longer than the whole
// 240-char budget, so a card with a screenshot showed a wall of path and none of
// its prose. The excerpt is now flattened through flattenMarkdown() (the same
// markdown-it instance the detail view renders with), images dropped entirely,
// and only THEN truncated — truncating first would let a stripped-away URL keep
// eating the budget.
//
// Dropping the image left one hole: a description that is ONLY a picture flattens
// to '', so the tile rendered title-and-meta and read as a card with no
// description at all. Those tiles now carry an "Image" marker in the meta row —
// a marker, never a thumbnail: tile height has to stay predictable while scanning
// a column, and every drawn image would be another anonymous request through the
// token-gated attachment route.
test.describe('Public board tiles excerpt the description as plain text', () => {
	// A true anonymous reader. Without this opt-out the page loads under the
	// shared admin storageState and these assertions pass for the wrong reason.
	test.use({ storageState: { cookies: [], origins: [] } })

	const IMAGE_TITLE = 'Card with a screenshot in the middle'
	const IMAGE_ONLY_TITLE = 'Card that is nothing but a screenshot'
	const MARKUP_TITLE = 'Card with mixed markdown'
	const LONG_TITLE = 'Card with a screenshot and long prose'
	const FENCE_TITLE = 'Card with image syntax inside a code fence'
	const REF_IMAGE_TITLE = 'Card that is only a reference-style picture'
	const NO_DESC_TITLE = 'Card with no description whatsoever'

	// 260 chars of prose after the image, so the excerpt must truncate — and can
	// only do so at 240 chars of PROSE if the image was stripped first.
	const LONG_PROSE = 'PROSE_HEAD_10605 ' + 'the deploy notes go on and on. '.repeat(9)

	let boardId = 0
	let token = ''
	let imageMarkdown = ''
	let imageCardId = 0
	// The same image markdown AS AN ANONYMOUS VISITOR RECEIVES IT (#152). The
	// public payload re-points every inline src at the token-gated route
	// (PublicShareService::rewriteInlineImages), so the source string that
	// actually reaches the tile is this one, not `imageMarkdown`. Anything
	// asserting on the raw source a public visitor sees must use this.
	let publicImageMarkdown = ''

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Public Excerpt E2E' })).body.id
		const stackId = (await api('POST', '/stacks', { boardId, title: 'To do' })).body.id

		const imageCard = (await api('POST', '/cards', { stackId, title: IMAGE_TITLE })).body.id
		imageCardId = imageCard
		// The exact shape a pasted image gets (cardAttachmentInlineUrl); the file
		// itself need not exist — the tile must never render or fetch it.
		imageMarkdown = `![image.png](/apps/kanso/api/cards/${imageCard}/attachments/42/inline)`
		expect((await api('PATCH', `/cards/${imageCard}`, {
			description: `Before the shot.\n\n${imageMarkdown}\n\nAfter the shot.`,
		})).status).toBe(200)

		const imageOnly = (await api('POST', '/cards', { stackId, title: IMAGE_ONLY_TITLE })).body.id
		expect((await api('PATCH', `/cards/${imageOnly}`, { description: imageMarkdown })).status).toBe(200)

		const markupCard = (await api('POST', '/cards', { stackId, title: MARKUP_TITLE })).body.id
		expect((await api('PATCH', `/cards/${markupCard}`, {
			description: '# HEADING_10605\n\n**BOLD_10605** and [LINK_LABEL_10605](https://example.invalid/a/very/long/url/nobody/wants/to/read)\n\n- ITEM_10605\n\n`CODE_10605`',
		})).status).toBe(200)

		const longCard = (await api('POST', '/cards', { stackId, title: LONG_TITLE })).body.id
		expect((await api('PATCH', `/cards/${longCard}`, {
			description: `${imageMarkdown}\n\n${LONG_PROSE}`,
		})).status).toBe(200)

		// Image SYNTAX inside a fence is code, not an image: markdown-it emits no
		// image token for it, so it must not claim the tile holds a picture. This
		// is the case a `![` regex gets wrong.
		const fenceCard = (await api('POST', '/cards', { stackId, title: FENCE_TITLE })).body.id
		expect((await api('PATCH', `/cards/${fenceCard}`, {
			description: '```\n' + imageMarkdown + '\n```',
		})).status).toBe(200)

		// A reference-style image IS a real image — and the case a
		// `!\[..\]\(..\)` regex misses, drift in the other direction. It flattens
		// to '' like any other image, so it must get the marker.
		const refCard = (await api('POST', '/cards', { stackId, title: REF_IMAGE_TITLE })).body.id
		expect((await api('PATCH', `/cards/${refCard}`, {
			description: `![shot][shot-ref]\n\n[shot-ref]: /apps/kanso/api/cards/${refCard}/attachments/42/inline`,
		})).status).toBe(200)

		// No description at all — the tile an image-only card must stay
		// distinguishable from, and which must gain nothing from this change.
		await api('POST', '/cards', { stackId, title: NO_DESC_TITLE })

		token = (await api('POST', `/boards/${boardId}/public-share`)).body.token
		expect(token).toBeTruthy()
		publicImageMarkdown = `![image.png](/apps/kanso/api/public/${token}/cards/${imageCardId}/attachments/42/inline)`
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('an image is dropped and the surrounding prose survives', async ({ page }) => {
		// The payload still ships the raw markdown (deliberately out of scope here):
		// the stripping is a rendering contract, so pin that the source really does
		// reach the browser, or the tile assertion could pass vacuously on a server
		// that had already stripped it. The src is the token-gated one (#152) —
		// still unstripped image SYNTAX, which is all this guard is about.
		const payload = await fetchPublic(token)
		expect(payload.status).toBe(200)
		const raw = payload.body.cards.find((c) => c.title === IMAGE_TITLE).description
		expect(raw).toContain(publicImageMarkdown)

		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		await expect(page.locator('.public-board__title')).toHaveText('Public Excerpt E2E')

		const desc = page.locator('.public-card').filter({ hasText: IMAGE_TITLE }).locator('.public-card__desc')
		await expect(desc).toHaveText('Before the shot. After the shot.')
		// No markdown syntax, no URL, and no empty brackets left behind.
		await expect(desc).not.toContainText('![')
		await expect(desc).not.toContainText('attachments')
		await expect(desc).not.toContainText('image.png')
		await expect(desc).not.toContainText('()')
		// …and the tile draws no image either — a tile is text.
		const tile = page.locator('.public-card').filter({ hasText: IMAGE_TITLE })
		await expect(tile.locator('img')).toHaveCount(0)
		// The marker is for tiles that would otherwise read as empty. This one has
		// its prose, so it says nothing about the picture — intentionally.
		await expect(tile.locator('.public-card__image')).toHaveCount(0)
	})

	test('a description that is only an image is marked, not left looking empty', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		const tile = page.locator('.public-card').filter({ hasText: IMAGE_ONLY_TITLE })
		await expect(tile).toBeVisible()
		// Absent, not an empty paragraph with stray punctuation in it.
		await expect(tile.locator('.public-card__desc')).toHaveCount(0)
		// Instead the meta row — the same row that carries Urgent / due / checklist
		// — says there is a picture in here, without drawing it.
		await expect(tile.locator('.public-card__meta .public-card__image')).toHaveText('Image')
		await expect(tile.locator('img')).toHaveCount(0)
	})

	test('a reference-style image is a real image and is marked too', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		const tile = page.locator('.public-card').filter({ hasText: REF_IMAGE_TITLE })
		await expect(tile).toBeVisible()
		await expect(tile.locator('.public-card__desc')).toHaveCount(0)
		// Only a parser knows this is an image; the marker comes from the same
		// token walk that drops it, so the two can never disagree.
		await expect(tile.locator('.public-card__image')).toHaveText('Image')
	})

	test('image syntax inside a code fence is code, and claims no picture', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		const tile = page.locator('.public-card').filter({ hasText: FENCE_TITLE })
		await expect(tile).toBeVisible()
		// The fence is the card's text, so it excerpts as code… (the src inside it
		// is the token-gated rewrite, #152 — rewriteInlineImages runs over the
		// whole description string, fenced code included.)
		await expect(tile.locator('.public-card__desc')).toHaveText(publicImageMarkdown)
		// …and there is no image anywhere in this card. A `![` regex would have
		// counted one.
		await expect(tile.locator('.public-card__image')).toHaveCount(0)
	})

	test('a card with no description at all is unchanged: no excerpt, no marker', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		const tile = page.locator('.public-card').filter({ hasText: NO_DESC_TITLE })
		await expect(tile).toBeVisible()
		await expect(tile.locator('.public-card__desc')).toHaveCount(0)
		await expect(tile.locator('.public-card__image')).toHaveCount(0)
	})

	test('bold, links, headings, lists and code read as text, not source', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		const desc = page.locator('.public-card').filter({ hasText: MARKUP_TITLE }).locator('.public-card__desc')
		await expect(desc).toHaveText('HEADING_10605 BOLD_10605 and LINK_LABEL_10605 ITEM_10605 CODE_10605')
		// The source characters themselves are gone, the link target included.
		await expect(desc).not.toContainText('**')
		await expect(desc).not.toContainText('#')
		await expect(desc).not.toContainText('`')
		await expect(desc).not.toContainText('example.invalid')
		// Plain text, not rendered HTML: the tile is interpolation, never v-html.
		await expect(desc.locator('strong, a, h1, li, code')).toHaveCount(0)
	})

	test('truncation applies to the stripped text, not the raw source', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso/p/${token}`)
		const desc = page.locator('.public-card').filter({ hasText: LONG_TITLE }).locator('.public-card__desc')
		const text = await desc.textContent()
		// 240 chars of prose + the ellipsis. Truncating the RAW source instead would
		// have spent ~60 of those characters on the image URL, so this length only
		// comes out right when the strip runs first.
		expect(text).toBe(LONG_PROSE.slice(0, 240) + '…')
		expect(text.startsWith('PROSE_HEAD_10605')).toBe(true)
		expect(text).not.toContain('attachments')
	})
})

// Images embedded in a shared card (#152 / GitHub #152). An image pasted into a
// description is stored as the AUTHENTICATED inline-attachment path, which needs
// a session — so a public-share visitor saw a broken-image box on a board that
// had been deliberately shared. The payload now re-points those srcs at a
// token-gated route, and the route re-checks the token itself.
test.describe('Public board serves the images embedded in a shared card', () => {
	// True anonymous reader. Without this opt-out every assertion below runs
	// under the shared admin session, where the ORIGINAL authenticated src would
	// have loaded fine and the whole describe would false-pass.
	test.use({ storageState: { cookies: [], origins: [] } })

	// A real 48×32 greyscale PNG. Real dimensions, not a 1×1: the assertions read
	// naturalWidth/naturalHeight, and a 1×1 cannot distinguish "decoded" from
	// "browser's broken-image placeholder".
	const PNG_W = 48
	const PNG_H = 32
	const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAADAAAAAgCAAAAABxv6TAAAAAFUlEQVR4nGNgGAWjYBSMglEwCjABAAYgAAFBC6YRAAAAAElFTkSuQmCC'

	async function uploadPng(cardId, filename) {
		const form = new FormData()
		form.append('file', new Blob([Buffer.from(PNG_B64, 'base64')], { type: 'image/png' }), filename)
		const r = await fetch(`${API}/cards/${cardId}/attachments`, {
			method: 'POST',
			headers: { 'OCS-APIREQUEST': 'true', Authorization: currentAuth },
			body: form,
		})
		if (!r.ok) throw new Error(`upload ${filename} → ${r.status}: ${await r.text()}`)
		return r.json()
	}

	let sharedBoardId = 0
	let otherBoardId = 0
	let sharedCardId = 0
	let otherCardId = 0
	let otherAttachmentId = 0
	let unembeddedAttachmentId = 0
	let hiddenCardId = 0
	let hiddenAttachmentId = 0
	let shareToken = ''

	test.beforeAll(async () => {
		// The SHARED board: one card carrying the same image in its description and
		// in a comment (the comment body is a rendered surface too).
		sharedBoardId = (await api('POST', '/boards', { title: 'Public Share Images E2E' })).body.id
		const stackId = (await api('POST', '/stacks', { boardId: sharedBoardId, title: 'To do' })).body.id
		sharedCardId = (await api('POST', '/cards', { stackId, title: 'Card with a picture' })).body.id
		const attachment = await uploadPng(sharedCardId, 'shared.png')
		const src = `/apps/kanso/api/cards/${sharedCardId}/attachments/${attachment.id}/inline`
		expect((await api('PATCH', `/cards/${sharedCardId}`, {
			description: `Before\n\n![shot](${src})\n\nAfter`,
		})).status).toBe(200)
		expect((await api('POST', `/cards/${sharedCardId}/comments`, {
			body: `and again ![shot](${src})`,
		})).status).toBe(200)
		expect((await api('PUT', `/boards/${sharedBoardId}/public-share/comments`, { enabled: true })).status).toBe(200)
		shareToken = (await api('POST', `/boards/${sharedBoardId}/public-share`)).body.token
		expect(shareToken).toBeTruthy()

		// A SECOND, NEVER-SHARED board with its own image. This is the thing the
		// share token must not be able to reach.
		otherBoardId = (await api('POST', '/boards', { title: 'Private Images E2E' })).body.id
		const otherStackId = (await api('POST', '/stacks', { boardId: otherBoardId, title: 'To do' })).body.id
		otherCardId = (await api('POST', '/cards', { stackId: otherStackId, title: 'Private card' })).body.id
		otherAttachmentId = (await uploadPng(otherCardId, 'private.png')).id

		// A second image UPLOADED to the shared card but never written into any
		// text. Attachments are not part of the public snapshot, so this one was
		// never published even though its card was.
		unembeddedAttachmentId = (await uploadPng(sharedCardId, 'never-embedded.png')).id

		// And a HIDDEN card on the SHARED board, carrying an embedded image. The
		// board is shared; this card is not on it.
		hiddenCardId = (await api('POST', '/cards', { stackId, title: 'Hidden card' })).body.id
		const hiddenAttachment = await uploadPng(hiddenCardId, 'hidden.png')
		hiddenAttachmentId = hiddenAttachment.id
		expect((await api('PATCH', `/cards/${hiddenCardId}`, {
			description: `![hidden](/apps/kanso/api/cards/${hiddenCardId}/attachments/${hiddenAttachment.id}/inline)`,
			visibility: 'private',
		})).status).toBe(200)
	})

	test.afterAll(async () => {
		if (sharedBoardId) await api('DELETE', `/boards/${sharedBoardId}`)
		if (otherBoardId) await api('DELETE', `/boards/${otherBoardId}`)
	})

	test('the anonymous payload re-points the image at the share token, not the authenticated route', async () => {
		const { status, body } = await fetchPublic(shareToken)
		expect(status).toBe(200)
		const card = body.cards.find((c) => c.id === sharedCardId)
		const publicSrc = `/apps/kanso/api/public/${shareToken}/cards/${sharedCardId}/attachments/`
		expect(card.description).toContain(publicSrc)
		expect(card.comments[0].body).toContain(publicSrc)
		// The session-only path must be gone: leaving it would ship the bug.
		expect(card.description).not.toContain(`/api/cards/${sharedCardId}/attachments/`)
		expect(card.comments[0].body).not.toContain(`/api/cards/${sharedCardId}/attachments/`)
	})

	test('an anonymous visitor DECODES the description and comment images, not a broken box', async ({ page }) => {
		// Prove the opt-out is in effect before asserting anything that a live
		// admin session would also satisfy.
		expect((await page.context().storageState()).cookies).toHaveLength(0)

		const responses = []
		page.on('response', (r) => {
			if (r.url().includes('/attachments/')) responses.push(r.status())
		})

		await page.goto(`${BASE}/index.php/apps/kanso/p/${shareToken}`)
		await page.locator('.public-card').first().click()
		await expect(page.locator('.public-detail')).toBeVisible()

		for (const selector of ['.public-detail__desc img', '.public-comment__body img']) {
			const img = page.locator(selector).first()
			await expect(img).toBeVisible()
			// naturalWidth is the real assertion: an <img> whose request 401s is
			// still "visible" (the browser lays out an alt box), so only the
			// DECODED intrinsic size separates a rendered picture from the bug.
			await expect.poll(
				async () => img.evaluate((el) => el.naturalWidth),
				{ timeout: 10_000, message: `${selector} never decoded — the bytes did not arrive` },
			).toBe(PNG_W)
			expect(await img.evaluate((el) => el.naturalHeight)).toBe(PNG_H)
		}

		// …and every byte came over HTTP 200, never a 401/404.
		expect(responses.length).toBeGreaterThan(0)
		expect(responses.every((s) => s === 200)).toBe(true)
	})

	test('the image bytes come back with an inline, nosniff, image/png response', async () => {
		const card = (await fetchPublic(shareToken)).body.cards.find((c) => c.id === sharedCardId)
		const path = card.description.match(/\/apps\/kanso\/api\/public\/\S+?\/inline/)[0]
		const r = await fetch(`${BASE}/index.php${path}`)
		expect(r.status).toBe(200)
		expect(r.headers.get('content-type')).toBe('image/png')
		expect(r.headers.get('content-disposition')).toBe('inline')
		expect(r.headers.get('x-content-type-options')).toBe('nosniff')
		expect(Buffer.from(await r.arrayBuffer()).length).toBe(Buffer.from(PNG_B64, 'base64').length)
	})

	// THE denial. A token for board A must not reach an attachment on board B —
	// and the card id is attacker-choosable, so this is the check that decides it.
	test('a token for one board cannot fetch an attachment belonging to another board', async () => {
		const r = await fetch(
			`${BASE}/index.php/apps/kanso/api/public/${shareToken}`
			+ `/cards/${otherCardId}/attachments/${otherAttachmentId}/inline`,
		)
		expect(r.status).toBe(404)
		expect(r.headers.get('content-type')).toContain('json')
		// Nothing resembling PNG bytes came back.
		expect(await r.text()).not.toContain('PNG')
	})

	// The card is really shared, the attachment is really embedded on it, and the
	// URL is the one that works — ONLY the token is wrong. Anything less (a guessed
	// attachment id, say) would 404 for a reason that has nothing to do with the
	// token and prove nothing.
	test('a made-up token reaches nothing, even for the exact URL that works', async () => {
		const card = (await fetchPublic(shareToken)).body.cards.find((c) => c.id === sharedCardId)
		const working = card.description.match(/\/apps\/kanso\/api\/public\/\S+?\/inline/)[0]
		expect((await fetch(`${BASE}/index.php${working}`)).status).toBe(200)

		const bogus = working.replace(shareToken, 'z'.repeat(64))
		expect((await fetch(`${BASE}/index.php${bogus}`)).status).toBe(404)
	})

	// The in-board half of the denial: `applyPublicOnly` exists for exactly this,
	// and the cross-board test above does not exercise it.
	test('a hidden card on the SHARED board keeps its image to itself', async () => {
		// It is genuinely absent from the payload — otherwise this asserts nothing.
		const payload = await fetchPublic(shareToken)
		expect(payload.body.cards.some((c) => c.id === hiddenCardId)).toBe(false)

		const r = await fetch(
			`${BASE}/index.php/apps/kanso/api/public/${shareToken}`
			+ `/cards/${hiddenCardId}/attachments/${hiddenAttachmentId}/inline`,
		)
		expect(r.status).toBe(404)
	})

	// Attachments are not part of the public snapshot. Only an image the author
	// EMBEDDED in text the visitor can read is published; one merely uploaded to
	// the same card is not, or the route would hand out every raster attachment of
	// every public card to anyone counting ids up from 1.
	test('an attachment uploaded to the shared card but never embedded is refused', async () => {
		const r = await fetch(
			`${BASE}/index.php/apps/kanso/api/public/${shareToken}`
			+ `/cards/${sharedCardId}/attachments/${unembeddedAttachmentId}/inline`,
		)
		expect(r.status).toBe(404)

		// …while the embedded one on that very same card still serves, so the
		// refusal above is about the attachment, not about the card.
		const card = (await fetchPublic(shareToken)).body.cards.find((c) => c.id === sharedCardId)
		const embedded = card.description.match(/\/apps\/kanso\/api\/public\/\S+?\/inline/)[0]
		expect((await fetch(`${BASE}/index.php${embedded}`)).status).toBe(200)
	})
})
