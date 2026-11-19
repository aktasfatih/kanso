// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, onScopeDispose, shallowRef, watch } from 'vue'
import { resolveCardRef } from '../services/api.js'
import { onBoardChanged } from '../services/realtime.js'
import { onBoardChangesApplied } from './useBoardDelta.js'

const MAX_REFERENCES = 50
const CONCURRENT_READS = 4

export function useResolvedCardReferences(boardId, context, references, cached) {
	const resolved = shallowRef(new Map())
	const missing = computed(() => [...new Set(references.value)]
		.filter((reference) => !cached.value.has(reference)).slice(0, MAX_REFERENCES))
	let refreshCurrent = () => {}

	watch([context, () => missing.value.join('\n')], ([current], _previous, onCleanup) => {
		resolved.value = new Map()
		const controller = new AbortController()
		let timer = null
		let inFlight = false
		let dirty = false
		let revision = 0
		onCleanup(() => {
			controller.abort()
			clearTimeout(timer)
			refreshCurrent = () => {}
		})
		if (!current) return
		const sourceBoard = boardId.value
		function schedule() {
			if (!inFlight && timer === null && !controller.signal.aborted) {
				timer = setTimeout(() => { timer = null; run() }, 1000)
			}
		}
		refreshCurrent = () => {
			if (!missing.value.length || globalThis.document?.visibilityState === 'hidden') return
			revision++
			resolved.value = new Map()
			dirty = true
			schedule()
		}
		async function run() {
			inFlight = true
			dirty = false
			const queue = [...missing.value]
			async function read() {
				while (queue.length && !controller.signal.aborted) {
					const reference = queue.shift()
					const ticket = revision
					try {
						const hit = await resolveCardRef(sourceBoard, reference, { signal: controller.signal, timeout: 5000 })
						if (!controller.signal.aborted && ticket === revision && Number.isSafeInteger(hit?.cardId) && hit.cardId > 0 && typeof hit.title === 'string') {
							resolved.value = new Map(resolved.value).set(reference, hit)
						}
					} catch { /* Missing and inaccessible references stay plain text. */ }
				}
			}
			const workers = Math.min(CONCURRENT_READS, queue.length)
			await Promise.all(Array.from({ length: workers }, () => read()))
			inFlight = false
			if (dirty) schedule()
		}
		run()
	}, { immediate: true, flush: 'sync' })

	const refresh = () => refreshCurrent()
	const unsubscribePush = onBoardChanged((changed) => {
		if (String(changed) !== String(boardId.value)) refresh()
	})
	const unsubscribeDelta = onBoardChangesApplied(({ boardId: changed }) => {
		if (String(changed) !== String(boardId.value)) refresh()
	})
	const interval = setInterval(refresh, 30_000)
	interval.unref?.()
	globalThis.window?.addEventListener('focus', refresh)
	globalThis.document?.addEventListener('visibilitychange', refresh)
	onScopeDispose(() => {
		unsubscribePush()
		unsubscribeDelta()
		clearInterval(interval)
		globalThis.window?.removeEventListener('focus', refresh)
		globalThis.document?.removeEventListener('visibilitychange', refresh)
	})

	return computed(() => new Map([...resolved.value, ...cached.value]))
}
