// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { generateUrl } from '@nextcloud/router'

/**
 * Register Kanso's service worker so the app is installable and boots offline.
 *
 * The worker is served by PHP from `/apps/kanso/sw.js` (not `/apps/kanso/js/…`)
 * precisely so its default scope is the whole app — we derive the scope from the
 * worker's own directory rather than hard-coding it, which keeps it correct under
 * both pretty URLs (`/apps/kanso/`) and the `/index.php/apps/kanso/` fallback.
 *
 * A no-op when the browser has no service-worker support (older browsers, or a
 * non-secure context) — registration simply doesn't happen and the app runs
 * exactly as before.
 */
export function registerServiceWorker() {
	if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
		return
	}

	// Register after load so the worker install never competes with the initial
	// app render for network/CPU.
	window.addEventListener('load', () => {
		const swUrl = generateUrl('/apps/kanso/sw.js')
		// The worker's max scope is its own directory; strip the filename to get it.
		const scope = swUrl.replace(/sw\.js(?:\?.*)?$/, '')
		navigator.serviceWorker.register(swUrl, { scope }).catch((error) => {
			// A failed registration must never break the app — log and move on.
			console.warn('[kanso] service worker registration failed', error)
		})
	})
}
