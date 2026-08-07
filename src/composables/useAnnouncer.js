// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ref, provide, inject } from 'vue'

/**
 * Screen-reader announcer for the board. A single visually-hidden
 * aria-live="polite" region is rendered once by the board host (BoardView);
 * this composable owns its text and exposes announce(msg).
 *
 * The message is cleared then set on the next microtask so that repeating the
 * same string (e.g. moving a card back and forth) still fires an assistive-tech
 * announcement — some screen readers suppress an aria-live update whose text is
 * identical to the current content.
 */
const ANNOUNCER_KEY = Symbol('kanso:announcer')

/**
 * Create the announcer (call in the host that renders the live region).
 * @return {{ message: import('vue').Ref<string>, announce: (msg: string) => void }}
 */
export function provideAnnouncer() {
	const message = ref('')
	function announce(msg) {
		if (!msg) return
		message.value = ''
		// Re-set on the next frame so identical consecutive messages re-announce.
		requestAnimationFrame(() => { message.value = String(msg) })
	}
	const api = { message, announce }
	provide(ANNOUNCER_KEY, api)
	return api
}

/**
 * Consume the announcer from a descendant (e.g. CardModal). Returns a no-op
 * announce() when no host provided one, so callers never need to null-check.
 * @return {{ announce: (msg: string) => void }}
 */
export function useAnnouncer() {
	return inject(ANNOUNCER_KEY, { announce: () => {} })
}
