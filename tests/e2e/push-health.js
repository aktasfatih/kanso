// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Is the realtime push path ADVERTISED-but-dead?
 *
 * Nextcloud advertises the `notify_push` capability from the moment
 * `occ notify_push:setup` has succeeded ONCE, and never retracts it. The
 * advertisement is a database row, not a probe — so a stack whose daemon died,
 * or whose apache `/push` reverse proxy vanished on a container recreate, keeps
 * telling every client "use push". src/services/realtime.js says the same thing
 * from the client side: `listen()` returns the capability flag, never
 * connectivity.
 *
 * That state is worse than having no push at all, because the lie is invisible:
 * clients dial ws://…/push/ws, get a plain Nextcloud 404, and the only symptom
 * is a handful of e2e specs failing on timeouts and console-error assertions
 * 40 minutes into a run. That is exactly how the dev stack silently lost its
 * `/push` proxy (#10443) — `dev/setup.sh` runs `notify_push:self-test`, but a
 * plain `docker compose up -d` (or any recreate of the nextcloud service)
 * re-creates the container without re-running setup.sh.
 *
 * So the e2e run asserts it for itself, once, in global setup: if push is
 * advertised, its advertised websocket endpoint must actually complete a
 * WebSocket handshake. One clear message naming the real cause, before any spec
 * runs, instead of six mystery reds.
 *
 * Why the websocket handshake and not `GET /push/test/cookie`: the handshake is
 * what clients actually perform, and it is the only probe that exercises
 * mod_proxy_wstunnel — the `ProxyPass /push/ws` line in
 * dev/apache/notify_push.conf. A healthy stack answers `101 Switching
 * Protocols` (measured); a Nextcloud with no /push proxy answers `404`, and a
 * proxy whose daemon is gone answers `500`/`502`/`503`.
 *
 * Env:
 *   KANSO_REQUIRE_NOTIFY_PUSH=1   this run NEEDS push (the CI `e2e-push` job):
 *                                 a stack that does not advertise it at all
 *                                 fails here too, naming the boot as the cause,
 *                                 rather than letting the push spec time out.
 *   KANSO_SKIP_PUSH_HEALTHCHECK=1 bypass the check entirely.
 *
 * Runnable on its own against a booted dev stack:
 *   node tests/e2e/push-health.js     # or: npm run check:push
 */

import { randomBytes } from 'node:crypto'
import http from 'node:http'
import https from 'node:https'

const BASE = process.env.KANSO_BASE_URL || 'http://localhost:8891'
const AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

// How long the handshake probe may take. Generous for a cold CI runner; this
// only ever costs its full budget on a stack that is already broken.
const PROBE_TIMEOUT_MS = 10_000

/**
 * Ask Nextcloud whether it advertises notify_push, and for which endpoint.
 *
 * @param {string} base Nextcloud origin
 * @return {Promise<{reachable: boolean, advertised: boolean, websocket: string|null, error: string|null}>} what the capabilities endpoint says
 */
async function readCapability(base) {
	let payload
	try {
		const res = await fetch(`${base}/ocs/v2.php/cloud/capabilities?format=json`, {
			headers: { 'OCS-APIRequest': 'true', Authorization: AUTH },
		})
		if (!res.ok) {
			return { reachable: false, advertised: false, websocket: null, error: `capabilities returned HTTP ${res.status}` }
		}
		payload = await res.json()
	} catch (err) {
		return { reachable: false, advertised: false, websocket: null, error: String(err?.message || err) }
	}
	const caps = payload?.ocs?.data?.capabilities ?? {}
	if (!('notify_push' in caps)) {
		return { reachable: true, advertised: false, websocket: null, error: null }
	}
	// `endpoints.websocket` is what the client library dials. Older/other
	// shapes have carried the base under `base_endpoint`; accept it so a
	// capability we can't read is reported as such rather than as "healthy".
	const push = caps.notify_push ?? {}
	const websocket = push?.endpoints?.websocket
		|| (typeof push?.base_endpoint === 'string' ? push.base_endpoint.replace(/^http/, 'ws').replace(/\/$/, '') + '/ws' : null)
	return { reachable: true, advertised: true, websocket: websocket || null, error: null }
}

/**
 * Attempt a real WebSocket handshake against the advertised endpoint.
 *
 * Deliberately raw `node:http`: `fetch` cannot issue an Upgrade, and the point
 * is to learn WHICH answer came back (404 vs 502 vs connection refused) —
 * a WebSocket client would collapse all of them into one opaque error.
 *
 * @param {string} wsUrl the advertised ws:// or wss:// endpoint
 * @return {Promise<{ok: boolean, detail: string, status: number|null}>} handshake outcome
 */
function probeHandshake(wsUrl) {
	return new Promise((resolve) => {
		let url
		try {
			url = new URL(wsUrl)
		} catch {
			resolve({ ok: false, detail: `the advertised endpoint is not a URL: ${wsUrl}`, status: null })
			return
		}
		const secure = url.protocol === 'wss:' || url.protocol === 'https:'
		const client = secure ? https : http
		const req = client.request({
			protocol: secure ? 'https:' : 'http:',
			hostname: url.hostname,
			port: url.port || (secure ? 443 : 80),
			path: url.pathname + url.search,
			method: 'GET',
			headers: {
				Connection: 'Upgrade',
				Upgrade: 'websocket',
				'Sec-WebSocket-Version': '13',
				'Sec-WebSocket-Key': randomBytes(16).toString('base64'),
			},
			timeout: PROBE_TIMEOUT_MS,
		})
		let settled = false
		const finish = (result) => {
			if (settled) return
			settled = true
			req.destroy()
			resolve(result)
		}
		// The healthy path: the daemon (through mod_proxy_wstunnel) switches
		// protocols. We never speak a frame — completing the handshake is the
		// whole assertion.
		req.on('upgrade', (res, socket) => {
			socket.destroy()
			finish({ ok: res.statusCode === 101, detail: `HTTP ${res.statusCode}`, status: res.statusCode })
		})
		// A plain response means something answered but is not the push daemon.
		req.on('response', (res) => {
			res.resume()
			finish({ ok: false, detail: `HTTP ${res.statusCode} (expected 101 Switching Protocols)`, status: res.statusCode })
		})
		req.on('timeout', () => finish({ ok: false, detail: `no answer within ${PROBE_TIMEOUT_MS} ms`, status: null }))
		req.on('error', (err) => finish({ ok: false, detail: String(err?.message || err), status: null }))
		req.end()
	})
}

/**
 * Turn a failed probe into the sentence that names the real cause.
 *
 * @param {{ok: boolean, detail: string, status: number|null}} probe probe outcome
 * @return {string} operator-facing diagnosis
 */
function diagnose(probe) {
	if (probe.status === 404) {
		return [
			'A 404 means Nextcloud itself answered — the /push reverse proxy is NOT in the',
			'container. /etc/apache2 lives in the container\'s ephemeral layer, so every',
			'recreate of the nextcloud service drops it unless dev/apache/notify_push.conf is',
			'bind-mounted (dev/docker-compose.yml). Recreate the container so the mount lands:',
			'    cd dev && docker compose --profile postgres up -d --force-recreate nextcloud',
		].join('\n')
	}
	if (probe.status !== null && probe.status >= 500) {
		return [
			`A ${probe.status} means the proxy is there but the daemon behind it is not`,
			'(mod_proxy resolves `notify_push` per request, so a stopped/absent daemon shows',
			'up as a DNS-lookup failure in the apache error log). Check and restart it:',
			'    docker logs kanso-dev-push',
			'    cd dev && docker compose --profile postgres up -d notify_push',
		].join('\n')
	}
	if (probe.status !== null) {
		return `Unexpected status ${probe.status} — something is listening on the advertised endpoint, but it is not the notify_push daemon.`
	}
	return [
		'Nothing completed the handshake at the advertised address. Either the port is not',
		'published, or the daemon is down:',
		'    docker logs kanso-dev-push',
		'    cd dev && docker compose --profile postgres up -d notify_push',
	].join('\n')
}

const FOOTER = [
	'',
	'Re-verify the whole path (redis → daemon → Nextcloud → trusted proxy → versions):',
	'    docker exec -u www-data kanso-dev php occ notify_push:self-test',
	'or just re-run the boot, which does the same thing:  ./dev/setup.sh',
	'',
	'To run the suite without push at all (skips the push spec, keeps the poll one):',
	'    KANSO_SKIP_NOTIFY_PUSH=1 npm run test:e2e',
	'To bypass THIS check only:  KANSO_SKIP_PUSH_HEALTHCHECK=1',
].join('\n')

/**
 * Verify that push, if advertised, actually works.
 *
 * Never throws — the caller decides what a failure means (global setup aborts
 * the run, the CLI exits non-zero).
 *
 * @param {object} [options] options
 * @param {string} [options.base] Nextcloud origin (default http://localhost:8891)
 * @param {boolean} [options.required] fail when push is not advertised at all
 * @return {Promise<{ok: boolean, state: string, summary: string, message: string|null}>} verdict
 */
export async function checkPushHealth({ base = BASE, required = process.env.KANSO_REQUIRE_NOTIFY_PUSH === '1' } = {}) {
	const cap = await readCapability(base)

	if (!cap.reachable) {
		const summary = `could not read the notify_push capability (${cap.error})`
		// Not this check's job to decide that Nextcloud is down — every spec is
		// about to say so far more clearly. Only a run that REQUIRES push treats
		// an unreadable capability as fatal.
		return required
			? { ok: false, state: 'unknown', summary, message: `Realtime push was required for this run (KANSO_REQUIRE_NOTIFY_PUSH=1) but ${summary} at ${base}.` }
			: { ok: true, state: 'unknown', summary, message: null }
	}

	if (!cap.advertised) {
		if (required) {
			return {
				ok: false,
				state: 'absent',
				summary: 'notify_push is not advertised',
				message: [
					'Realtime push was REQUIRED for this run (KANSO_REQUIRE_NOTIFY_PUSH=1), but this',
					`Nextcloud does not advertise the notify_push capability at ${base}.`,
					'',
					'That is a BOOT failure, not a test failure: dev/setup.sh could not install,',
					'enable or set up notify_push. Look in the boot output for',
					'"WARNING: could not set up notify_push" — the line under it names the cause',
					'(usually the pinned release tarball not downloading, or a version skew between',
					'the app pin in dev/setup.sh and the daemon image tag in dev/docker-compose.yml).',
				].join('\n') + '\n' + FOOTER,
			}
		}
		// The honest, common case: no push on this stack, the client falls back
		// to delta polling, and realtime.spec.js's push test is skipped via
		// KANSO_SKIP_NOTIFY_PUSH=1 (what CI does).
		return { ok: true, state: 'absent', summary: 'notify_push is not advertised — the delta-poll fallback carries realtime', message: null }
	}

	if (!cap.websocket) {
		return {
			ok: false,
			state: 'malformed',
			summary: 'notify_push is advertised without a websocket endpoint',
			message: [
				'Nextcloud advertises the notify_push capability but not a websocket endpoint, so',
				'no client can connect and none can tell why. Re-run `occ notify_push:setup`:',
				'    ./dev/setup.sh',
			].join('\n') + '\n' + FOOTER,
		}
	}

	const probe = await probeHandshake(cap.websocket)
	if (probe.ok) {
		return { ok: true, state: 'live', summary: `notify_push handshakes at ${cap.websocket}`, message: null }
	}

	return {
		ok: false,
		state: 'dead',
		summary: `notify_push is advertised at ${cap.websocket} but does not handshake (${probe.detail})`,
		message: [
			'Realtime push is ADVERTISED BUT DEAD.',
			'',
			`    advertised endpoint : ${cap.websocket}`,
			`    handshake probe     : ${probe.detail}`,
			'',
			'Nextcloud never retracts the notify_push capability once `notify_push:setup` has',
			'succeeded once, so every client will take the push path and no frame will ever',
			'arrive. Left alone this fails realtime.spec.js\'s push test and any spec that',
			'asserts a clean console (the browser logs the failed ws:// dial).',
			'',
			diagnose(probe),
		].join('\n') + '\n' + FOOTER,
	}
}

// Standalone CLI: `node tests/e2e/push-health.js` / `npm run check:push`.
// process.argv[1] is the resolved script path; comparing against import.meta.url
// keeps the module importable without running this.
if (import.meta.url === `file://${process.argv[1]}`) {
	const verdict = await checkPushHealth()
	if (verdict.ok) {
		console.log(`push health: ${verdict.state} — ${verdict.summary}`)
		process.exit(0)
	}
	console.error(`\npush health: ${verdict.state} — ${verdict.summary}\n`)
	console.error(verdict.message)
	process.exit(1)
}
