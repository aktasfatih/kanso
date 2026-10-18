// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { showMessage, ToastType } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

// The toast currently on screen, if any. Rapid entry (type, Enter, type, Enter)
// would otherwise stack one toast per card; each new create replaces the last so
// the "Open" action always points at the card the user just made.
let current = null

/**
 * "Card created" confirmation with an "Open" action (KANSO-302). Shown only for
 * a single-card create - a multi-line paste is a bulk add where "open which
 * one?" has no answer, so callers skip it there.
 *
 * showMessage accepts a DOM Node, which lets us ship an action button without
 * isHTML (the library would otherwise be handed an HTML string to inject).
 * Nothing here moves focus: the composer keeps it for rapid entry, and the
 * button is reachable by Tab like any other control.
 *
 * @param {() => void} onOpen opens the created card
 */
export function showCardCreatedToast(onOpen) {
	current?.hideToast()

	const node = document.createElement('span')
	node.append(t('kanso', 'Card created'))

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
