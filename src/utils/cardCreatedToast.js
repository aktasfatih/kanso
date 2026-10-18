// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { showMessage, ToastType } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

// Each create replaces the previous toast so rapid entry doesn't stack them.
let current = null

/**
 * @param {() => void} onOpen
 */
export function showCardCreatedToast(onOpen) {
	current?.hideToast()

	const node = document.createElement('span')
	// Trailing space, else screen readers announce "Card createdOpen".
	node.append(t('kanso', 'Card created'), ' ')

	const button = document.createElement('button')
	button.type = 'button'
	button.className = 'kanso-toast-action'
	button.textContent = t('kanso', 'Open')
	button.addEventListener('click', (event) => {
		event.stopPropagation()
		handle?.hideToast()
		onOpen()
	})
	node.append(button)

	const handle = showMessage(node, {
		type: ToastType.SUCCESS,
		onRemove: () => {
			if (current === handle) current = null
		},
	})
	current = handle
}
