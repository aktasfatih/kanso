// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Kanso admin backup settings (#3615). A tiny vanilla controller for the
// server-rendered admin form in templates/admin-backup.php: it saves the
// enabled/path/retention config and can trigger a backup on demand, both
// against the admin-gated /api/admin/backup endpoints.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

const url = (path) => generateUrl('/apps/kanso' + path)

function boot() {
	const root = document.getElementById('kanso-backup-settings')
	if (!root) {
		return
	}

	const enabled = document.getElementById('kanso-backup-enabled')
	const path = document.getElementById('kanso-backup-path')
	const account = document.getElementById('kanso-backup-account')
	const retention = document.getElementById('kanso-backup-retention')
	const saveBtn = document.getElementById('kanso-backup-save')
	const runBtn = document.getElementById('kanso-backup-run')
	const lastRun = document.getElementById('kanso-backup-lastrun')

	const payload = () => ({
		enabled: !!enabled.checked,
		path: path.value.trim(),
		account: (account.value.trim() || 'admin'),
		retention: Math.max(1, Math.min(365, parseInt(retention.value, 10) || 7)),
	})

	const applyLastRun = (config) => {
		if (!config || !lastRun) {
			return
		}
		const when = config.lastRunAt > 0
			? new Date(config.lastRunAt * 1000).toISOString().replace('T', ' ').slice(0, 16) + ' UTC'
			: t('kanso', 'never')
		lastRun.dataset.status = config.lastRunStatus || ''
		lastRun.textContent = t('kanso', 'Last run: {when}', { when })
			+ (config.lastRunMessage ? ' — ' + config.lastRunMessage : '')
	}

	saveBtn.addEventListener('click', async () => {
		saveBtn.disabled = true
		try {
			const { data } = await axios.put(url('/api/admin/backup'), payload())
			applyLastRun(data)
			showSuccess(t('kanso', 'Backup settings saved'))
		} catch (e) {
			showError(t('kanso', 'Could not save backup settings'))
		} finally {
			saveBtn.disabled = false
		}
	})

	runBtn.addEventListener('click', async () => {
		runBtn.disabled = true
		try {
			// Persist current form values first so "Run now" uses what's on screen.
			await axios.put(url('/api/admin/backup'), payload())
			const { data } = await axios.post(url('/api/admin/backup/run'))
			applyLastRun(data.config)
			if (data.result && data.result.status === 'ok') {
				showSuccess(t('kanso', 'Backup completed'))
			} else if (data.result && data.result.status === 'disabled') {
				showError(t('kanso', 'Backups are disabled'))
			} else {
				showError(data.result && data.result.message ? data.result.message : t('kanso', 'Backup failed'))
			}
		} catch (e) {
			showError(t('kanso', 'Backup failed'))
		} finally {
			runBtn.disabled = false
		}
	})
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', boot)
} else {
	boot()
}
