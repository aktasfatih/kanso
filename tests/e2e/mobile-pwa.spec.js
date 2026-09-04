// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Mobile / PWA smoke test.
 *
 * Runs the board on a phone viewport and asserts the two things the PWA work
 * added on top of the normal board flow:
 *   1. The board is usable at 390px — columns render and a card opens.
 *   2. The app is installable + offline-capable — the manifest link is present
 *      and fetches as application/manifest+json, and the service worker is
 *      served with the right scope header and registers.
 *
 * Runs under the mobile-chromium (Pixel 7) and mobile-webkit (iPhone 14)
 * projects (see playwright.config.js), so each test executes on both the
 * Android-Chrome and iOS-Safari engines with a real phone viewport + touch.
 */
import { test, expect, api, gotoBoard, BASE, me } from './helpers.js'

const state = { boardId: null }

// The offline snapshot lives in IndexedDB `kanso-offline` / store `kv` / key
// `queryClient` (src/services/offlineCache.js). These run inside the page.
const IDB = { db: 'kanso-offline', store: 'kv', key: 'queryClient' }

function readSnapshot(page) {
	return page.evaluate(({ db, store, key }) => new Promise((resolve) => {
		const open = indexedDB.open(db, 1)
		open.onupgradeneeded = () => { try { open.result.createObjectStore(store) } catch {} }
		open.onerror = () => resolve(null)
		open.onsuccess = () => {
			const conn = open.result
			try {
				const req = conn.transaction(store, 'readonly').objectStore(store).get(key)
				req.onsuccess = () => { conn.close(); resolve(req.result ?? null) }
				req.onerror = () => { conn.close(); resolve(null) }
			} catch { conn.close(); resolve(null) }
		}
	}), IDB)
}

/** Rewrite the persisted snapshot's owner uid — i.e. "this profile's snapshot
 * was written by somebody else", the shared-browser case. */
function reownSnapshot(page, uid) {
	return page.evaluate(({ db, store, key, uid }) => new Promise((resolve, reject) => {
		const open = indexedDB.open(db, 1)
		open.onerror = () => reject(new Error('idb open failed'))
		open.onsuccess = () => {
			const conn = open.result
			const get = conn.transaction(store, 'readonly').objectStore(store).get(key)
			get.onsuccess = () => {
				const saved = get.result
				if (!saved) { conn.close(); reject(new Error('no snapshot to re-own')); return }
				saved.uid = uid
				const tx = conn.transaction(store, 'readwrite')
				tx.objectStore(store).put(saved, key)
				tx.oncomplete = () => { conn.close(); resolve(true) }
				tx.onerror = () => { conn.close(); reject(new Error('idb write failed')) }
			}
			get.onerror = () => { conn.close(); reject(new Error('idb read failed')) }
		}
	}), { ...IDB, uid })
}

test.beforeAll(async () => {
	const board = await api.post('/boards', { title: 'Mobile PWA E2E' })
	state.boardId = board.id
	const todo = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
	await api.post('/stacks', { boardId: board.id, title: 'Doing' })
	await api.post('/cards', { stackId: todo.id, title: 'Mobile card one' })
	await api.post('/cards', { stackId: todo.id, title: 'Mobile card two' })
})

test.afterAll(async () => {
	if (state.boardId) {
		await api.delete(`/boards/${state.boardId}`).catch(() => {})
	}
})

test('board is usable on a phone viewport', async ({ page }) => {
	await gotoBoard(page, state.boardId)

	// Columns render and a card is visible at 390px.
	await expect(page.locator('.stack-column').first()).toBeVisible({ timeout: 30_000 })
	await expect(page.getByText('Mobile card one')).toBeVisible()

	// mobile.css sizes columns at 88vw on phones — assert the column is in that
	// band (relative to the device viewport, so it holds on both Pixel 7 and
	// iPhone 14). Proves the mobile layout applied, not the 280–420px desktop
	// sizing.
	const vw = page.viewportSize().width
	const width = await page.locator('.stack-column').first().evaluate((el) => el.getBoundingClientRect().width)
	expect(width).toBeGreaterThan(vw * 0.8)
	expect(width).toBeLessThan(vw * 0.95)

	// Opening a card works (full-screen NcModal on mobile).
	await page.getByText('Mobile card one').click()
	await expect(page.getByRole('dialog')).toBeVisible({ timeout: 15_000 })
})

// #10066 — the command palette used to have exactly one trigger, Ctrl/Cmd+K,
// and the overlay documenting it opens on '?'. Neither key exists on a phone, so
// the whole feature was unreachable on touch. This test lives HERE because only
// tests/e2e/mobile-pwa.spec.js runs at a phone viewport (both mobile projects
// match on this file); tests/e2e/command-palette.spec.js is keyboard-driven and
// runs desktop-only, so it cannot cover reachability.
//
// Driven with tap(), not click(): a real touch sequence is the thing under test.
test('the command palette is reachable by touch alone', async ({ page }) => {
	await gotoBoard(page, state.boardId)
	await expect(page.locator('.stack-column').first()).toBeVisible({ timeout: 30_000 })

	// The trigger lives in the "More" overflow, which is already touch-reachable
	// and costs the (narrow) header no width.
	await page.getByRole('button', { name: 'More board actions' }).tap()
	const paletteItem = page.getByRole('menuitem', { name: 'Open command palette' })
	await expect(paletteItem).toBeVisible({ timeout: 10_000 })
	await paletteItem.tap()

	// The palette itself was already touch-usable (its results carry @click and
	// mobile.css enforces 44px targets) — only the way in was missing.
	const palette = page.locator('.command-palette')
	await expect(palette).toBeVisible({ timeout: 10_000 })
	await expect(palette.locator('.command-palette__input')).toBeVisible()
})

test('has an installable web app manifest', async ({ page }) => {
	await gotoBoard(page, state.boardId)

	// Nextcloud's theming app injects the app-scoped manifest link (Kanso adds
	// only the service worker on top of it). Fetch whatever it points at and
	// assert the installability essentials.
	const manifestLink = page.locator('link[rel="manifest"]').first()
	await expect(manifestLink).toHaveCount(1)
	const href = await manifestLink.getAttribute('href')
	expect(href).toBeTruthy()

	const res = await page.request.get(new URL(href, BASE).toString())
	expect(res.status()).toBe(200)
	const manifest = await res.json()
	expect(manifest.display).toBe('standalone')
	expect(manifest.start_url).toContain('/apps/kanso/')
	expect(Array.isArray(manifest.icons) && manifest.icons.length).toBeTruthy()

	// Every icon must actually resolve to an image. A non-empty icons array with
	// a 404 icon (theming failing to rasterise the app SVG) still leaves the app
	// UNINSTALLABLE — that regression shipped once and passed the old assertion.
	for (const icon of manifest.icons) {
		const iconRes = await page.request.get(new URL(icon.src, BASE).toString())
		expect(iconRes.status(), `manifest icon ${icon.src}`).toBe(200)
		expect(iconRes.headers()['content-type'] || '').toMatch(/image\//)
	}
})

/**
 * Reload the page (up to `attempts` times) until the service worker controls it.
 *
 * The worker registers on `load`, so the FIRST document of a context is never
 * controlled — only a subsequent full load is, or the existing one once
 * activate's clients.claim() reaches it. Both are asynchronous, hence the wait
 * after each reload instead of a single instantaneous read.
 */
async function waitForController(page, attempts = 3) {
	for (let i = 0; i < attempts; i++) {
		await page.reload({ waitUntil: 'load' })
		const got = await page.waitForFunction(
			() => !!navigator.serviceWorker.controller,
			null,
			{ timeout: 10_000 },
		).then(() => true).catch(() => false)
		if (got) return true
	}
	return false
}

test('serves the service worker with app scope and registers it', async ({ page, browserName }) => {
	// The worker must carry Service-Worker-Allowed so its scope can cover the app.
	const res = await page.request.get(`${BASE}/index.php/apps/kanso/sw.js`)
	expect(res.status()).toBe(200)
	expect(res.headers()['content-type']).toContain('javascript')
	expect(res.headers()['service-worker-allowed']).toBe('/apps/kanso/')

	// Capture any CSP / failed-fetch errors from here on — the regression below
	// surfaces as exactly these console messages.
	// Match the CSP-block signature specifically. A bare "Failed to fetch" also
	// fires when a reload aborts an in-flight request (a false positive), so key
	// on the CSP wording that the block actually produces.
	const swErrors = []
	const isCspError = (t) => /Content Security Policy|Refused to connect|violates the following|ERR_FAILED/i.test(t)
	page.on('console', (m) => { const t = m.text(); if (isCspError(t)) swErrors.push(t) })
	page.on('pageerror', (e) => { if (isCspError(e.message)) swErrors.push('pageerror:' + e.message) })

	// The PWA layer is off under automation by default (it destabilises the
	// parallel suite); force it on for THIS test so we still cover real
	// registration + control. Must be set before the app script runs.
	await page.addInitScript(() => { window.__KANSO_FORCE_PWA__ = true })

	// Navigate to the PRETTY app URL (/apps/kanso/), which is what the worker's
	// scope + the manifest start_url use. gotoBoard() uses /index.php/apps/kanso,
	// which is OUTSIDE the worker scope, so it would never be controlled.
	await page.goto(`${BASE}/apps/kanso/`, { waitUntil: 'load' })
	const registered = await page.waitForFunction(async () => {
		if (!('serviceWorker' in navigator)) return false
		const reg = await navigator.serviceWorker.getRegistration()
		return !!reg
	}, null, { timeout: 20_000 }).then(() => true).catch(() => false)
	expect(registered).toBe(true)

	// Registration alone is NOT enough. The CSP bug let the worker register but
	// blocked every fetch() it made (NC's default default-src 'none' with no
	// connect-src), so navigations it controlled failed with ERR_FAILED. Assert
	// the worker actually CONTROLS the page and can fetch through it — the exact
	// regression, encoded.
	//
	// Chromium only: Playwright's WebKit registers + activates the worker but does
	// NOT reliably set navigator.serviceWorker.controller (a known WebKit-in-
	// Playwright limitation), so the control+fetch deep-check can't run there.
	// WebKit still covers the iOS-Safari engine for the layout/manifest/icon/
	// registration assertions above and below.
	if (browserName === 'chromium') {
		// A truthy registration isn't yet ACTIVE — wait for activation, then a
		// fresh in-scope load is controlled by the worker.
		const active = await page.waitForFunction(async () => {
			const reg = await navigator.serviceWorker.getRegistration()
			return !!(reg && reg.active)
		}, null, { timeout: 20_000 }).then(() => true).catch(() => false)
		expect(active).toBe(true)

		// Control arrives ASYNCHRONOUSLY — either the reloaded document is created
		// under the worker, or activate's clients.claim() reaches it a moment
		// later — so sample it with a bounded WAIT rather than once per reload.
		// Reading `controller` the instant `load` fires gives claim() zero grace,
		// and on a loaded runner that is enough to miss it on all three reloads
		// (run 33915911344 failed here twice, then passed unchanged on retry #2).
		// This only removes an artificial deadline; a worker that never controls
		// still fails, 10s later.
		const controlled = await waitForController(page, 3)
		expect(controlled).toBe(true)

		// A fetch routed THROUGH the controlled worker must return an HTTP status,
		// not throw a CSP "Failed to fetch" TypeError.
		const swFetch = await page.evaluate(async () => {
			try { const r = await fetch(location.origin + '/apps/kanso/sw.js'); return String(r.status) } catch (e) { return 'THREW:' + e.message }
		})
		expect(swFetch).toMatch(/^\d+$/)
	}

	// No CSP violation must appear on either engine during the forced-PWA session.
	expect(swErrors, swErrors.join('\n')).toEqual([])
})

// #10146 — board confidentiality on a shared browser profile.
//
// The offline layer is two independent stores, and BOTH used to outlive the
// session that filled them:
//   1. Cache Storage, written by the service worker — origin-scoped, not
//      partitioned by user, never purged on logout.
//   2. IndexedDB `kanso-offline`, written by src/services/offlineCache.js and
//      hydrated BEFORE mount — so the first paint after a different user logged
//      in rendered the PREVIOUS user's boards, while online.
// There is deliberately no logout hook in either test: Nextcloud logout is a
// full server navigation to /logout, so the SPA is torn down with no JS event to
// hang one on. The fix therefore has to hold at RESTORE time, which is what
// these assert.
//
// Both tests force the PWA on (main.js disables it under webdriver).

test('the service worker never caches API responses', async ({ page, browserName }) => {
	// Playwright's WebKit has no service-worker support, so there would be no
	// worker to populate Cache Storage and every assertion here would be vacuous.
	test.skip(browserName !== 'chromium', 'no service-worker support in Playwright WebKit')

	await page.addInitScript(() => { window.__KANSO_FORCE_PWA__ = true })

	// The PRETTY URL is the one inside the worker's scope (/apps/kanso/);
	// /index.php/apps/kanso is outside it and would never be controlled.
	await page.goto(`${BASE}/apps/kanso/`, { waitUntil: 'load' })
	const active = await page.waitForFunction(async () => {
		if (!('serviceWorker' in navigator)) return false
		const reg = await navigator.serviceWorker.getRegistration()
		return !!(reg && reg.active)
	}, null, { timeout: 20_000 }).then(() => true).catch(() => false)
	expect(active).toBe(true)

	// The worker registers on `load`, so the FIRST document is never controlled —
	// only a subsequent full load is. (A hash change is not a load, hence reload.)
	const controlled = await waitForController(page, 3)
	expect(controlled).toBe(true)

	// Open the board on this CONTROLLED document (hash route — same document, so
	// the worker keeps controlling it) and wait for real card content.
	await page.goto(`${BASE}/apps/kanso/#/board/${state.boardId}`)
	await expect(page.getByText('Mobile card one')).toBeVisible({ timeout: 30_000 })

	// Non-vacuity #1 — the board's API GETs really were issued from a controlled
	// page, in scope, so the worker's fetch handler DID see them. Without this the
	// "no API entry" assertion could pass simply by never asking the API.
	const apiRequests = await page.evaluate(() => performance.getEntriesByType('resource')
		.map((e) => e.name)
		.filter((u) => u.includes('/apps/kanso/api/')))
	expect(apiRequests.length, 'no in-scope API request was made').toBeGreaterThan(0)

	// Non-vacuity #2 — the worker still caches in-scope NON-API GETs. Probing with
	// its own script keeps this independent of where the deployment serves the app
	// bundles from (under the dev/CI stack they live outside the worker's scope,
	// at /custom_apps/kanso/js/, so they never reach the cache here).
	await page.evaluate(() => fetch(location.origin + '/apps/kanso/sw.js'))

	const cached = await page.evaluate(async () => {
		const urls = []
		for (const name of await caches.keys()) {
			const cache = await caches.open(name)
			for (const req of await cache.keys()) urls.push(req.url)
		}
		return urls
	})
	expect(cached.filter((u) => /\/apps\/kanso\/sw\.js/.test(u)).length,
		`the worker cached nothing at all:\n${cached.join('\n')}`).toBeGreaterThan(0)

	// …and yet nothing under /api ever lands in Cache Storage.
	const apiEntries = cached.filter((u) => /\/apps\/kanso\/api\//.test(u))
	expect(apiEntries, `API responses must never be cached:\n${apiEntries.join('\n')}`).toEqual([])
})

test('the persisted offline cache is not restored for a different user', async ({ page }) => {
	await page.addInitScript(() => { window.__KANSO_FORCE_PWA__ = true })
	await gotoBoard(page, state.boardId)
	await expect(page.getByText('Mobile card one')).toBeVisible({ timeout: 30_000 })

	// Wait out the 1s persist debounce and confirm a snapshot exists, stamped with
	// the current user. This is the setup AND the non-vacuity check: there really
	// is board data on this device for the next person to find.
	let snapshot = null
	await expect.poll(async () => {
		snapshot = await readSnapshot(page)
		return snapshot?.uid ?? null
	}, { timeout: 20_000, message: 'no uid-stamped offline snapshot was persisted' }).toBe(me)
	expect(JSON.stringify(snapshot.state)).toContain('Mobile card one')

	// Cut the API off for the rest of the test, so whatever renders can ONLY have
	// come out of the persisted snapshot — no network fallback to mask a leak.
	await page.route('**/apps/kanso/api/**', (route) => route.abort())

	// Control: for the SAME user the offline boot still works. (The fix must not
	// be "never restore anything" — genuine offline use is the point of the PWA.)
	await gotoBoard(page, state.boardId)
	await expect(page.getByText('Mobile card one')).toBeVisible({ timeout: 30_000 })

	// Now the real case: the snapshot on this profile belongs to somebody else,
	// exactly as it would after user A logged out and user B logged in.
	//
	// Park on a same-origin page that does NOT run Kanso first: the app's persist
	// loop is debounced by a second and would otherwise re-stamp the snapshot with
	// the current uid right after we rewrote it, quietly neutering the test.
	await page.goto(`${BASE}/index.php/settings/user`, { waitUntil: 'domcontentloaded' })
	await reownSnapshot(page, `${me}_someone_else`)
	await gotoBoard(page, state.boardId)

	// Nothing of the other user's board may reach the screen…
	await expect(page.getByText('Mobile card one')).toHaveCount(0, { timeout: 20_000 })
	await page.waitForTimeout(3000)
	await expect(page.getByText('Mobile card one')).toHaveCount(0)

	// …and the foreign snapshot is dropped rather than left for the next reload.
	// (This session may write a fresh, EMPTY snapshot of its own afterwards — the
	// persist loop keeps running — so accept "gone" or "replaced", never "kept".)
	await expect.poll(async () => {
		const saved = await readSnapshot(page)
		if (!saved) return 'deleted'
		if (saved.uid !== me) return 'still-foreign'
		return JSON.stringify(saved.state).includes('Mobile card one') ? 'leaked' : 'replaced'
	}, {
		timeout: 20_000,
		message: "a snapshot belonging to another user must be deleted, not kept",
	}).toMatch(/^(deleted|replaced)$/)
})

// #10184 — the description editor opening below the fold on a phone.
//
// Reported as "the edit box disappears / is not editable", which is not what
// happens: the editor mounts, focuses and accepts typing. It simply opens where
// the read-view was — far down the modal's scroll container — and NOTHING scrolls
// it into view. Measured at 390x664 that left the caret 5px above the fold, and
// with the soft keyboard up the editor was off-screen entirely.
//
// Tiptap's autofocus is not a fix: ProseMirror's scrollIntoView only brings the
// caret to the container EDGE, i.e. zero keyboard clearance.
//
// These tests live HERE because only tests/e2e/mobile-pwa.spec.js runs at a phone
// viewport (playwright.config.js matches both mobile projects on this file);
// tests/e2e/description-editor.spec.js is desktop-only and can never see this.
test.describe('the description editor on a phone', () => {
	const BOARD_TITLE = 'Mobile Description E2E'
	// Roughly what a phone keyboard claims. Playwright has no soft keyboard on
	// either engine, so this is a BUDGET the caret must clear, not a real keyboard:
	// the caret has to end up at least this far above the scroller's bottom edge.
	// Measured headroom with the fix in place is ~50px on the iPhone 14 (the tight
	// one — 664px tall against the Pixel 7's 915px) and ~140px on the Pixel 7, so a
	// future change to the editor's min-height or to where the description sits in
	// the modal can turn this red without a real regression. Check the numbers in
	// the failure before assuming the fix broke.
	const KEYBOARD_PX = 260
	const desc = { boardId: null, cardUrl: '' }

	test.beforeAll(async () => {
		for (const b of await api.get('/boards')) {
			if (b.title === BOARD_TITLE) await api.delete(`/boards/${b.id}`).catch(() => {})
		}
		const board = await api.post('/boards', { title: BOARD_TITLE })
		desc.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Card with a description' })
		await api.patch(`/cards/${card.id}`, { description: 'Existing description text for the repro.' })
		desc.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (desc.boardId) await api.delete(`/boards/${desc.boardId}`).catch(() => {})
	})

	const VIEW = '.card-modal__desc-view'
	const EDITOR = '.card-modal__section .kanso-md-editor'

	/**
	 * Measure `anchorSel` against the scroll container that actually clips it,
	 * and report how far that container is scrolled.
	 *
	 * The scroller is resolved by walking UP from the anchor rather than by naming
	 * NcModal's `.modal-container__content`: on a phone that is indeed the
	 * scroller, but at a desktop width `.card-modal__content` takes over
	 * (max-height: 64vh; overflow: auto — the mobile media query resets it), so a
	 * hard-coded class would silently measure the wrong box on one of the two
	 * viewports. Both call sites below pass anchors from the same section, so both
	 * resolve to the same element and their scrollTops are comparable.
	 */
	function probe(page, anchorSel) {
		return page.evaluate((sel) => {
			const anchor = document.querySelector(sel)
			if (!anchor) return null
			let scroller = null
			for (let el = anchor.parentElement; el && !scroller; el = el.parentElement) {
				// `overflow: auto` alone is not enough: on a phone
				// `.card-modal__content` keeps it while the media query drops its
				// max-height, so its box is as tall as its content and it never
				// clips anything. The nearest ancestor that OVERFLOWS is the one
				// that decides what is on screen.
				const oy = getComputedStyle(el).overflowY
				if ((oy === 'auto' || oy === 'scroll') && el.scrollHeight > el.clientHeight + 1) scroller = el
			}
			if (!scroller) return null

			// Tiptap autofocuses with focus('end'), so the caret is on the last line.
			// The selection rect is authoritative where the engine gives a collapsed
			// range a height; otherwise use that last line's box.
			let caretBottom = null
			const selection = window.getSelection()
			if (selection && selection.rangeCount) {
				const r = selection.getRangeAt(0).getBoundingClientRect()
				if (r.height > 0) caretBottom = r.bottom
			}
			if (caretBottom === null) {
				const last = anchor.querySelector('.ProseMirror')?.lastElementChild
				if (last) caretBottom = last.getBoundingClientRect().bottom
			}

			const s = scroller.getBoundingClientRect()
			const a = anchor.getBoundingClientRect()
			return {
				scrollerTop: s.top,
				scrollerBottom: s.bottom,
				scrollTop: scroller.scrollTop,
				editorTop: a.top,
				editorBottom: a.bottom,
				caretBottom,
				focusedInEditor: !!(document.activeElement && anchor.contains(document.activeElement)),
			}
		}, anchorSel)
	}

	/**
	 * Resolve once the scroll container around `anchorSel` has genuinely come to
	 * rest: unchanged for `quietMs` AND across at least `minSamples` animation
	 * frames.
	 *
	 * Both conditions are load-bearing. Sampling from the test side with
	 * expect.poll cannot tell "has come to rest" from "has not started moving
	 * yet" — two round-trips ~100ms apart both read the pre-scroll value and the
	 * poll declares victory, which is exactly how CI measured this editor before
	 * the app had scrolled it (run 33915911344: editorBottom 793.625, i.e. the
	 * scroller still at ProseMirror's own 12px focus nudge). And the frame count
	 * is what stops a main thread blocked for longer than quietMs — Tiptap
	 * construction under a loaded runner does exactly that — from faking
	 * quiescence purely by producing no samples.
	 *
	 * Returns a string so the caller can distinguish "settled" from "gave up",
	 * never a bare false that would read as a normal timeout.
	 */
	function waitForScrollerQuiet(page, anchorSel, quietMs = 600, minSamples = 20) {
		return page.evaluate(({ sel, quiet, frames }) => new Promise((resolve) => {
			const anchor = document.querySelector(sel)
			if (!anchor) { resolve('no-anchor'); return }
			// Same walk-up as probe() below — see its comment for why the scroller
			// is resolved by overflow rather than by class name.
			let scroller = null
			for (let el = anchor.parentElement; el && !scroller; el = el.parentElement) {
				const oy = getComputedStyle(el).overflowY
				if ((oy === 'auto' || oy === 'scroll') && el.scrollHeight > el.clientHeight + 1) scroller = el
			}
			if (!scroller) { resolve('no-scroller'); return }

			const deadline = performance.now() + 15_000
			let last = scroller.scrollTop
			let lastChange = performance.now()
			let still = 0
			const tick = () => {
				const now = performance.now()
				if (scroller.scrollTop !== last) {
					last = scroller.scrollTop
					lastChange = now
					still = 0
				} else {
					still++
				}
				if (still >= frames && now - lastChange >= quiet) { resolve('quiet'); return }
				if (now > deadline) { resolve('timeout'); return }
				requestAnimationFrame(tick)
			}
			requestAnimationFrame(tick)
		}), { sel: anchorSel, quiet: quietMs, frames: minSamples })
	}

	/** Open the card, activate the description, and measure once it settles. */
	async function openEditor(page, activate) {
		await page.goto(desc.cardUrl)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 30_000 })
		const view = page.locator(VIEW)
		await expect(view).toBeVisible({ timeout: 15_000 })

		const before = await probe(page, VIEW)
		expect(before, 'no scroll container found around the description').not.toBeNull()

		await activate(view)

		const editor = page.locator(EDITOR)
		await expect(editor).toBeVisible({ timeout: 15_000 })
		await expect(editor.locator('.ProseMirror')).toBeVisible({ timeout: 10_000 })

		// The scroll lands a couple of frames after the editor mounts (the toolbar
		// paints only once Tiptap is constructed, and it must run AFTER Tiptap's own
		// focus-driven scroll). Wait for the scroller to come to rest rather than
		// sleeping a fixed interval — the self-hosted runner is several times slower
		// than a dev box, and a fixed sleep would go red on timing alone.
		const quiet = await waitForScrollerQuiet(page, EDITOR)
		expect(quiet, 'the modal scroller never settled').toBe('quiet')

		const m = await probe(page, EDITOR)
		expect(m, 'no scroll container found around the editor').not.toBeNull()

		return { ...m, scrollTopBefore: before.scrollTop }
	}

	test('tapping the description scrolls the editor clear of the keyboard', async ({ page }) => {
		const m = await openEditor(page, (view) => view.tap())

		// The report's actual symptom: the editor must be wholly on screen.
		expect(m.editorBottom, 'the editor hangs below the modal scroller')
			.toBeLessThanOrEqual(m.scrollerBottom + 1)
		expect(m.editorTop, 'the editor was scrolled off the top instead')
			.toBeGreaterThanOrEqual(m.scrollerTop - 1)

		// …and the caret clears a phone keyboard, which the container edge alone
		// never did.
		expect(m.caretBottom, 'no caret position could be measured').not.toBeNull()
		expect(m.caretBottom, 'the caret sits inside the keyboard band')
			.toBeLessThanOrEqual(m.scrollerBottom - KEYBOARD_PX)

		// Non-vacuity: reconstruct where the caret would have been had the scroller
		// stayed put (scrolling by Δ moves content up by exactly Δ). Without the
		// fix that is where it sat — under the keyboard. If a future layout makes
		// the editor fit unaided, this fails and the test gets re-derived rather
		// than passing for free.
		const delta = m.scrollTop - m.scrollTopBefore
		expect(m.caretBottom + delta, 'the editor already fitted without scrolling — test is vacuous')
			.toBeGreaterThan(m.scrollerBottom - KEYBOARD_PX)

		// It is a real editing session, not just a well-placed box.
		expect(m.focusedInEditor, 'the editor did not take focus').toBe(true)
	})

	// The phone fix must not disturb the desktop: there the editor already fits,
	// and it must still open, focus and sit fully inside the modal scroller.
	test.describe('at a desktop width', () => {
		test.use({
			viewport: { width: 1440, height: 900 },
			isMobile: false,
			hasTouch: false,
			deviceScaleFactor: 1,
		})

		test('the description editor still opens fully visible and focused', async ({ page }) => {
			const m = await openEditor(page, (view) => view.click())

			expect(m.editorTop, 'desktop editor scrolled off the top')
				.toBeGreaterThanOrEqual(m.scrollerTop - 1)
			expect(m.editorBottom, 'desktop editor hangs below the scroller')
				.toBeLessThanOrEqual(m.scrollerBottom + 1)
			expect(m.focusedInEditor, 'desktop editor did not take focus').toBe(true)
		})
	})
})

// #10183 — review rows on a phone.
//
// `.review-row` is a flex row whose action cluster is `flex-shrink: 0` and holds
// two full-label buttons (~257px together), so at 390px it claimed the whole
// line and the ellipsising title column absorbed the entire shortfall: the card
// title rendered as ONE glyph (clientWidth 13px against scrollWidth 229px). The
// embedded My Work → Reviews tab was worse still — 0px, with the buttons
// overflowing the row — because MyReviewsView's page inset nested inside
// MyWorkView's identical one.
//
// These tests live HERE because only tests/e2e/mobile-pwa.spec.js runs at a
// phone viewport (playwright.config.js matches both mobile projects on this
// file); tests/e2e/my-reviews.spec.js is desktop-only and can never see this.
test.describe('review rows at a phone width', () => {
	const BOARD_TITLE = 'Mobile Reviews E2E'
	const CARD_TITLE = 'Review row title needing the whole row width'
	const rev = { boardId: null, cardId: null }

	test.beforeAll(async () => {
		// Drop anything a previous run left behind, so exactly one row carries
		// this title.
		for (const b of await api.get('/boards')) {
			if (b.title === BOARD_TITLE) await api.delete(`/boards/${b.id}`).catch(() => {})
		}

		const board = await api.post('/boards', { title: BOARD_TITLE })
		rev.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', { stackId: stack.id, title: CARD_TITLE })
		rev.cardId = card.id
		// Requesting a review from yourself yields a pending row — with the two
		// verdict buttons — on both surfaces.
		await api.put(`/cards/${card.id}/reviews/${me}`)
	})

	test.afterAll(async () => {
		if (rev.boardId) await api.delete(`/boards/${rev.boardId}`).catch(() => {})
	})

	/** Geometry + layout mode of our pending review row, measured in the page. */
	async function measureRow(page) {
		const row = page.locator('.review-row', { hasText: CARD_TITLE }).first()
		await expect(row).toBeVisible({ timeout: 30_000 })
		// The verdict buttons are what crushed the title, so a row measured before
		// they render would look fine for the wrong reason.
		await expect(row.getByRole('button', { name: 'Approve' })).toBeVisible({ timeout: 15_000 })

		return row.evaluate((el) => {
			const q = (sel) => el.querySelector(sel)
			const cardTitle = q('.review-row__card-title')
			const boardTitle = q('.review-row__board-title')
			const actions = q('.review-row__actions')
			const content = q('.review-row__content')
			return {
				cardTitle: { client: cardTitle.clientWidth, scroll: cardTitle.scrollWidth },
				boardTitle: { client: boardTitle.clientWidth, scroll: boardTitle.scrollWidth },
				rowRight: el.getBoundingClientRect().right,
				actionsRight: actions.getBoundingClientRect().right,
				actionsTop: actions.getBoundingClientRect().top,
				contentBottom: content.getBoundingClientRect().bottom,
				rowDirection: getComputedStyle(el).flexDirection,
			}
		})
	}

	async function expectReadable(page, where) {
		const m = await measureRow(page)
		const vw = page.viewportSize().width
		// Nothing is clipped: both titles render in full.
		expect(m.cardTitle.scroll, `${where}: card title is clipped`)
			.toBeLessThanOrEqual(m.cardTitle.client + 1)
		expect(m.boardTitle.scroll, `${where}: board title is clipped`)
			.toBeLessThanOrEqual(m.boardTitle.client + 1)
		// …and they got a real column, not a sliver that "fits" trivially.
		expect(m.cardTitle.client, `${where}: title column is too narrow`)
			.toBeGreaterThan(vw * 0.5)
		// The buttons stay inside the row instead of spilling out of it.
		expect(m.actionsRight, `${where}: actions overflow the row`)
			.toBeLessThanOrEqual(m.rowRight + 1)
	}

	test('the review row is readable on the My Reviews page', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso#/reviews`)
		await expectReadable(page, '#/reviews')
	})

	test('the review row is readable on the embedded My Work → Reviews tab', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso#/my-work?tab=reviews`)
		await expect(page.locator('.my-work-view')).toBeVisible({ timeout: 30_000 })
		await expectReadable(page, '#/my-work?tab=reviews')

		// The hub already supplies the page inset, so the embedded list must not
		// add a second one — that double inset cost the row a sixth of the
		// viewport. It shows up as the row sitting further right than the hub's
		// own header.
		const inset = await page.locator('.review-row', { hasText: CARD_TITLE }).first()
			.evaluate((row) => ({
				row: row.getBoundingClientRect().left,
				header: document.querySelector('.my-work-view__title').getBoundingClientRect().left,
			}))
		expect(inset.row - inset.header, 'the embedded review list is inset twice')
			.toBeLessThanOrEqual(1)
	})

	// Stacking the row puts the verdict buttons on their own line, and that line
	// is the element carrying @click.stop. If it were stretched to the full row
	// width, the empty space beside the buttons would silently swallow taps meant
	// to open the card — a new dead zone in place of the old one.
	test('tapping beside the verdict buttons still opens the card', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso#/reviews`)
		const row = page.locator('.review-row', { hasText: CARD_TITLE }).first()
		await expect(row).toBeVisible({ timeout: 30_000 })
		await expect(row.getByRole('button', { name: 'Approve' })).toBeVisible({ timeout: 15_000 })

		const rowBox = await row.boundingBox()
		const actionsBox = await row.locator('.review-row__actions').boundingBox()
		// The cluster must end well short of the row's right edge (the row's own
		// padding is 14px, so this margin only clears a genuinely narrow cluster).
		expect(actionsBox.x + actionsBox.width, 'the action cluster spans the whole row')
			.toBeLessThan(rowBox.x + rowBox.width - 20)

		// Tap that empty space, level with the buttons.
		await page.touchscreen.tap(rowBox.x + rowBox.width - 6, actionsBox.y + actionsBox.height / 2)
		await expect(page).toHaveURL(
			new RegExp(`/board/${rev.boardId}/card/${rev.cardId}`),
			{ timeout: 15_000 },
		)
	})

	// The phone rules must not leak upward: on a desktop width the row stays a
	// single line with the actions BESIDE the titles.
	test.describe('at a desktop width', () => {
		// deviceScaleFactor 1: the phone devices default to 2.6–3×, which would
		// rasterise this 1440×900 page at ~4300px wide for no benefit.
		test.use({
			viewport: { width: 1440, height: 900 },
			isMobile: false,
			hasTouch: false,
			deviceScaleFactor: 1,
		})

		test('the review row keeps its single-line desktop layout', async ({ page }) => {
			await page.goto(`${BASE}/index.php/apps/kanso#/reviews`)
			const m = await measureRow(page)
			expect(m.rowDirection, 'desktop row must not stack').toBe('row')
			expect(m.actionsTop, 'desktop actions must sit beside the titles, not under them')
				.toBeLessThan(m.contentBottom)
			expect(m.actionsRight).toBeLessThanOrEqual(m.rowRight + 1)
		})
	})
})
