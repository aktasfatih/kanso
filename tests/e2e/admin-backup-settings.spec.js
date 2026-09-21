// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { request as apiRequest } from '@playwright/test'
import { test, expect, ncLogin, authFor, ADMIN, TESTER, BASE } from './helpers.js'

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
	]

	// Fields and copy that only mean something when the archives go into Files.
	const FILES_ONLY_CONTROLS = [
		'#kanso-backup-files-config',
		'#kanso-backup-account',
		'#kanso-backup-path',
		'#kanso-backup-destination-hint-files',
	]

	const gotoPanel = async (page) => {
		await ncLogin(page, ADMIN)
		await page.goto(`${BASE}/settings/admin/kanso`)
		const section = page.locator('#kanso-backup-settings')
		await expect(section).toBeVisible()
		return section
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

	test('a run into app data lists its backups and the download returns a real zip', async ({ page, request }) => {
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
		await page.fill('#kanso-backup-retention', '2')
		await page.click('#kanso-backup-run')

		// The listing is the ONLY view of an app-data backup; a row must appear.
		const rows = page.locator('#kanso-backup-file-rows tr')
		await expect(rows.first()).toBeVisible({ timeout: 30_000 })
		const firstName = await rows.first().locator('td').first().innerText()
		expect(firstName).toMatch(/^kanso-board-\d+-\d{8}-\d{6}\.zip$/)

		// The setting round-tripped: a reload still shows app data, from the
		// server-rendered template rather than from the form state.
		await page.reload()
		await expect(page.locator('#kanso-backup-destination')).toHaveValue('appdata')
		await expect(page.locator('#kanso-backup-file-rows tr').first()).toBeVisible({ timeout: 30_000 })

		const href = await page.locator('#kanso-backup-file-rows tr').first().locator('a.kanso-backup-download').getAttribute('href')
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
})
