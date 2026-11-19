// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, shallowRef, watch } from 'vue'
import { resolveCardRef } from '../services/api.js'

const MAX_REFERENCES = 50
const CONCURRENT_READS = 4

export function useResolvedCardReferences(boardId, context, references, cached) {
	const resolved = shallowRef(new Map())
	const missing = computed(() => [...new Set(references.value)]
		.filter((reference) => !cached.value.has(reference)).slice(0, MAX_REFERENCES))

	watch([context, () => missing.value.join('\n')], ([current], _previous, onCleanup) => {
		resolved.value = new Map()
		const controller = new AbortController()
		onCleanup(() => controller.abort())
		if (!current) return
		const sourceBoard = boardId.value
		const queue = [...missing.value]
		async function read() {
			while (queue.length && !controller.signal.aborted) {
				const reference = queue.shift()
				try {
					const hit = await resolveCardRef(sourceBoard, reference, { signal: controller.signal })
					if (!controller.signal.aborted && Number.isSafeInteger(hit?.cardId) && hit.cardId > 0 && typeof hit.title === 'string') {
						resolved.value = new Map(resolved.value).set(reference, hit)
					}
				} catch { /* Missing and inaccessible references stay plain text. */ }
			}
		}
		const workers = Math.min(CONCURRENT_READS, queue.length)
		for (let i = 0; i < workers; i++) read()
	}, { immediate: true, flush: 'sync' })

	return computed(() => new Map([...resolved.value, ...cached.value]))
}
