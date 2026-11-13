// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const source = readFileSync(new URL('../../dev/setup.sh', import.meta.url), 'utf8')
const boundary = source.indexOf('# --- is this the stack that was asked for?')
assert.ok(boundary > 0)
const bootScript = source.slice(0, boundary).replaceAll('http://localhost:8891', 'http://localhost:8898') + '\nprintf "Fixture install wait completed\\n"\n'
const fatal = 'FATAL: synthetic database stopped during boot'
const retryLogs = ['Initializing database files', 'Unable to start server']

const fakeCli = '#!' + process.execPath + '\n' + String.raw`
import assert from 'node:assert/strict'
import { appendFileSync, readFileSync, writeFileSync } from 'node:fs'
import { basename, join } from 'node:path'
const dir = process.env.SETUP_FIXTURE
const options = JSON.parse(readFileSync(join(dir, 'scenario.json'), 'utf8'))
const name = basename(process.argv[1])
const args = process.argv.slice(2)
appendFileSync(join(dir, 'commands.jsonl'), JSON.stringify([name, ...args]) + '\n')
assert.equal(process.env.E2E_BASE_URL, 'http://localhost:8898')
assert.equal(process.env.KANSO_BASE_URL, 'http://localhost:8898')
const profile = options.driver === 'mariadb' ? 'mysql' : options.driver
let boots = Number(readFileSync(join(dir, 'boots'), 'utf8'))
const state = boots > 1 ? (options.retryState ?? options.state) : options.state
if (name === 'docker') {
	if (args[0] === 'compose') {
		assert.deepEqual(args.slice(1, 3), ['--profile', profile])
		if (args[3] === 'config') {
			console.log('name: kanso-setup-fixture')
		} else if (args[3] === 'up') {
			assert.deepEqual(args.slice(3), ['up', '-d'])
			writeFileSync(join(dir, 'boots'), String(++boots))
			process.exit(options.composeExits?.[boots - 1] ?? 0)
		} else {
			assert.deepEqual(args.slice(3), ['down', '-v'])
			assert.deepEqual(options.volumes ?? [], [])
		}
	} else if (args[0] === 'volume') {
		assert.deepEqual(args.slice(0, 5), ['volume', 'ls', '-q', '--filter', 'label=com.docker.compose.project=kanso-setup-fixture'])
		assert.equal(args[5], '--filter')
		const volume = args[6].replace('label=com.docker.compose.volume=', '')
		assert.ok(['db_mysql', 'nextcloud_html'].includes(volume))
		if (options.volumes?.includes(volume)) console.log('synthetic-volume')
	} else if (args[0] === 'inspect') {
		assert.equal(args[1], '-f')
		assert.equal(args.length, 4)
		if (args[3] === 'kanso-dev') {
			assert.equal(args[2], '{{.State.Status}}')
			console.log('exited')
		} else {
			assert.equal(args[3], 'kanso-dev-db')
			assert.notEqual(profile, 'sqlite')
			if (args[2] === '{{.State.Status}}') {
				console.log(state.status)
			} else {
				assert.equal(args[2], 'status={{.State.Status}} health={{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}} exit={{.State.ExitCode}}')
				console.log('status=' + state.status + ' health=' + (state.health ?? 'none') + ' exit=' + (state.exit ?? 0))
			}
			if (options.inspectExit) console.error('private metadata password=inspect-canary')
			process.exit(options.inspectExit ?? 0)
		}
	} else {
		assert.equal(args[0], 'logs')
		assert.equal(args.at(-1), 'kanso-dev-db')
		assert.notEqual(profile, 'sqlite')
		let logs = boots > 1 ? (options.retryLogs ?? options.logs) : options.logs
		if (args.length > 2) {
			assert.equal(args[1], '--tail')
			const count = Number(args[2])
			assert.ok(count > 0 && count <= 100)
			assert.equal(args.length, 4)
			logs = logs.slice(-count)
		}
		console.log(logs.join('\n'))
		if (options.logStderr) console.error(options.logStderr)
		if (options.logExit) console.error('Synthetic log retrieval failed')
		process.exit(options.logExit ?? 0)
	}
} else if (name === 'curl') {
	assert.deepEqual(args, ['-sf', 'http://localhost:8898/status.php'])
	if (profile === 'sqlite' || (state.status === 'running' && [undefined, null, 'healthy'].includes(state.health))) {
		console.log('{"installed":true}')
	} else {
		console.error('Synthetic downstream getaddrinfo for db failed')
		process.exit(6)
	}
} else {
	throw new Error('Unexpected command: ' + name)
}
`

function runBoot(options = {}) {
	const dir = mkdtempSync(join(tmpdir(), 'kanso-setup-test-'))
	try {
		mkdirSync(join(dir, 'bin'))
		writeFileSync(join(dir, 'package.json'), '{"type":"module"}')
		writeFileSync(join(dir, 'setup.sh'), bootScript)
		writeFileSync(join(dir, 'scenario.json'), JSON.stringify({ driver: 'postgres', state: { status: 'running', health: 'healthy' }, logs: [fatal], ...options }), { mode: 0o600 })
		writeFileSync(join(dir, 'boots'), '0')
		writeFileSync(join(dir, 'commands.jsonl'), '')
		for (const command of ['docker', 'curl', 'sleep', 'npm']) {
			writeFileSync(join(dir, 'bin', command), fakeCli, { mode: 0o700 })
		}
		const result = spawnSync('/bin/sh', [join(dir, 'setup.sh')], {
			encoding: 'utf8', timeout: 10_000,
			env: {
				PATH: `${join(dir, 'bin')}:/usr/bin:/bin`, SETUP_FIXTURE: dir,
				KANSO_DB: options.driver ?? 'postgres', KANSO_SKIP_BUILD: '1',
				E2E_BASE_URL: 'http://localhost:8898', KANSO_BASE_URL: 'http://localhost:8898',
			},
		})
		assert.equal(result.error, undefined)
		assert.equal(result.signal, null)
		return {
			...result, output: result.stdout + result.stderr,
			commands: readFileSync(join(dir, 'commands.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map(line => JSON.parse(line)),
		}
	} finally { rmSync(dir, { recursive: true, force: true }) }
}

function assertDatabaseFailure(result, engine) {
	assert.equal(result.status, 1, result.output)
	assert.ok(result.stderr.includes(`${engine} database failed to start`), result.output)
	assert.doesNotMatch(result.output, /Waiting for Nextcloud|EMPTY webroot|getaddrinfo|Fixture install wait completed/)
	assert.equal(result.commands.some(([name]) => name === 'curl'), false)
	assert.ok(result.commands.some(([name, command, flag]) => name === 'docker' && command === 'logs' && flag === '--tail'))
}

for (const driver of ['postgres', 'mysql', 'mariadb']) {
	const engine = driver === 'postgres' ? 'PostgreSQL' : 'MariaDB'
	test(`${driver} reports a stopped database before the Nextcloud wait`, () => {
		const result = runBoot({ driver, state: { status: 'exited', health: 'unhealthy', exit: 137 } })
		assertDatabaseFailure(result, engine)
		assert.match(result.stderr, /status=exited health=unhealthy exit=137/)
		assert.ok(result.stderr.includes(fatal))
	})
	for (const health of ['starting', 'unhealthy']) {
		test(`${driver} rejects a running database with health=${health}`, () => {
			assertDatabaseFailure(runBoot({ driver, state: { status: 'running', health } }), engine)
		})
	}
	for (const health of ['healthy', null]) {
		test(`${driver} continues with a running database and health=${health}`, () => {
			const result = runBoot({ driver, state: { status: 'running', health } })
			assert.equal(result.status, 0, result.output)
			assert.match(result.stdout, /Fixture install wait completed/)
			assert.equal(result.commands.some(([, command]) => command === 'logs'), false)
		})
	}
}

test('SQLite finishes the install wait without inspecting a database', () => {
	const result = runBoot({ driver: 'sqlite' })
	assert.equal(result.status, 0, result.output)
	assert.match(result.stdout, /Fixture install wait completed/)
	assert.equal(result.commands.some(command => command.includes('kanso-dev-db')), false)
})

test('failed inspection cannot accept healthy stdout or print private inspection errors', () => {
	const result = runBoot({ inspectExit: 42 })
	assertDatabaseFailure(result, 'PostgreSQL')
	assert.match(result.stderr, /inspect exit=42/)
	assert.doesNotMatch(result.output, /inspect-canary/)
})

test('database diagnostics stay failed when reading its logs fails', () => {
	const result = runBoot({ state: { status: 'missing' }, inspectExit: 1, logExit: 44 })
	assertDatabaseFailure(result, 'PostgreSQL')
	assert.match(result.stderr, /Synthetic log retrieval failed/)
})

test('database log tail is bounded and redacts credentials while retaining the fatal line', () => {
	const result = runBoot({ state: { status: 'exited', exit: 1 }, logStderr: 'MARIADB_PASSWORD=stderr-canary', logs: [
		'old-line-outside-tail', ...Array(120).fill('Synthetic startup progress'),
		'MYSQL_PASSWORD=password-canary', 'secret=secret-canary', 'TOKEN=token-canary',
		"CREATE USER example IDENTIFIED BY 'sql-canary'", 'postgresql://test:uri-canary@db/nextcloud', fatal,
	] })
	assertDatabaseFailure(result, 'PostgreSQL')
	assert.ok(result.stderr.includes(fatal))
	assert.match(result.stderr, /redacted/)
	assert.doesNotMatch(result.output, /old-line-outside-tail|password-canary|secret-canary|token-canary|sql-canary|uri-canary|stderr-canary/)
})

test('Compose startup failure retains its exit status without entering diagnostics or the install wait', () => {
	const result = runBoot({ composeExits: [17] })
	assert.equal(result.status, 17, result.output)
	assert.equal(result.commands.some(([, command]) => ['logs', 'inspect'].includes(command)), false)
	assert.equal(result.commands.some(([name]) => name === 'curl'), false)
})

test('pristine MariaDB entrypoint failure recovers once before the database assertion', () => {
	const result = runBoot({ driver: 'mariadb', state: { status: 'exited', exit: 1 }, logs: retryLogs, retryState: { status: 'running', health: 'healthy' } })
	assert.equal(result.status, 0, result.output)
	assert.equal(result.commands.filter(command => command.includes('down')).length, 1)
	assert.equal(result.commands.filter(command => command.includes('up')).length, 2)
	assert.match(result.stdout, /Fixture install wait completed/)
})

test('failed MariaDB retry reports the second failure without a third boot', () => {
	const result = runBoot({ driver: 'mysql', state: { status: 'exited', exit: 1 }, logs: retryLogs, retryState: { status: 'exited', exit: 23 }, retryLogs: [fatal] })
	assertDatabaseFailure(result, 'MariaDB')
	assert.match(result.stderr, /exit=23/)
	assert.ok(result.stderr.includes(fatal))
	assert.equal(result.commands.filter(command => command.includes('down')).length, 1)
	assert.equal(result.commands.filter(command => command.includes('up')).length, 2)
})

test('Compose failure during the MariaDB retry retains its exit status', () => {
	const result = runBoot({ driver: 'mysql', state: { status: 'exited', exit: 1 }, logs: retryLogs, composeExits: [0, 19] })
	assert.equal(result.status, 19, result.output)
	assert.equal(result.commands.filter(command => command.includes('up')).length, 2)
	assert.equal(result.commands.some(command => command.includes('--tail')), false)
	assert.equal(result.commands.some(([name]) => name === 'curl'), false)
})

test('PostgreSQL never takes the MariaDB destructive recovery path', () => {
	const result = runBoot({ state: { status: 'exited', exit: 1 }, logs: retryLogs })
	assertDatabaseFailure(result, 'PostgreSQL')
	assert.equal(result.commands.filter(command => command.includes('down')).length, 0)
})

test('a running MariaDB never takes the destructive recovery path even with matching logs', () => {
	const result = runBoot({ driver: 'mysql', logs: retryLogs })
	assert.equal(result.status, 0, result.output)
	assert.equal(result.commands.filter(command => command.includes('down')).length, 0)
	assert.equal(result.commands.filter(command => command.includes('up')).length, 1)
})

for (const status of ['created', 'restarting', 'paused']) {
	test(`a ${status} PostgreSQL container fails even when its last health was healthy`, () => {
		assertDatabaseFailure(runBoot({ state: { status, health: 'healthy' } }), 'PostgreSQL')
	})
}

for (const volumes of [['db_mysql'], ['nextcloud_html']]) {
	test(`MariaDB retry cannot reset an existing ${volumes[0]} volume`, () => {
		const result = runBoot({ driver: 'mysql', state: { status: 'exited', exit: 1 }, logs: retryLogs, volumes })
		assertDatabaseFailure(result, 'MariaDB')
		assert.equal(result.commands.filter(command => command.includes('down')).length, 0)
		assert.equal(result.commands.filter(command => command.includes('up')).length, 1)
	})
}

for (const logs of retryLogs.map(line => [line])) {
	test(`MariaDB retry requires both log signatures: ${logs[0]}`, () => {
		const result = runBoot({ driver: 'mysql', state: { status: 'exited', exit: 1 }, logs })
		assertDatabaseFailure(result, 'MariaDB')
		assert.equal(result.commands.filter(command => command.includes('down')).length, 0)
	})
}

test('SQLite dependencies remain optional in Compose', () => {
	const compose = readFileSync(new URL('../../dev/docker-compose.yml', import.meta.url), 'utf8')
	const nextcloud = compose.slice(compose.indexOf('\n  nextcloud:'))
	for (const service of ['db', 'db-mysql']) {
		assert.match(nextcloud, new RegExp(`\\n      ${service}:\\n        condition: service_healthy\\n        required: false`))
	}
})
