// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// "Share from Files" (#3645): a Files-app action that copies the picked file
// onto a Kanso card. Loaded by OCA\Files::loadAdditionalScripts (wired from
// Application.php), so it ships none of the board app - just the action + a small
// board+card picker dialog mounted on demand.

import '@nextcloud/dialogs/style.css'
import { createApp } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { registerFileAction, FileType, Permission } from '@nextcloud/files'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import AddToKansoDialog from './components/AddToKansoDialog.vue'
import kansoSvg from '../img/app.svg?raw'

// One shared query client for every picker instance (the search results cache
// survives reopening the dialog).
const queryClient = new QueryClient({
	defaultOptions: { queries: { staleTime: 30_000, retry: 1 } },
})

// Mounts the picker dialog for a single node, resolving once the dialog closes.
function openPicker(node) {
	return new Promise((resolve) => {
		const mount = document.createElement('div')
		document.body.appendChild(mount)

		const app = createApp(AddToKansoDialog, {
			fileId: node.fileid,
			fileName: node.basename ?? node.displayname ?? '',
			onClose() {
				app.unmount()
				mount.remove()
				resolve(true)
			},
		})
		app.use(VueQueryPlugin, { queryClient })
		app.mount(mount)
	})
}

registerFileAction({
	id: 'kanso-add-to-card',
	displayName: () => t('kanso', 'Add to Kanso…'),
	iconSvgInline: () => kansoSvg,

	// Single readable FILE only - never a folder, never a batch. The action just
	// offers to copy; the server re-checks the actor can read the node and can
	// EDIT the target card, so this is a UX gate, not the security boundary.
	enabled: (nodes) => {
		if (!Array.isArray(nodes) || nodes.length !== 1) {
			return false
		}
		const node = nodes[0]
		return node.type === FileType.File
			&& (node.permissions & Permission.READ) !== 0
	},

	async exec(node) {
		await openPicker(node)
		// The dialog owns success/error toasts; report a silent (null) result so
		// the Files app does not emit its own generic toast.
		return null
	},
})
