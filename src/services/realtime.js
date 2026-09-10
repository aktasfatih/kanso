// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { listen } from '@nextcloud/notify_push'

/**
 * notify_push bridge. The backend broadcasts a `kanso_board_changed` custom
 * event (body: {boardId}) to every board participant on each mutation.
 *
 * `listen()` returns synchronously whether push is ADVERTISED by this server -
 * that is only the notify_push capability, never connectivity: the flag it
 * returns (`window._notify_push_available`) is set from the capability object
 * before the library has even constructed the WebSocket, so it cannot reflect
 * whether the daemon is up or the reverse proxy forwards /push. The socket
 * connects, authenticates and reconnects in the background. When push is
 * unavailable the listener is simply never called and polling (see useBoard)
 * carries realtime alone.
 */

const EVENT_NAME = 'kanso_board_changed'

// initRealtime is called once from main.js module top-level; if it ever
// moves into a component's setup it needs an idempotency guard.
let available = false

// Push liveness latch (#10225). `available` above is the server's ADVERTISEMENT
// and stays true on an instance whose notify_push daemon is dead or whose proxy
// never forwards the websocket - a common self-hosted state, and one the
// capability never retracts once `notify_push:setup` has succeeded once.
// Trusting it there stretched every board's delta poll 5s -> 30s while no frame
// could ever arrive, so a change took up to 30s to show. So we are pessimistic:
// a RECEIVED frame is the only unambiguous evidence the whole path works, and
// nothing short of one flips this.
//
// Deliberately one-way, and deliberately module-global:
//  - One-way: if the socket dies AFTER a frame arrived, this stays true and
//    that session keeps the 30s cadence. Closing that hole needs a liveness
//    timer, and there is no correct timeout - a HEALTHY socket on an idle board
//    emits nothing Kanso can observe (the library swallows its only
//    heartbeat-ish message, `authenticated`, before listeners see it, and WS
//    pings are protocol-level), so silence is indistinguishable from "nobody
//    changed the board" and any finite timeout would degrade every healthy
//    deployment. Reconnection is already the library's job (onerror = onclose,
//    linear backoff). The case guarded here is the one that is decidable:
//    startup, where push was never working at all.
//  - Module-global rather than per-board: one working socket proves push works,
//    for every board. Frames for board A are proof for board B too.
let confirmed = false

/**
 * Register the push listener. Call once at app startup.
 *
 * @param {(boardId: number) => void} onBoardChanged called with the changed board's id
 * @return {boolean} whether push is advertised by the server (not yet proven live)
 */
export function initRealtime(onBoardChanged) {
	available = listen(EVENT_NAME, (name, body) => {
		// Any `kanso_board_changed` frame - even one whose body we can't use -
		// proves the capability, the daemon, the proxy and the auth handshake all
		// work. (Only frames for THIS event reach here: the library dispatches by
		// event name, so another app's traffic on the shared socket is equally
		// good evidence but cannot be observed from in here.)
		confirmed = true
		const boardId = body?.boardId
		if (boardId !== undefined && boardId !== null) {
			onBoardChanged(boardId)
		}
	})
	return available
}

/**
 * Whether push has been PROVEN live - polling consumers use this to stretch
 * their interval from fallback (5s) to safety-net (30s). False until the first
 * frame lands, so a dead daemon degrades to the fast fallback instead of to a
 * cadence nothing can ever wake.
 *
 * @return {boolean}
 */
export function pushActive() {
	return available && confirmed
}
