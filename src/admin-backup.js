// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Kanso admin backup settings (#3615, #161). A tiny vanilla controller for the
// server-rendered admin form in templates/admin-backup.php: it saves the
// enabled/destination/path/retention config, can trigger a backup on demand,
// and lists the stored backups with a download link — which is the ONLY way to
// reach them when the destination is Kanso's app data. Everything talks to the
// admin-gated /api/admin/backup endpoints.
//
// The list also DELETES. Retention only prunes boards that still exist during a
// run that happens, so a deleted board's archives — full exports carrying every
// private card and attachment — are never cleaned up again, and under app data
// there is no other way to reach the files at all. Hence a per-row delete, and
// hence the confirm below names the file and says it is the only copy: there is
// nowhere to un-delete it from.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

const url = (path) => generateUrl('/apps/kanso' + path)

// Mirrors BackupService::DEST_APPDATA / DEST_FILES. The server is the authority
// on what is stored; these only decide which fields the form shows.
const DEST_APPDATA = 'appdata'

function formatSize(bytes) {
	if (!bytes || bytes < 0) {
		return '—'
	}
	const units = ['B', 'KB', 'MB', 'GB', 'TB']
	let value = bytes
	let unit = 0
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024
		unit++
	}
	return (unit === 0 ? value : value.toFixed(1)) + ' ' + units[unit]
}

function formatTime(seconds) {
	if (!seconds) {
		return '—'
	}
	return new Date(seconds * 1000).toISOString().replace('T', ' ').slice(0, 16) + ' UTC'
}

function boot() {
	const root = document.getElementById('kanso-backup-settings')
	if (!root) {
		return
	}

	const enabled = document.getElementById('kanso-backup-enabled')
	const destination = document.getElementById('kanso-backup-destination')
	const filesConfig = document.getElementById('kanso-backup-files-config')
	const appdataHint = document.getElementById('kanso-backup-destination-hint-appdata')
	const filesHint = document.getElementById('kanso-backup-destination-hint-files')
	const deleteHintAppData = document.getElementById('kanso-backup-delete-hint-appdata')
	const deleteHintFiles = document.getElementById('kanso-backup-delete-hint-files')
	const path = document.getElementById('kanso-backup-path')
	const account = document.getElementById('kanso-backup-account')
	const retention = document.getElementById('kanso-backup-retention')
	const notify = document.getElementById('kanso-backup-notify')
	const saveBtn = document.getElementById('kanso-backup-save')
	const runBtn = document.getElementById('kanso-backup-run')
	const lastRun = document.getElementById('kanso-backup-lastrun')
	const fileRows = document.getElementById('kanso-backup-file-rows')
	const fileList = document.getElementById('kanso-backup-file-list')
	const fileEmpty = document.getElementById('kanso-backup-file-empty')
	const fileError = document.getElementById('kanso-backup-file-error')

	const payload = () => ({
		enabled: !!enabled.checked,
		destination: (destination && destination.value) || DEST_APPDATA,
		path: path.value.trim(),
		account: (account.value.trim() || 'admin'),
		retention: Math.max(1, Math.min(365, parseInt(retention.value, 10) || 7)),
		// Server-side is the authority on the allowed values; an unrecognised one
		// falls back to the default there rather than silencing the run.
		notify: (notify && notify.value) || 'failure',
	})

	// Exactly one destination is on screen at a time. The account/path fields
	// only mean anything for the Files destination — showing them under app data
	// would invite an admin to fill in a folder that nothing reads — and the same
	// goes for the two explanations: leaving both up made the page read as if
	// both stores were in use. Their VALUES are still saved, so switching back
	// and forth does not make anyone retype a path.
	//
	// The template already renders the right half hidden, so this only has to
	// keep up with changes; nothing flashes on load.
	const applyDestination = () => {
		if (!destination) {
			return
		}
		const appdata = destination.value === DEST_APPDATA
		const toggle = (el, shownUnderAppData) => {
			if (el) {
				el.style.display = appdata === shownUnderAppData ? '' : 'none'
			}
		}
		toggle(appdataHint, true)
		toggle(filesHint, false)
		toggle(filesConfig, false)
	}

	// The delete hint follows the SAVED destination, not the dropdown — which is
	// why it is not part of applyDestination() above. Those hints describe what
	// the next RUN will do, so tracking the unsaved selection is right for them.
	// This one sits next to a button that acts NOW, against whatever is
	// persisted: picking "In a Files folder" without saving and being told the
	// file goes to a trashbin, while Delete hard-deletes from app data, is
	// exactly the wrong way round. The listing endpoint returns the authoritative
	// destination, so that is what drives it.
	//
	// The confirm dialog reads the same value (see deleteFile below), so the
	// hint on the page and the question in front of the button can never say
	// two different things about the same click.
	let savedDestination = null
	const applyDeleteHint = (destinationOfRecord) => {
		if (!destinationOfRecord) {
			return
		}
		savedDestination = destinationOfRecord
		const appdata = destinationOfRecord === DEST_APPDATA
		if (deleteHintAppData) {
			deleteHintAppData.style.display = appdata ? '' : 'none'
		}
		if (deleteHintFiles) {
			deleteHintFiles.style.display = appdata ? 'none' : ''
		}
	}

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

	const renderFiles = (files) => {
		if (!fileRows) {
			return
		}
		fileRows.replaceChildren()
		for (const file of files) {
			const row = document.createElement('tr')
			row.dataset.name = file.name

			const cell = (text) => {
				const td = document.createElement('td')
				// textContent, never innerHTML: the names come from storage and are
				// never worth trusting to a parser.
				td.textContent = text
				return td
			}
			row.appendChild(cell(file.name))

			// The board column doubles as the orphan marker: these rows were
			// always listed, but nothing said which of them belong to a board
			// that is gone — and those are exactly the ones retention will never
			// touch again.
			const board = cell(file.boardId ? '#' + file.boardId : '—')
			if (file.orphaned) {
				row.dataset.orphaned = '1'
				const badge = document.createElement('span')
				badge.className = 'kanso-backup-orphan'
				badge.textContent = ' ' + t('kanso', '(orphaned)')
				badge.title = t('kanso', 'The board this backup came from no longer exists.')
				board.appendChild(badge)
			}
			row.appendChild(board)

			row.appendChild(cell(formatSize(file.size)))
			row.appendChild(cell(formatTime(file.mtime)))

			const actions = document.createElement('td')
			const link = document.createElement('a')
			link.className = 'kanso-backup-download'
			link.href = url('/api/admin/backup/download?name=' + encodeURIComponent(file.name))
			link.textContent = t('kanso', 'Download')
			// A plain link, not a blob: a backup can be hundreds of megabytes and
			// must stream rather than be assembled in the browser.
			link.setAttribute('download', file.name)
			actions.appendChild(link)

			const remove = document.createElement('button')
			remove.type = 'button'
			remove.className = 'kanso-backup-delete'
			remove.textContent = t('kanso', 'Delete')
			remove.addEventListener('click', () => deleteFile(file.name, remove))
			actions.appendChild(remove)

			row.appendChild(actions)

			fileRows.appendChild(row)
		}
		const any = files.length > 0
		if (fileList) {
			fileList.style.display = any ? '' : 'none'
		}
		if (fileEmpty) {
			fileEmpty.style.display = any ? 'none' : ''
		}
		// A listing that arrived clears whatever the last failed one said.
		if (fileError) {
			fileError.style.display = 'none'
		}
	}

	// "The request failed" and "there are no backups" are different facts, and an
	// admin must be able to tell them apart: with the app-data destination this
	// table is the ONLY view of the stored archives, so rendering the empty state
	// after a failed request would claim the backups are gone.
	const showFilesError = () => {
		if (fileRows) {
			fileRows.replaceChildren()
		}
		if (fileList) {
			fileList.style.display = 'none'
		}
		if (fileEmpty) {
			fileEmpty.style.display = 'none'
		}
		if (fileError) {
			fileError.textContent = t('kanso', 'Could not load the stored backups.')
			fileError.style.display = ''
		}
	}

	const loadFiles = async () => {
		try {
			const { data } = await axios.get(url('/api/admin/backup/files'))
			renderFiles((data && data.files) || [])
			applyDeleteHint(data && data.destination)
		} catch (e) {
			showFilesError()
		}
	}

	// Removing an archive is not undoable from here — under app data it is a
	// hard delete, and under a Files folder it lands in the backup account's
	// trashbin rather than anywhere this panel can reach. So the confirm names
	// the exact file and says what it costs, instead of asking "Are you sure?".
	// What it does NOT say is "the only copy of that board": retention defaults
	// to 7, so a board usually has several archives sitting in this very table,
	// and an admin reading an overstatement next to six sibling rows stops
	// believing the rest of the panel.
	//
	// And "for good" is said ONLY where it is true. Under a Files folder the
	// node is deleted through the Files API, which parks it in the backup
	// account's trashbin — recoverable by whoever owns that account — so telling
	// an admin it is gone forever there is the same overstatement one step
	// worse: it is wrong. Like the hint beside the button, the wording follows
	// the SAVED destination the listing reported, never the dropdown's unsaved
	// value; until a listing has answered, neither claim is made.
	//
	// The list is reloaded from the server either way rather than having the row
	// spliced out (or left in place) locally: a failure raised after the file was
	// already unlinked would otherwise leave a phantom row, and the server is the
	// authority on what is stored.
	const deleteFile = async (name, button) => {
		let question
		if (savedDestination === DEST_APPDATA) {
			question = t('kanso', 'Delete {name}? This file is removed for good — Kanso keeps no second copy of it.', { name })
		} else if (savedDestination) {
			question = t('kanso', 'Delete {name}? The file goes to the backup account\'s trashbin — Kanso keeps no second copy of it.', { name })
		} else {
			question = t('kanso', 'Delete {name}? Kanso keeps no second copy of it.', { name })
		}
		if (!window.confirm(question)) {
			return
		}
		button.disabled = true
		try {
			await axios.delete(url('/api/admin/backup/files'), { params: { name } })
			await loadFiles()
			showSuccess(t('kanso', 'Backup deleted'))
		} catch (e) {
			showError(t('kanso', 'Could not delete the backup'))
			// Whatever the server actually has, rather than what this panel
			// assumes it has: the delete may have got as far as the unlink.
			await loadFiles()
		}
	}

	if (destination) {
		destination.addEventListener('change', applyDestination)
		applyDestination()
	}

	saveBtn.addEventListener('click', async () => {
		saveBtn.disabled = true
		try {
			const { data } = await axios.put(url('/api/admin/backup'), payload())
			applyLastRun(data)
			await loadFiles()
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
			await loadFiles()
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

	loadFiles()
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', boot)
} else {
	boot()
}
