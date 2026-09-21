// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { request as apiRequest } from '@playwright/test'
import { test, expect, ncLogin, authFor, ADMIN, TESTER, BASE } from './helpers.js'

// GitHub #161 — where scheduled backups are written, and what the panel is
// allowed to say about it.
//
// A backup written into a user's Files folder adds two Files-activity entries
// per board per run (one created, one deleted by retention pruning) to the
// stream of whoever owns the folder. Nextcloud's own Files hooks write those; no
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

		// 1. The escape hatch is surfaced, right where the setting it depends on is.
		const hint = page.locator('#kanso-backup-account-hint')
		await expect(hint).toBeVisible()
		const hintText = (await hint.innerText()).toLowerCase()
		expect(hintText).toContain('service account')
		expect(hintText).toContain('activity')
		// ...including the tradeoff, so nobody switches accounts and then wonders
		// where their backups went.
		expect(hintText).toContain('tradeoff')

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
		const appdata = (await page.locator('#kanso-backup-destination-hint-appdata').innerText()).toLowerCase()
		expect(appdata).toContain('activity')
		expect(appdata).toMatch(/not browsable|not (?:browsable|syncable)|only way to get one back|list at the bottom/)

		// Files folder: browsable and off-site-capable, and it says so.
		const files = (await page.locator('#kanso-backup-destination-hint-files').innerText()).toLowerCase()
		expect(files).toMatch(/external storage|off this server|browse/)

		// The account/path fields belong to the Files destination only.
		await page.selectOption('#kanso-backup-destination', 'appdata')
		await expect(page.locator('#kanso-backup-files-config')).toBeHidden()
		await page.selectOption('#kanso-backup-destination', 'files')
		await expect(page.locator('#kanso-backup-files-config')).toBeVisible()
	})

	test('a run into app data lists its backups and the download returns a real zip', async ({ page, request }) => {
		await gotoPanel(page)

		// Switch to app data and take a backup from the panel itself — the whole
		// round trip an admin does, not an API call behind its back.
		await page.selectOption('#kanso-backup-destination', 'appdata')
		await page.check('#kanso-backup-enabled')
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
