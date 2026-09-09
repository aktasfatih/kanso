// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// BASE/API come from helpers.js so this spec honours E2E_BASE_URL like every
// other spec; it used to hardcode http://localhost:8891 and silently ignore it.
import { test, expect, currentAuth, me, BASE, API } from './helpers.js'

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
	'allDay', 'checklist', 'coverColor', 'description', 'duedate', 'estimate',
	'humanId', 'id', 'labels', 'priority', 'stackId', 'startDate', 'status',
	'title', 'type',
].sort()

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
		expect(payloadCard.coverColor).toBe(COVER)
		expect(res.body.board.cardFeatures.checklist).toBe(false)
		expect(res.body.board.cardFeatures.coverColor).toBe(false)
	})
})
