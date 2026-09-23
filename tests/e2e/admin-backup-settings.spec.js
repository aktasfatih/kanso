// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { request as apiRequest } from '@playwright/test'
import { test, expect, ncLogin, authFor, makeApi, adminAuth, toast, ADMIN, TESTER, BASE } from './helpers.js'

// GitHub #161 — where scheduled backups are written, and what the panel is
// allowed to say about it.
//
// A backup written into a user's Files folder adds up to two Files-activity
// entries per board per run (one created, plus one deleted once retention has
// something to prune) to the stream of whoever owns the folder. Nextcloud's own Files hooks write those; no
// app API in NC 32-34 suppresses them for an individual write, and measurement
// confirmed it: OCP\Activity\IManager::setCurrentUserId() does not reach them at
// all (the Activity app reads OCA\Activity\CurrentUser, i.e. the user SESSION),
// and forcing the session user is order-dependent inside a cron process and
// leaks onto later jobs. So for THAT destination the remedy stays the one the
// panel spells out: point the backup account at a dedicated service account and
// the entries land in ITS feed instead of yours.
//
// The panel now also offers a second destination — Kanso's own app data — which
// leaves no activity entries because nothing is written into a user folder at
// all (app data is not a `home::` storage, so FilesHooks resolves an empty
// affected-user list; measured 0 rows across create, overwrite, prune). That is
// a true statement about app data and it is allowed to be made. What must never
// be claimed is that the FILES-FOLDER destination's entries stop being recorded.
// Hence the guard below runs over the Files-mode copy specifically, and the
// app-data hint is asserted separately on what it must admit: the archives leave
// Files entirely, so Kanso is then the only way to get one back.
//
// Because the two destinations say opposite things about activity, exactly one
// of them may be on screen at a time — both at once read as if both stores were
// in use. The separation test below pins that, and the length test pins the
// register: these hints are settings copy, not the README.
test.describe('Kanso admin backup settings', () => {
	// The admin settings page is admin-only, and under E2E_ISOLATE the worker's
	// stored session is a plain per-worker user that would just get a 403 here.
	// Start from a clean context and log in as the real admin (see the e2e
	// storageState guard).
	test.use({ storageState: { cookies: [], origins: [] } })

	// ── How this file stays safe on a SHARED dev stack (card #10707) ──────────
	//
	// Every other spec in this suite acts on a board it made. This one acts on
	// INSTANCE state: the backup config lives in `oc_appconfig`, there is exactly
	// one of it, and the archives it points at are shared by whoever else is
	// using the same stack. E2E_ISOLATE cannot namespace any of that — it gives a
	// worker its own USER, and there is only ever one admin panel. So the
	// isolation is built here instead, and it is deliberately NOT "run this
	// against a throwaway stack":
	//
	//   1. NOTHING IN THIS FILE MIGRATES. The spec drives an instance that is
	//      already up; it never boots a stack, so it cannot move the schema under
	//      another worktree. (Booting a stack from a feature worktree is the
	//      hazard that kept e2e off this panel — a throwaway stack would have
	//      brought that hazard back in a second copy rather than removing it.)
	//   2. THE CONFIG IS SNAPSHOT AND PUT BACK after every test, so an aborted
	//      run cannot leave the instance pointed at a destination, an account or
	//      a retention count nobody chose. That is the only durable state the
	//      panel writes.
	//   3. DESTRUCTION IS SCOPED TO WHAT THIS SPEC MADE. The delete tests run
	//      against the app-data destination only — never a real Files folder —
	//      each one seeds its own throwaway board, and `afterAll` removes exactly
	//      the archives that appeared while the file ran, by name, plus those
	//      boards. An archive that was already there when it started is never
	//      touched — that is the difference between a cleanup and a purge, and on
	//      a backup panel it is the whole difference. Retention is
	//      raised, not lowered, for the same reason: a run PRUNES to that number
	//      per board, and a low one would delete somebody else's copies.
	//
	// What is deliberately NOT put back is the last-run record (`lastRunAt` /
	// `lastRunStatus`): a backup really did run, and rewriting that line would
	// make the panel lie about it. It is a fact about the instance, not a
	// setting somebody chose.
	//
	// The result is a spec that leaves the instance byte-for-byte as it found it,
	// and needs no privileges, ports or database of its own to do it.
	//
	// `api` from helpers.js is the WORKER's user under E2E_ISOLATE, which every
	// endpoint below answers with a 403. These are genuine admin-only operations,
	// so they get an explicitly admin-bound client.
	const admin = makeApi(adminAuth)
	const storedNames = async () => (await admin.get('/admin/backup/files')).files.map((f) => f.name)

	let savedConfig = null
	let archivesOnEntry = new Set()
	const seededBoards = []

	test.beforeAll(async () => {
		savedConfig = await admin.get('/admin/backup')
		archivesOnEntry = new Set(await storedNames())
	})

	test.afterEach(async () => {
		// Whatever a test did to the instance-wide config, and however it ended.
		if (savedConfig) {
			await admin.put('/admin/backup', savedConfig)
		}
	})

	test.afterAll(async () => {
		// Only what this file added. A listing that cannot be read is left alone
		// rather than guessed at — deleting on a guess is the one thing a cleanup
		// for a backup panel must never do.
		let names = []
		try {
			names = await storedNames()
		} catch (e) {
			return
		}
		for (const name of names) {
			if (!archivesOnEntry.has(name)) {
				await admin.raw('DELETE', '/admin/backup/files?name=' + encodeURIComponent(name))
			}
		}
		// ...and the throwaway boards those archives were made from. One of them
		// is deleted inside a test already, which is why this tolerates a 404.
		for (const boardId of seededBoards) {
			await admin.raw('DELETE', `/boards/${boardId}`)
		}
	})

	// Claims the FILES-FOLDER copy may never make, scoped to the ACTIVITY entries
	// rather than to notifications — a suppression verb applied to the activity
	// stream. The legitimate notification wording ("Never", "Only when a backup
	// fails") does not match any of these, and must not be made to.
	const FALSE_SUPPRESSION_CLAIMS = [
		/\b(?:disabl\w*|suppress\w*|silenc\w*|stops?|stopped|prevent\w*|hid\w*|turns? off|switch(?:es)? off)\s+(?:the\s+|these\s+|those\s+|all\s+|any\s+|your\s+|its\s+)*(?:files[\s-])?activity\b/,
		/\b(?:no|zero|without)\s+(?:more\s+|further\s+|new\s+)?(?:files[\s-])?activity\s+(?:entry|entries|rows|records)\b/,
		/\bactivity\s+(?:entry|entries|stream|feed|rows)\b[^.]{0,40}\b(?:are|is|get|gets)\s+(?:not\s+recorded|suppressed|disabled|hidden|skipped|silenced)\b/,
	]

	// Every element that describes the Files-folder destination. The guard runs
	// over these, and over nothing that describes app data — where "no activity
	// entries" is a measured fact rather than a promise Kanso cannot keep.
	const FILES_MODE_COPY = [
		'#kanso-backup-destination-hint-files',
		'#kanso-backup-account-hint',
		'#kanso-backup-notify-hint',
	]

	// A settings page states what a control does; it does not argue. These hints
	// grew to 400-640 characters over three rounds of work, which is how an admin
	// panel turns into documentation nobody reads. The long version lives in the
	// README; this cap is what keeps it from creeping back here.
	const HINT_MAX_CHARS = 360
	const ALL_HINTS = [
		'#kanso-backup-destination-hint-appdata',
		'#kanso-backup-destination-hint-files',
		'#kanso-backup-account-hint',
		'#kanso-backup-notify-hint',
		'#kanso-backup-stored-hint',
		// What deleting a stored backup costs, and what an orphaned row means
		// (#10675) — held to the same cap as everything else on this page.
		'#kanso-backup-delete-hint',
		'#kanso-backup-orphan-hint',
	]

	// Fields and copy that only mean something when the archives go into Files.
	const FILES_ONLY_CONTROLS = [
		'#kanso-backup-files-config',
		'#kanso-backup-account',
		'#kanso-backup-path',
		'#kanso-backup-destination-hint-files',
	]
	// NOT in the list above, deliberately: #kanso-backup-delete-hint-files and
	// its app-data twin follow the SAVED destination the listing reports, not the
	// dropdown — they sit beside a button that acts on what is persisted, right
	// now. Everything else here is forward-looking copy about the next run.

	const gotoPanel = async (page) => {
		await ncLogin(page, ADMIN)
		await page.goto(`${BASE}/settings/admin/kanso`)
		const section = page.locator('#kanso-backup-settings')
		await expect(section).toBeVisible()
		return section
	}

	/**
	 * Puts TWO archives this spec owns into the app-data store, by making two
	 * boards of its own and running a backup from the panel — the same two clicks
	 * an admin does, not an API call behind the panel's back.
	 *
	 * The second archive is the CONTROL row, and it is seeded rather than picked
	 * out of whatever the instance happens to hold: it is what keeps "the row
	 * went" from passing on a table that emptied itself, and "orphaned" from
	 * passing on a badge painted onto every row, so it must exist on a CI
	 * instance that has barely any boards yet (this file sorts first, so it runs
	 * before most specs have made any).
	 *
	 * @param {import('@playwright/test').Page} page the admin's page
	 * @return {Promise<{boardId: number, name: string, neighbour: string}>} seeded state
	 */
	const seedArchive = async (page) => {
		const stamp = Date.now()
		const board = await admin.post('/boards', { title: `Backup e2e ${stamp}` })
		const other = await admin.post('/boards', { title: `Backup e2e control ${stamp}` })
		seededBoards.push(board.id, other.id)

		await gotoPanel(page)
		await page.selectOption('#kanso-backup-destination', 'appdata')
		// Nextcloud's .checkbox rule parks the input off-screen and paints the
		// label, so the label is the only clickable half of the control.
		const enabledBox = page.locator('#kanso-backup-enabled')
		if (!await enabledBox.isChecked()) {
			await page.click('label[for="kanso-backup-enabled"]')
		}
		await expect(enabledBox).toBeChecked()
		// Far above anything a dev box holds. A run prunes each board down to this
		// many archives, so a small number here would delete copies another
		// session left behind — see the isolation note at the top of the file.
		await page.fill('#kanso-backup-retention', '30')

		await Promise.all([
			page.waitForResponse((r) => r.url().includes('/api/admin/backup/run') && r.ok()),
			page.click('#kanso-backup-run'),
		])

		const row = page.locator(`#kanso-backup-file-rows tr[data-name^="kanso-board-${board.id}-"]`)
		const controlRow = page.locator(`#kanso-backup-file-rows tr[data-name^="kanso-board-${other.id}-"]`)
		await expect(row).toHaveCount(1)
		await expect(controlRow).toHaveCount(1)

		return {
			boardId: board.id,
			name: await row.getAttribute('data-name'),
			neighbour: await controlRow.getAttribute('data-name'),
		}
	}

	/**
	 * Clicks a row's Delete and answers the confirm it must raise.
	 *
	 * `window.confirm` blocks the page, and the click that opened it does not
	 * resolve until somebody answers — so the answer is wired up BEFORE the
	 * click rather than awaited after it. Returning the question is what makes
	 * this assert rather than merely cope: a Delete that stopped asking would
	 * leave `asked` null here, which is the whole point of the control.
	 *
	 * @param {import('@playwright/test').Page} page the admin's page
	 * @param {import('@playwright/test').Locator} row the archive's row
	 * @param {'accept'|'dismiss'} answer what to tell the confirm
	 * @return {Promise<string>} the question it asked
	 */
	const clickDelete = async (page, row, answer) => {
		let asked = null
		page.once('dialog', async (dialog) => {
			asked = dialog.message()
			await (answer === 'accept' ? dialog.accept() : dialog.dismiss())
		})
		await row.locator('button.kanso-backup-delete').click()
		expect(asked, 'Delete must ask before it removes anything').not.toBeNull()
		return asked
	}

	test('the backup account field explains how to keep backups out of your own activity feed', async ({ page }) => {
		await gotoPanel(page)

		// The Files-mode fields are only rendered visible in Files mode; switch
		// there so the copy under test is the copy an admin actually reads.
		await page.selectOption('#kanso-backup-destination', 'files')
		await expect(page.locator('#kanso-backup-account')).toBeVisible()

		// 1. The cost is stated plainly, and the escape hatch is right where the
		// setting it depends on is.
		const hint = page.locator('#kanso-backup-account-hint')
		await expect(hint).toBeVisible()
		const hintText = (await hint.innerText()).toLowerCase()
		expect(hintText).toContain('activity')
		expect(hintText).toMatch(/separate account|service account|dedicated account/)
		expect(hintText).toMatch(/instead of yours|out of your own|not yours/)
		// ...including what it costs, so nobody switches accounts and then wonders
		// where their backups went.
		expect(hintText).toMatch(/backups then live|backups live/)

		// 2. And it keeps the run notification apart from the activity entries:
		// silencing the notification does not remove a single row.
		expect(hintText).toContain('notification')
		expect(hintText).toMatch(/does not remove|do not remove|does not stop|leaves? (?:them|these entries)|still recorded/)

		// 3. The notification control is present with its real choices — asserting
		// it here keeps step 4 honest: the text the guard runs over genuinely
		// contains the legitimate notification wording, so an over-broad guard
		// would fail this spec rather than pass it vacuously.
		const notify = page.locator('#kanso-backup-notify')
		await expect(notify).toBeVisible()
		await expect(notify.locator('option')).toHaveCount(3)

		// 4. Nothing about the FILES destination claims its activity entries stop
		// being recorded. They are not Kanso's to suppress — proven unavoidable
		// from inside the app — so any such claim would be a lie to the admin,
		// whether it arrives as copy or as a control.
		for (const selector of FILES_MODE_COPY) {
			const text = (await page.locator(selector).innerText()).toLowerCase()
			expect(text.length, `${selector} must carry real copy for the guard to bite`).toBeGreaterThan(40)
			for (const claim of FALSE_SUPPRESSION_CLAIMS) {
				expect(text, `${selector} must not claim activity entries are suppressed (${claim})`).not.toMatch(claim)
			}
		}
	})

	test('the destination picker offers both stores and is honest about what each costs', async ({ page }) => {
		await gotoPanel(page)

		const destination = page.locator('#kanso-backup-destination')
		await expect(destination).toBeVisible()
		await expect(destination.locator('option')).toHaveCount(2)

		// App data: quiet and quota-free, but the archives leave Files entirely,
		// so the panel has to say how they come back.
		await page.selectOption('#kanso-backup-destination', 'appdata')
		const appdataHint = page.locator('#kanso-backup-destination-hint-appdata')
		await expect(appdataHint).toBeVisible()
		const appdata = (await appdataHint.innerText()).toLowerCase()
		expect(appdata.length, 'the app-data hint must carry real copy').toBeGreaterThan(40)
		expect(appdata).toContain('activity')
		expect(appdata).toMatch(/cannot sync|not browsable|not (?:browsable|syncable)/)
		expect(appdata).toMatch(/only way to get one back|list at the bottom/)

		// Files folder: browsable and off-site-capable, and it says so.
		await page.selectOption('#kanso-backup-destination', 'files')
		const filesHint = page.locator('#kanso-backup-destination-hint-files')
		await expect(filesHint).toBeVisible()
		const files = (await filesHint.innerText()).toLowerCase()
		expect(files.length, 'the Files hint must carry real copy').toBeGreaterThan(40)
		expect(files).toMatch(/external storage|off this server|open and sync|browse/)
		// ...including the price: quota, and entries in that account's activity.
		expect(files).toContain('activity')
	})

	test('only the selected destination is on screen, from the saved value on load', async ({ page }) => {
		await gotoPanel(page)

		const appdataHint = page.locator('#kanso-backup-destination-hint-appdata')
		const filesHint = page.locator('#kanso-backup-destination-hint-files')
		// Shared controls belong to neither destination and must not move when the
		// picker does.
		const shared = [
			// The input itself is parked off-screen by Nextcloud's own .checkbox
			// rule, so the label is what an admin actually sees and clicks.
			'label[for="kanso-backup-enabled"]',
			'#kanso-backup-retention',
			'#kanso-backup-notify',
			'#kanso-backup-stored',
		]

		// App data: every Files-only control is gone — not merely disabled. A
		// field that means nothing in this mode must not sit there looking
		// configurable.
		await page.selectOption('#kanso-backup-destination', 'appdata')
		await expect(appdataHint).toBeVisible()
		await expect(filesHint).toBeHidden()
		for (const selector of FILES_ONLY_CONTROLS) {
			await expect(page.locator(selector), `${selector} must be hidden under app data`).toBeHidden()
		}
		for (const selector of shared) {
			await expect(page.locator(selector), `${selector} is shared and must stay put`).toBeVisible()
		}

		// Files: they come back, and so does the explanation that goes with them.
		await page.selectOption('#kanso-backup-destination', 'files')
		await expect(filesHint).toBeVisible()
		await expect(appdataHint).toBeHidden()
		for (const selector of FILES_ONLY_CONTROLS) {
			await expect(page.locator(selector), `${selector} must be shown under Files`).toBeVisible()
		}
		for (const selector of shared) {
			await expect(page.locator(selector), `${selector} is shared and must stay put`).toBeVisible()
		}

		// A round trip keeps what was typed — hiding a field must not erase it.
		const account = page.locator('#kanso-backup-account')
		const path = page.locator('#kanso-backup-path')
		const savedAccount = await account.inputValue()
		const savedPath = await path.inputValue()
		await account.fill('backup-bot')
		await path.fill('/kanso-archive')
		await page.selectOption('#kanso-backup-destination', 'appdata')
		await page.selectOption('#kanso-backup-destination', 'files')
		await expect(account).toHaveValue('backup-bot')
		await expect(path).toHaveValue('/kanso-archive')
		await account.fill(savedAccount)
		await path.fill(savedPath)

		// And the SAVED value drives the initial state: the server renders the
		// right half hidden, so a reload in app-data mode never flashes the Files
		// fields on its way to hiding them.
		await page.selectOption('#kanso-backup-destination', 'appdata')
		// Wait for the PUT to land: reloading before it does would re-render the
		// OLD destination and test nothing.
		await Promise.all([
			page.waitForResponse((r) => r.url().includes('/api/admin/backup')
				&& r.request().method() === 'PUT' && r.ok()),
			page.click('#kanso-backup-save'),
		])
		await page.reload()
		await expect(page.locator('#kanso-backup-destination')).toHaveValue('appdata')
		await expect(appdataHint).toBeVisible()
		for (const selector of FILES_ONLY_CONTROLS) {
			await expect(page.locator(selector), `${selector} must be hidden on load under app data`).toBeHidden()
		}
		// The template — not the script — is what hid them, so this holds even
		// before admin-backup.js has run.
		const inlineHidden = await page.locator('#kanso-backup-files-config').getAttribute('style')
		expect(inlineHidden).toContain('none')
	})

	test('the hints stay short enough to read', async ({ page }) => {
		await gotoPanel(page)
		// Both destinations, so the copy of each is measured as rendered.
		for (const mode of ['appdata', 'files']) {
			await page.selectOption('#kanso-backup-destination', mode)
			for (const selector of ALL_HINTS) {
				// A hidden element's innerText falls back to raw textContent, so
				// collapse the template's own indentation before measuring.
				const text = (await page.locator(selector).innerText()).replace(/\s+/g, ' ').trim()
				expect(text.length, `${selector} must carry real copy`).toBeGreaterThan(10)
				expect(text.length, `${selector} is back to documentation length (${text.length} chars)`)
					.toBeLessThanOrEqual(HINT_MAX_CHARS)
			}
		}
	})

	test('a listing that could not be fetched says so instead of claiming there are none', async ({ page }) => {
		// With the app-data destination this table is the ONLY view of the stored
		// archives, so an admin who meets "No backups stored yet." after a failed
		// request reads it as "my backups are gone". The two states must differ.
		let failListing = true
		await page.route('**/api/admin/backup/files*', (route) => {
			if (failListing) {
				return route.fulfill({
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify({ message: 'boom' }),
				})
			}
			return route.continue()
		})

		await gotoPanel(page)

		const error = page.locator('#kanso-backup-file-error')
		const empty = page.locator('#kanso-backup-file-empty')
		await expect(error).toBeVisible()
		await expect(error).toContainText(/could not load/i)
		// ...and NOT the empty state, nor a table an admin could read as complete.
		await expect(empty).toBeHidden()
		await expect(page.locator('#kanso-backup-file-list')).toBeHidden()

		// A later good listing clears it — the panel must not stay stuck on an
		// error the server has since recovered from. Saving reloads the list.
		failListing = false
		await Promise.all([
			page.waitForResponse((r) => r.url().includes('/api/admin/backup/files') && r.ok()),
			page.click('#kanso-backup-save'),
		])
		await expect(error).toBeHidden()
		// And exactly one of the two real states is back on screen, whichever it is.
		await expect.poll(async () => {
			const listed = await page.locator('#kanso-backup-file-list').isVisible()
			const none = await empty.isVisible()
			return listed !== none
		}, { message: 'after a good listing the panel shows either the table or the empty hint' }).toBe(true)
	})

	test('a destination the server cannot read says so instead of showing an empty list', async ({ page }) => {
		// The server-side half of the test above, with nothing mocked. The listing
		// endpoint used to swallow an unresolvable destination and answer 200 with
		// an empty list, so a misconfigured Files folder reached the admin as "No
		// backups stored yet." — a failure wearing the costume of an empty folder,
		// on the screen where an admin goes to check their backups exist. Point the
		// config at an account that does not exist and the panel must say the
		// listing failed.
		await gotoPanel(page)

		const error = page.locator('#kanso-backup-file-error')
		const empty = page.locator('#kanso-backup-file-empty')
		const list = page.locator('#kanso-backup-file-list')
		const account = page.locator('#kanso-backup-account')
		const path = page.locator('#kanso-backup-path')

		await page.selectOption('#kanso-backup-destination', 'files')
		const savedAccount = await account.inputValue()
		const savedPath = await path.inputValue()

		try {
			await account.fill('kanso-no-such-backup-account')
			await path.fill('/kanso-backups')
			await Promise.all([
				page.waitForResponse((r) => r.url().includes('/api/admin/backup/files')),
				page.click('#kanso-backup-save'),
			])

			await expect(error).toBeVisible()
			await expect(error).toContainText(/could not load/i)
			// The state that must NOT appear: an admin reading this would conclude
			// their archives are gone.
			await expect(empty).toBeHidden()
			await expect(list).toBeHidden()
		} finally {
			// Put the config back whatever happened, so the rest of the file runs
			// against a destination that resolves.
			await account.fill(savedAccount)
			await path.fill(savedPath)
			await page.selectOption('#kanso-backup-destination', 'appdata')
			await Promise.all([
				page.waitForResponse((r) => r.url().includes('/api/admin/backup/files') && r.ok()),
				page.click('#kanso-backup-save'),
			])
		}

		// And a destination that DOES resolve is a 200 again — including when it
		// holds nothing. "No backups stored yet." is still reachable; it just no
		// longer stands in for a failure.
		await expect(error).toBeHidden()
		await expect.poll(async () => {
			const listed = await list.isVisible()
			const none = await empty.isVisible()
			return listed !== none
		}, { message: 'a healthy destination shows either the table or the empty hint' }).toBe(true)
	})

	test('a backup folder that is not there fails the listing and is never created by looking', async ({ page }) => {
		// The case the round above missed. A nonexistent ACCOUNT and a path that
		// points at a file both failed loudly already; a folder that is simply not
		// there did not — the listing resolved its destination through the same
		// path a backup RUN does, which creates the folder when it is missing. So
		// a typo'd path silently made that directory in the backup account's Files
		// and then reported the empty folder it had just created as "No backups
		// stored yet.", which is precisely the sentence the error state exists to
		// keep off this screen. Reads look; only runs build.
		const absent = 'kanso-e2e-absent-' + Date.now()
		const dav = `${BASE}/remote.php/dav/files/${ADMIN.user}/${absent}`
		// Its own context: Nextcloud hands back a session cookie for a Basic-auth
		// request and prefers it afterwards, so a shared one would test whoever
		// authenticated first.
		const asAdmin = await apiRequest.newContext()
		const exists = async () => {
			const r = await asAdmin.fetch(dav, {
				method: 'PROPFIND',
				headers: { Authorization: authFor(ADMIN.user, ADMIN.pass), Depth: '0' },
			})
			return r.status() !== 404
		}

		await gotoPanel(page)

		const error = page.locator('#kanso-backup-file-error')
		const empty = page.locator('#kanso-backup-file-empty')
		const list = page.locator('#kanso-backup-file-list')
		const account = page.locator('#kanso-backup-account')
		const path = page.locator('#kanso-backup-path')

		await page.selectOption('#kanso-backup-destination', 'files')
		const savedAccount = await account.inputValue()
		const savedPath = await path.inputValue()

		try {
			expect(await exists(), 'the folder must not exist before the test').toBe(false)

			await account.fill(ADMIN.user)
			await path.fill('/' + absent)
			await Promise.all([
				page.waitForResponse((r) => r.url().includes('/api/admin/backup/files')),
				page.click('#kanso-backup-save'),
			])

			// 1. The panel says the listing failed...
			await expect(error).toBeVisible()
			await expect(error).toContainText(/could not load/i)
			// ...and never the sentence that would tell an admin their archives are
			// gone when the truth is the server was pointed at the wrong folder.
			await expect(empty).toBeHidden()
			await expect(list).toBeHidden()

			// 2. And the read left the account's Files exactly as it found them.
			// This is the half that regressed silently: the panel could show the
			// right thing while still having written to storage to get there.
			expect(await exists(), 'reading the backups must not create the folder').toBe(false)

			// A reload reads it again — still no folder, still an error.
			await page.reload()
			await expect(error).toBeVisible()
			expect(await exists(), 'a second read must not create it either').toBe(false)
		} finally {
			await asAdmin.dispose()
			// Put the config back whatever happened, so the rest of the file runs
			// against a destination that resolves.
			await page.reload()
			await page.selectOption('#kanso-backup-destination', 'files')
			await account.fill(savedAccount)
			await path.fill(savedPath)
			await page.selectOption('#kanso-backup-destination', 'appdata')
			await Promise.all([
				page.waitForResponse((r) => r.url().includes('/api/admin/backup/files') && r.ok()),
				page.click('#kanso-backup-save'),
			])
		}
	})

	test('a run into app data lists its backups and the download returns a real zip', async ({ page, request }) => {
		// A board of this spec's own, BEFORE the run. A run archives every live
		// board on the instance (BackupService::run over BoardMapper::findAll),
		// so on an instance that happens to hold none it succeeds having written
		// nothing: the listing is then legitimately empty and no wait can make a
		// row appear. That is reachable on CI — a fresh stack holds no boards and
		// the parallel worker's specs delete and recreate theirs — and it is what
		// made this the one flaky test in the file. Every other test here already
		// seeds through seedArchive() for exactly this reason.
		const board = await admin.post('/boards', { title: `Backup e2e run ${Date.now()}` })
		seededBoards.push(board.id)

		await gotoPanel(page)

		// Switch to app data and take a backup from the panel itself — the whole
		// round trip an admin does, not an API call behind its back.
		await page.selectOption('#kanso-backup-destination', 'appdata')
		// Nextcloud's .checkbox rule parks the input at left:-10000px and paints
		// the label, so the label is the only clickable half of the control.
		const enabledBox = page.locator('#kanso-backup-enabled')
		if (!await enabledBox.isChecked()) {
			await page.click('label[for="kanso-backup-enabled"]')
		}
		await expect(enabledBox).toBeChecked()
		// 30, not 2: a run prunes EVERY board down to this many archives, so a
		// small number deletes copies another session left behind — the isolation
		// note at the top of this file, which seedArchive() already honours.
		await page.fill('#kanso-backup-retention', '30')

		// Wait for the run itself, not just for the DOM to catch up. The click
		// handler only re-reads the listing when the POST resolves ok; on the
		// error path it raises a toast and leaves the table exactly as it was, so
		// polling the table alone turns a failed run into a timeout that says
		// nothing about why.
		await Promise.all([
			page.waitForResponse((r) => r.url().includes('/api/admin/backup/run') && r.ok()),
			page.click('#kanso-backup-run'),
		])

		// The listing is the ONLY view of an app-data backup; THIS spec's row must
		// appear. Named rather than `first()`: the listing is instance-wide and
		// sorted by name, so the top row can belong to another worker's board —
		// and every assertion below (the download, the traversal refusals) would
		// then be made about somebody else's archive.
		const row = page.locator(`#kanso-backup-file-rows tr[data-name^="kanso-board-${board.id}-"]`)
		await expect(row).toHaveCount(1)
		const firstName = await row.getAttribute('data-name')
		expect(firstName).toMatch(/^kanso-board-\d+-\d{8}-\d{6}\.zip$/)

		// The setting round-tripped: a reload still shows app data, from the
		// server-rendered template rather than from the form state.
		await page.reload()
		await expect(page.locator('#kanso-backup-destination')).toHaveValue('appdata')
		await expect(row).toHaveCount(1)

		const href = await row.locator('a.kanso-backup-download').getAttribute('href')
		expect(href).toContain('/api/admin/backup/download')

		// The bytes: a zip an admin can actually open.
		const download = await request.get(BASE + href, {
			headers: { Authorization: authFor(ADMIN.user, ADMIN.pass) },
		})
		expect(download.status()).toBe(200)
		expect(download.headers()['content-type']).toBe('application/zip')
		expect(download.headers()['content-disposition']).toContain('attachment;')
		const body = await download.body()
		expect(body.length).toBeGreaterThan(0)
		// A zip's local file header - the file is an archive, not an error page.
		expect(body.subarray(0, 2).toString('latin1')).toBe('PK')

		// The filename is the only input it takes: anything that is not a name
		// Kanso itself wrote is a 404 - traversal attempts included - and the
		// refusal is the same one a missing backup gets, so the endpoint is not an
		// oracle for what exists. Percent-encoded so each shape reaches the
		// endpoint as a NAME rather than being normalised into the path.
		const base = BASE + '/index.php/apps/kanso/api/admin/backup/download?name='
		for (const hostile of [
			firstName + '.php',
			firstName.replace('.zip', '.txt'),
			'',
			'../../../../etc/passwd',
			'../config/config.php',
			firstName + '/../../config.php',
		]) {
			const refused = await request.get(base + encodeURIComponent(hostile), {
				headers: { Authorization: authFor(ADMIN.user, ADMIN.pass) },
			})
			expect(refused.status(), `must refuse ${hostile}`).toBe(404)
		}

		// A backup is built at SYSTEM scope and holds every private card on the
		// instance, so the route is admin-only and answers nobody else. With app
		// data it is also the ONLY door to those bytes, which is exactly why this
		// denial is asserted rather than assumed.
		//
		// Each identity gets its OWN request context: Nextcloud hands back a
		// session cookie for a Basic-auth request and prefers that session over
		// the header on the next one, so reusing one context would silently test
		// whoever authenticated first.
		const asTester = await apiRequest.newContext()
		try {
			const denied = await asTester.get(BASE + href, {
				headers: { Authorization: authFor(TESTER.user, TESTER.pass) },
			})
			expect(denied.status()).toBe(403)
		} finally {
			await asTester.dispose()
		}

		const anonymous = await apiRequest.newContext()
		try {
			const denied = await anonymous.get(BASE + href)
			expect(denied.status()).toBe(401)
		} finally {
			await anonymous.dispose()
		}
	})

	// ── Removing a stored backup (#10675) ────────────────────────────────────
	//
	// The destructive end of the panel, and the one the rest of it cannot stand
	// in for: a run that misfires can be run again, a listing that lies can be
	// reloaded, but an archive that is deleted is gone — hard-deleted under app
	// data, and under a Files folder parked in an account's trashbin where this
	// panel cannot reach it. So what is asserted here is not that a button
	// exists: it is that the click asks first, that saying no changes nothing,
	// that saying yes removes THAT archive and no other, and — the one an
	// admin's data depends on — that a delete the storage refused arrives as a
	// failure rather than as a row quietly vanishing from the table.

	test('deleting a stored backup asks first, then takes that row and only that row away', async ({ page }) => {
		const { name, neighbour } = await seedArchive(page)
		const rows = page.locator('#kanso-backup-file-rows tr')
		const row = page.locator(`#kanso-backup-file-rows tr[data-name="${name}"]`)
		const control = page.locator(`#kanso-backup-file-rows tr[data-name="${neighbour}"]`)
		await expect(row).toHaveCount(1)
		await expect(control).toHaveCount(1)
		const before = await rows.count()

		// What the admin is told before clicking, and it follows the SAVED
		// destination rather than the dropdown: this button acts now, on what is
		// persisted, so being promised a trashbin by an unsaved selection while
		// app data hard-deletes is exactly the wrong way round.
		const appdataCost = page.locator('#kanso-backup-delete-hint-appdata')
		const filesCost = page.locator('#kanso-backup-delete-hint-files')
		await expect(appdataCost).toBeVisible()
		await expect(filesCost).toBeHidden()
		await page.selectOption('#kanso-backup-destination', 'files')
		await expect(appdataCost, 'the delete cost must not follow the unsaved dropdown').toBeVisible()
		await expect(filesCost).toBeHidden()

		// ...and neither does the QUESTION in front of the button. Asked while the
		// dropdown says Files but app data is what is SAVED, the confirm must still
		// promise a hard delete: an admin told the archive lands in a trashbin,
		// moments before it is destroyed outright, has been told the one thing that
		// makes this click unrecoverable.
		const underUnsavedFiles = await clickDelete(page, row, 'dismiss')
		expect(underUnsavedFiles, 'the confirm must follow the SAVED destination').toMatch(/for good/i)
		expect(underUnsavedFiles, 'app data has no trashbin to offer').not.toMatch(/trashbin/i)
		expect(await storedNames(), 'a dismissed confirm must not delete anything').toContain(name)

		await page.selectOption('#kanso-backup-destination', 'appdata')

		// 1. It asks — and the question names the file and says what it costs,
		// because there is nowhere to undo this from.
		const question = await clickDelete(page, row, 'dismiss')
		expect(question).toContain(name)
		expect(question).toMatch(/for good/i)
		expect(question, 'app data is a hard delete, so nothing may hint otherwise').not.toMatch(/trashbin/i)

		// Saying no leaves the archive exactly where it was — in the table and in
		// storage both.
		await expect(row).toHaveCount(1)
		expect(await storedNames(), 'a dismissed confirm must not delete anything').toContain(name)

		// 2. Saying yes removes it, and says so.
		await clickDelete(page, row, 'accept')

		// A plain toast dismisses itself at TOAST_DEFAULT_TIMEOUT = 7s, so a 15s wait
		// short-budget-ok: would outlive the toast and report the wrong failure
		await expect(toast(page, 'Backup deleted')).toBeVisible({ timeout: 6_000 })
		await expect(row).toHaveCount(0)

		// ...and ONLY it. A neighbouring archive is still listed and exactly one
		// row left the table, so this cannot pass on a listing that emptied
		// itself or on a delete that took the board's whole history.
		await expect(control).toHaveCount(1)
		await expect(rows).toHaveCount(before - 1)

		// 3. And it is gone from STORAGE, not merely from the DOM — which is why
		// the panel re-reads the server after a delete instead of splicing the
		// row out locally.
		expect(await storedNames()).not.toContain(name)
		await page.reload()
		await expect(page.locator(`#kanso-backup-file-rows tr[data-name="${name}"]`)).toHaveCount(0)
		await expect(page.locator(`#kanso-backup-file-rows tr[data-name="${neighbour}"]`)).toHaveCount(1)
	})

	test('a delete the server refuses is reported as a failure, never as a silent success', async ({ page }) => {
		const { name } = await seedArchive(page)
		const row = page.locator(`#kanso-backup-file-rows tr[data-name="${name}"]`)
		await expect(row).toHaveCount(1)

		// A destination that cannot be written to — a dead mount, a folder that is
		// gone, an account that was removed. The endpoint answers 5xx rather than
		// claiming the archive was removed, and the panel has to carry that all
		// the way to the admin: reported as a success, this is the failure that
		// sends someone away believing an archive is gone while it sits there
		// intact, or believing it is deleted while it still holds every private
		// card on the instance.
		await page.route('**/api/admin/backup/files*', (route) => {
			if (route.request().method() !== 'DELETE') {
				return route.continue()
			}
			return route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ message: 'the destination could not be read' }),
			})
		})

		await clickDelete(page, row, 'accept')

		// A plain toast dismisses itself at TOAST_DEFAULT_TIMEOUT = 7s, so a 15s wait
		// short-budget-ok: would outlive the toast and report the wrong failure
		await expect(toast(page, /could not delete the backup/i)).toBeVisible({ timeout: 6_000 })
		// And never the other message. Asserted only once the error is on screen,
		// so it cannot pass by being checked before either toast had rendered.
		await expect(toast(page, 'Backup deleted')).toHaveCount(0)

		// The row is still there because the SERVER still has the file: the panel
		// reloads the listing after a failure rather than assuming an outcome —
		// a delete can fail after the unlink as easily as before it.
		await expect(row).toHaveCount(1)
		expect(await storedNames()).toContain(name)
	})

	test('an archive whose board is gone is badged orphaned, and a live board\'s is not', async ({ page }) => {
		const { boardId, name, neighbour } = await seedArchive(page)
		const row = page.locator(`#kanso-backup-file-rows tr[data-name="${name}"]`)
		const control = page.locator(`#kanso-backup-file-rows tr[data-name="${neighbour}"]`)

		// Both boards are alive, so neither row carries the marker yet.
		await expect(row).not.toHaveAttribute('data-orphaned', '1')
		await expect(row.locator('.kanso-backup-orphan')).toHaveCount(0)
		await expect(control.locator('.kanso-backup-orphan')).toHaveCount(0)

		// The board goes. listBackups() reads the LIVE board set, which excludes a
		// soft-deleted board, so its archives are orphaned at once — and these are
		// exactly the rows retention will never prune again, because retention
		// only runs for boards that still exist.
		await admin.delete(`/boards/${boardId}`)
		await page.reload()

		await expect(row).toHaveAttribute('data-orphaned', '1')
		const badge = row.locator('.kanso-backup-orphan')
		await expect(badge).toBeVisible()
		await expect(badge).toHaveText(/\(orphaned\)/)
		await expect(badge).toHaveAttribute('title', /no longer exists/i)

		// And the badge is a statement about THIS row rather than decoration on
		// every row: an archive whose board survived carries none.
		await expect(control).toHaveCount(1)
		await expect(control).not.toHaveAttribute('data-orphaned', '1')
		await expect(control.locator('.kanso-backup-orphan')).toHaveCount(0)
	})

	test('a non-admin reaches neither the backup panel nor the endpoints behind it', async ({ page, browser }) => {
		// Seeded as the admin first so the refusals below are refusals of a REAL
		// archive. A 403 on a name that does not exist would prove something about
		// the filename allow-list and nothing at all about the gate.
		const { name } = await seedArchive(page)

		// Its own context: this page holds a live admin session, and ncLogin()
		// returns straight away when it finds one, so reusing it would test the
		// admin a second time.
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const asTester = await context.newPage()
			await ncLogin(asTester, TESTER)
			const refusal = await asTester.goto(`${BASE}/settings/admin/kanso`)
			expect(refusal.status()).toBe(403)

			// Not one part of the panel is rendered — not hidden, not disabled,
			// not present-but-inert. A read-only control still carries the copy
			// that names the backup account and the folder it writes to.
			for (const selector of [
				'#kanso-backup-settings',
				'#kanso-backup-destination',
				'#kanso-backup-account',
				'#kanso-backup-run',
				'#kanso-backup-file-rows',
			]) {
				await expect(asTester.locator(selector), `${selector} must not reach a non-admin`).toHaveCount(0)
			}
		} finally {
			await context.close()
		}

		// The page gate above is Nextcloud's, from the <admin> settings
		// registration. These are Kanso's own, and they are the ones that matter:
		// an archive is built at SYSTEM scope and holds every private card on the
		// instance, so a stray #[NoAdminRequired] on any of them would hand the
		// lot to whoever asked, panel or no panel.
		const tester = makeApi(authFor(TESTER.user, TESTER.pass))
		const probes = [
			['GET', '/admin/backup', undefined],
			// The saved config as the body, so that even if this one ever DID go
			// through it could not rewrite the instance's destination.
			['PUT', '/admin/backup', savedConfig],
			['GET', '/admin/backup/files', undefined],
			['POST', '/admin/backup/run', undefined],
			['DELETE', '/admin/backup/files?name=' + encodeURIComponent(name), undefined],
		]
		for (const [method, path, body] of probes) {
			const denied = await tester.raw(method, path, body)
			expect(denied.status, `${method} ${path} must refuse a non-admin`).toBe(403)
		}

		const anonymous = await apiRequest.newContext()
		try {
			const denied = await anonymous.get(`${BASE}/index.php/apps/kanso/api/admin/backup/files`, {
				headers: { 'OCS-APIRequest': 'true' },
			})
			expect(denied.status()).toBe(401)
		} finally {
			await anonymous.dispose()
		}

		// And nothing any of that tried actually went through.
		expect(await storedNames()).toContain(name)
	})
})
