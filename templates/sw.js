// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Kanso service worker (PWA shell). Served by PageController::serviceWorker()
// from /apps/kanso/sw.js so its default scope is /apps/kanso/ — it only ever
// controls Kanso's own pages/assets, never the rest of the Nextcloud instance.
//
// Deliberately a CLASSIC, dependency-free worker (no imports, no build step): it
// is delivered verbatim by PHP, kept out of the Vite bundle so the js/ output
// dir can be cleaned on every build without losing it. The real offline-DATA
// story is the persisted TanStack Query cache in the app; this worker only makes
// the app installable and lets it boot + show its last assets while offline.

// v4: API responses are no longer cached (see the fetch handler). The bump is
// load-bearing, not cosmetic — `activate` below deletes every cache that isn't
// prefixed with the CURRENT version, so upgrading also purges the board payloads
// a v3 worker already wrote into Cache Storage on existing installs.
const VERSION = 'kanso-pwa-v4'
const SHELL_CACHE = VERSION + '-shell'
const RUNTIME_CACHE = VERSION + '-runtime'

// A stable key under which the last good app-shell HTML is stored, so an offline
// launch (any in-scope navigation) can still boot the SPA.
const SHELL_KEY = 'kanso-app-shell'

self.addEventListener('install', () => {
	// A fresh worker should take over as soon as it is installed rather than
	// waiting for every tab to close — the caching strategy is backward
	// compatible, so there is no half-updated state to guard against.
	self.skipWaiting()
})

self.addEventListener('activate', (event) => {
	event.waitUntil((async () => {
		// Drop caches from older worker versions so a deploy can't serve stale
		// assets forever.
		const keys = await caches.keys()
		await Promise.all(
			keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k)),
		)
		await self.clients.claim()
	})())
})

// The registered scope's path (e.g. /apps/kanso/ or /index.php/apps/kanso/).
// Everything the worker handles is matched against this so it never touches
// requests belonging to other Nextcloud apps.
function scopePath() {
	try {
		return new URL(self.registration.scope).pathname
	} catch {
		return '/'
	}
}

// Is this an API request (…/apps/kanso/api/…)? Both URL shapes are covered
// because the check is relative to the registered scope, which is whichever of
// /apps/kanso/ or /index.php/apps/kanso/ the worker was registered under.
function isApiRequest(pathname, scope) {
	const prefix = scope.endsWith('/') ? scope : scope + '/'
	const rest = pathname.slice(prefix.length)
	return rest === 'api' || rest.startsWith('api/')
}

async function networkFirst(request, cacheName) {
	const cache = await caches.open(cacheName)
	try {
		const response = await fetch(request)
		if (response && response.ok) {
			cache.put(request, response.clone())
		}
		return response
	} catch (err) {
		const cached = await cache.match(request)
		if (cached) {
			return cached
		}
		throw err
	}
}

// Navigations: fetch the live shell, but cache it under a single stable key and
// fall back to that when offline so the SPA still boots (its client-side router
// then renders from the persisted query cache).
async function navigate(request) {
	const cache = await caches.open(SHELL_CACHE)
	try {
		const response = await fetch(request)
		if (response && response.ok) {
			cache.put(SHELL_KEY, response.clone())
		}
		return response
	} catch (err) {
		const cached = await cache.match(SHELL_KEY)
		if (cached) {
			return cached
		}
		throw err
	}
}

self.addEventListener('fetch', (event) => {
	const request = event.request

	// Never intercept mutations: writes must reach the server (and the app's own
	// optimistic/offline-queue layer owns retry semantics, not the worker).
	if (request.method !== 'GET') {
		return
	}

	const url = new URL(request.url)

	// Same-origin only, and only within Kanso's own scope.
	if (url.origin !== self.location.origin) {
		return
	}
	const scope = scopePath()
	if (!url.pathname.startsWith(scope)) {
		return
	}

	if (request.mode === 'navigate') {
		event.respondWith(navigate(request))
		return
	}

	// API responses are never cached. Cache Storage is origin-scoped, ignores
	// Cache-Control and is NOT partitioned by user, and nothing clears it when a
	// Nextcloud session ends (logout is a full server navigation, so the app gets
	// no chance to purge) — caching /api here would leave board titles,
	// descriptions and comments readable on the device after the session was
	// over. The offline-DATA story is the app's own persisted query cache (see
	// the header comment above), which IS scoped to the user who stored it, so
	// letting API GETs go straight to the network costs no offline capability.
	if (isApiRequest(url.pathname, scope)) {
		return
	}

	// Everything else in scope — the app bundles (js/css/img) — is
	// network-first with a cache fallback. Deliberately NOT cache-first for the
	// bundles: Vite's ENTRY files (kanso-main.mjs, kanso-public.mjs) are NOT
	// content-hashed, so a cache-first entry would survive an app update/rebuild
	// and keep importing chunk hashes that no longer exist on disk — white-
	// screening the app until the cache was manually cleared. Network-first
	// always picks up an update when online; the cache is still populated so an
	// offline reload boots from the last good copy.
	event.respondWith(networkFirst(request, RUNTIME_CACHE))
})

// ── Web push (#mobile-pwa) ──────────────────────────────────────────────────
// These fire only if a push actually arrives, so they are harmless to ship ahead
// of the server-side send path. The payload is a small JSON envelope
// { title, body, url, tag }; a malformed/empty push degrades to a generic
// notification rather than throwing.

self.addEventListener('push', (event) => {
	let data = {}
	try {
		data = event.data ? event.data.json() : {}
	} catch {
		data = { body: event.data ? event.data.text() : '' }
	}
	const title = data.title || 'Kanso'
	const options = {
		body: data.body || '',
		tag: data.tag || undefined,
		// Reuse the app icon; data.url is where a tap should land (a /card/{id}
		// deep link from NotificationService).
		icon: data.icon,
		badge: data.badge,
		data: { url: data.url || scopePath() },
	}
	event.waitUntil(self.registration.showNotification(title, options))
})

self.addEventListener('notificationclick', (event) => {
	event.notification.close()
	const targetUrl = event.notification.data && event.notification.data.url
		? event.notification.data.url
		: scopePath()
	event.waitUntil((async () => {
		// Focus an existing Kanso tab if one is open (and navigate it), else open a
		// new one — the standard "don't spawn duplicate tabs" click behaviour.
		const clientList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
		for (const client of clientList) {
			if (client.url.includes(scopePath()) && 'focus' in client) {
				await client.focus()
				if ('navigate' in client) {
					try {
						await client.navigate(targetUrl)
					} catch {
						// Cross-scope navigate can reject; the focus above is enough.
					}
				}
				return
			}
		}
		if (self.clients.openWindow) {
			await self.clients.openWindow(targetUrl)
		}
	})())
})
