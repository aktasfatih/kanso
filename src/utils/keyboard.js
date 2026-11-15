// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

export function submitOnEnter(event, submit) {
	if (event.isComposing) return
	event.preventDefault()
	return submit(event)
}
