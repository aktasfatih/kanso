// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, onScopeDispose, ref, toValue, watch } from 'vue'
import { timeStatsRange } from '../utils/timeStats.js'

export function useTimeStatsRange(preset) {
	const day = ref('')
	const view = typeof window === 'undefined' ? null : window
	const doc = typeof document === 'undefined' ? null : document
	let timer = null
	let stopped = false

	function refresh() {
		if (stopped) return
		const now = Date.now()
		const date = new Date(now)
		day.value = date.toISOString().slice(0, 10)
		clearTimeout(timer)
		const midnight = Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate() + 1)
		timer = setTimeout(refresh, Math.max(1, midnight - now))
	}

	function refreshIfVisible() {
		if (doc?.visibilityState !== 'hidden') refresh()
	}

	refresh()
	watch(() => toValue(preset), refresh, { flush: 'sync' })
	view?.addEventListener('focus', refreshIfVisible)
	doc?.addEventListener('visibilitychange', refreshIfVisible)
	onScopeDispose(() => {
		stopped = true
		clearTimeout(timer)
		view?.removeEventListener('focus', refreshIfVisible)
		doc?.removeEventListener('visibilitychange', refreshIfVisible)
	})

	return computed(() => timeStatsRange(toValue(preset), new Date(day.value + 'T00:00:00Z')))
}
