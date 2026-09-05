// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { dehydrate, hydrate, onlineManager } from '@tanstack/vue-query'
import { getCurrentUser } from '@nextcloud/auth'
import { dropAllCursors } from '../composables/useBoardDelta.js'
import { invalidateMyWork } from '../composables/queryKeys.js'

/**
 * Offline support for the PWA (database-first, per the charter's state pattern):
 *
 *  1. The TanStack Query cache (board summaries, open-card detail, My Work feeds)
 *     is persisted to IndexedDB and restored on launch, so the app boots to real
 *     data with no network — the service worker serves the shell/bundles, this
 *     serves the data.
 *  2. Writes made while offline are PAUSED by TanStack (default networkMode
 *     'online') and resumed automatically on reconnect; on reconnect we also drop
 *     the delta cursors and invalidate everything so the client reconciles with
 *     the server's source of truth (any change missed while offline is refetched).
 *
 * Deliberately dependency-free: a tiny IndexedDB key-value store + dehydrate/
 * hydrate (already shipped in @tanstack/query-core) rather than pulling in
 * @tanstack/query-persist-client-core + a persister package.
 *
 * NOTE (bounded follow-up): paused mutations are resumed within a session but are
 * NOT persisted across a full reload — that needs a stable mutationKey +
 * queryClient.setMutationDefaults() on every composable so a rehydrated mutation
 * knows its mutationFn. Persisting them without that would restore un-runnable
 * mutations, so shouldDehydrateMutation is false here.
 */

// Bump when the cached SHAPE changes (a query payload restructure) so stale
// entries from an older client are discarded rather than hydrated.
// v2: the `my-cards` payload became { cards, truncated, limit } instead of a
// bare card array, so v1 snapshots must not be hydrated.
const CACHE_BUSTER = 'kanso-cache-v2'
const CACHE_KEY = 'queryClient'
// Data older than this is treated as too stale to show even offline.
const MAX_AGE_MS = 24 * 60 * 60 * 1000
// Coalesce bursts of cache writes into one IndexedDB write.
const PERSIST_DEBOUNCE_MS = 1000

// Only these query families are worth persisting for offline: the board and its
// open-card detail queries, plus the cross-board My Work feeds. Everything else
// (participant/user searches, transient lookups) is cheap to refetch and would
// just bloat the stored snapshot.
const PERSIST_PREFIXES = new Set([
	'board',
	'board-list',
	'card',
	'comments',
	'checklist',
	'card-links',
	'card-attachments',
	'card-time-entries',
	'card-activity',
	'my-cards',
	'my-reviews',
	'inbox',
])

const hasIndexedDb = typeof indexedDB !== 'undefined'
const DB_NAME = 'kanso-offline'
const STORE = 'kv'

function openDb() {
	return new Promise((resolve, reject) => {
		const request = indexedDB.open(DB_NAME, 1)
		request.onupgradeneeded = () => {
			request.result.createObjectStore(STORE)
		}
		request.onsuccess = () => resolve(request.result)
		request.onerror = () => reject(request.error)
	})
}

async function idbGet(key) {
	const db = await openDb()
	try {
		return await new Promise((resolve, reject) => {
			const request = db.transaction(STORE, 'readonly').objectStore(STORE).get(key)
			request.onsuccess = () => resolve(request.result)
			request.onerror = () => reject(request.error)
		})
	} finally {
		db.close()
	}
}

async function idbSet(key, value) {
	const db = await openDb()
	try {
		await new Promise((resolve, reject) => {
			const tx = db.transaction(STORE, 'readwrite')
			tx.objectStore(STORE).put(value, key)
			tx.oncomplete = () => resolve()
			tx.onerror = () => reject(tx.error)
		})
	} finally {
		db.close()
	}
}

async function idbDel(key) {
	const db = await openDb()
	try {
		await new Promise((resolve, reject) => {
			const tx = db.transaction(STORE, 'readwrite')
			tx.objectStore(STORE).delete(key)
			tx.oncomplete = () => resolve()
			tx.onerror = () => reject(tx.error)
		})
	} finally {
		db.close()
	}
}

/**
 * The uid the snapshot belongs to. IndexedDB is origin-scoped, NOT per-user, and
 * a Nextcloud logout is a full server navigation (`/logout`) that tears the SPA
 * down without emitting any JS event — so the app can never purge the snapshot
 * on the way out. Every snapshot is therefore STAMPED with its owner and only
 * ever restored for that same owner; otherwise the next person to log in on a
 * shared browser profile would see the previous user's boards on first paint.
 *
 * Bound by what a client can know: the uid comes from the page Nextcloud served,
 * so this closes the ONLINE cross-user case (a logged-out or different user is
 * served a page that says so) but not the case of an OFFLINE device booting from
 * the worker's cached app shell, where there is no fresh page to disagree with
 * the snapshot. No offline-capable client can verify a session it cannot reach;
 * the trade is deliberate, and it is the same one every offline-first app makes.
 *
 * @return {string|null} the current uid, or null when there is no session.
 */
function currentUid() {
	try {
		return getCurrentUser()?.uid ?? null
	} catch {
		return null
	}
}

function shouldPersistQuery(query) {
	return query.state.status === 'success'
		&& Array.isArray(query.queryKey)
		&& PERSIST_PREFIXES.has(query.queryKey[0])
}

/**
 * Restore the persisted query cache into the client. Best-effort: any failure
 * (no IndexedDB, corrupt/stale/expired snapshot, a snapshot belonging to someone
 * else) leaves the client empty and the app fetches fresh, exactly as before.
 * Call this BEFORE mounting so the first paint can render cached data.
 *
 * @param {import('@tanstack/vue-query').QueryClient} queryClient
 * @return {Promise<void>}
 */
export async function restoreQueryCache(queryClient) {
	if (!hasIndexedDb) {
		return
	}
	try {
		const uid = currentUid()
		const saved = await idbGet(CACHE_KEY)
		// A snapshot is only ever restored for the user who wrote it. `saved.uid`
		// is undefined on snapshots written by an older client, which never equals
		// a real uid — so those are dropped too rather than trusted.
		const mine = !!uid && saved?.uid === uid
		if (!saved || !mine || saved.buster !== CACHE_BUSTER || (Date.now() - saved.timestamp) > MAX_AGE_MS) {
			if (saved) {
				await idbDel(CACHE_KEY)
			}
			return
		}
		hydrate(queryClient, saved.state)
	} catch {
		// Never let a bad snapshot block startup.
	}
}

/**
 * Persist the (filtered) query cache to IndexedDB on every change, debounced.
 *
 * @param {import('@tanstack/vue-query').QueryClient} queryClient
 */
export function startPersistingQueryCache(queryClient) {
	if (!hasIndexedDb) {
		return
	}
	let timer = null
	const persist = () => {
		const uid = currentUid()
		if (!uid) {
			// No session to attribute the data to — writing it would produce a
			// snapshot nobody may read anyway. Skip.
			return
		}
		const snapshot = {
			buster: CACHE_BUSTER,
			uid,
			timestamp: Date.now(),
			state: dehydrate(queryClient, {
				shouldDehydrateQuery: shouldPersistQuery,
				shouldDehydrateMutation: () => false,
			}),
		}
		idbSet(CACHE_KEY, snapshot).catch(() => {
			// A failed write just means this snapshot is skipped; the next one retries.
		})
	}
	queryClient.getQueryCache().subscribe(() => {
		if (timer !== null) {
			clearTimeout(timer)
		}
		timer = setTimeout(persist, PERSIST_DEBOUNCE_MS)
	})
}

/**
 * Wire reconnect handling: when connectivity returns, resume any writes that
 * paused while offline and reconcile the read cache with the server (drop the
 * delta cursors and invalidate everything so active queries refetch).
 *
 * @param {import('@tanstack/vue-query').QueryClient} queryClient
 */
export function initOfflineSync(queryClient) {
	onlineManager.subscribe((online) => {
		if (!online) {
			return
		}
		dropAllCursors()
		queryClient.resumePausedMutations()
		// Invalidate all: active queries refetch now, the rest are marked stale for
		// their next mount. The My Work feeds are always-mounted, so name them too.
		queryClient.invalidateQueries()
		invalidateMyWork(queryClient)
	})
}

/**
 * Convenience: set up cache persistence + reconnect sync in one call.
 *
 * @param {import('@tanstack/vue-query').QueryClient} queryClient
 */
export function initOfflineCache(queryClient) {
	startPersistingQueryCache(queryClient)
	initOfflineSync(queryClient)
}
