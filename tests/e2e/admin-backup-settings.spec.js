// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, ncLogin, ADMIN, BASE } from './helpers.js'

// GitHub #161 — scheduled backups add two Files-activity entries per board per
// run (one created, one deleted by retention pruning) to the stream of whoever
// owns the target folder. Nextcloud's own Files hooks write those; no app API in
// NC 32-34 suppresses them for an individual write, and measurement confirmed it:
// OCP\Activity\IManager::setCurrentUserId() does not reach them at all (the
// Activity app reads OCA\Activity\CurrentUser, i.e. the user SESSION), and
// forcing the session user is order-dependent inside a cron process and leaks
// onto later jobs. So the honest remedy is the one the admin panel spells out:
// point the backup account at a dedicated service account and the entries land
// in ITS feed instead of yours. See the class docblock on BackupService.
//
// The panel ALSO has a real, working notification setting now ("Notify
// administrators about a run": never / on failure / always). That control is
// legitimate and its wording must stay free — it governs Kanso's own message,
// not the Files-activity rows. So this spec does not police the word
// "notification"; it polices the one claim that would be false: that the
// Files-activity entries themselves are suppressed. The two are easy to confuse
// (an admin reading "Notify: Never" could reasonably assume the run goes
// untraced), which is exactly why the hint has to keep them apart.
test.describe('Kanso admin backup settings', () => {
	// The admin settings page is admin-only, and under E2E_ISOLATE the worker's
	// stored session is a plain per-worker user that would just get a 403 here.
	// Start from a clean context and log in as the real admin (see the e2e
	// storageState guard).
	test.use({ storageState: { cookies: [], origins: [] } })

	// Claims the panel may never make, scoped to the ACTIVITY entries rather than
	// to notifications — a suppression verb applied to the activity stream. The
	// legitimate notification wording ("Never", "Only when a backup fails") does
	// not match any of these, and must not be made to.
	const FALSE_SUPPRESSION_CLAIMS = [
		/\b(?:disabl\w*|suppress\w*|silenc\w*|stops?|stopped|prevent\w*|hid\w*|turns? off|switch(?:es)? off)\s+(?:the\s+|these\s+|those\s+|all\s+|any\s+|your\s+|its\s+)*(?:files[\s-])?activity\b/,
		/\b(?:no|zero|without)\s+(?:more\s+|further\s+|new\s+)?(?:files[\s-])?activity\s+(?:entry|entries|rows|records)\b/,
		/\bactivity\s+(?:entry|entries|stream|feed|rows)\b[^.]{0,40}\b(?:are|is|get|gets)\s+(?:not\s+recorded|suppressed|disabled|hidden|skipped|silenced)\b/,
	]

	test('the backup account field explains how to keep backups out of your own activity feed', async ({ page }) => {
		await ncLogin(page, ADMIN)
		await page.goto(`${BASE}/settings/admin/kanso`)

		const section = page.locator('#kanso-backup-settings')
		await expect(section).toBeVisible()
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

		// 4. Nothing in the panel claims the Files-activity entries stop being
		// recorded. They are not Kanso's to suppress — proven unavoidable from
		// inside the app — so any such claim would be a lie to the admin, whether
		// it arrives as copy or as a control. Fail loudly if one ever appears.
		const panel = (await section.innerText()).toLowerCase()
		for (const claim of FALSE_SUPPRESSION_CLAIMS) {
			expect(panel, `the backup panel must not claim activity entries are suppressed (${claim})`).not.toMatch(claim)
		}
	})
})
