// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { shallowRef } from 'vue'

export const relationPicker = shallowRef(null)

export function beginRelationPick(session) {
	relationPicker.value = { ...session, phase: 'selecting', targetId: null }
}

export function finishRelationPick(targetId = null) {
	if (!relationPicker.value) return
	relationPicker.value = { ...relationPicker.value, phase: 'returning', targetId }
}

export function clearRelationPick() {
	relationPicker.value = null
}

export async function navigateRelationPick(router, target) {
	const session = relationPicker.value
	try {
		const failure = await router.push(target)
		if (failure && relationPicker.value === session) clearRelationPick()
	} catch (error) {
		if (relationPicker.value === session) clearRelationPick()
		throw error
	}
}
