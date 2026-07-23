// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { listen } from '@nextcloud/notify_push'

/**
 * notify_push bridge. The backend broadcasts a `kanso_board_changed` custom
 * event (body: {boardId}) to every board participant on each mutation.
 *
 * `listen()` returns synchronously whether push is available on this server
 * (the notify_push capability is present); the websocket itself connects,
 * authenticates and reconnects in the background. When push is unavailable
 * the listener is simply never called and polling (see useBoard) carries
 * realtime alone.
 */

const EVENT_NAME = 'kanso_board_changed'

// initRealtime is called once from main.js module top-level; if it ever
// moves into a component's setup it needs an idempotency guard.
let active = false

/**
 * Register the push listener. Call once at app startup.
 *
 * @param {(boardId: number) => void} onBoardChanged called with the changed board's id
 * @return {boolean} whether push is available
 */
export function initRealtime(onBoardChanged) {
	active = listen(EVENT_NAME, (name, body) => {
		const boardId = body?.boardId
		if (boardId !== undefined && boardId !== null) {
			onBoardChanged(boardId)
		}
	})
	return active
}

/**
 * Whether push is available — polling consumers use this to stretch their
 * interval from fallback (5s) to safety-net (60s).
 *
 * @return {boolean}
 */
export function pushActive() {
	return active
}
