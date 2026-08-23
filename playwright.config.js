// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@playwright/test'

export default defineConfig({
	testDir: './tests/e2e',
	// Serial by default: every spec runs as the same `admin` user against one
	// shared DB (fixed board names, delete-then-recreate, per-user aggregate
	// views like my-work/inbox/search), so concurrent specs would corrupt each
	// other. The suite is parallel-READY: set E2E_ISOLATE=1 and each worker
	// provisions its own Nextcloud user (see tests/e2e/helpers.js), which
	// namespaces all of that per worker — then E2E_WORKERS can be raised safely.
	//   E2E_ISOLATE=1 E2E_WORKERS=4 npx playwright test
	workers: process.env.E2E_WORKERS ? Number(process.env.E2E_WORKERS) : 1,
	// 120s per test: the self-hosted CI runner is ~4-5x slower than a dev box,
	// so a test that takes ~10s locally can approach the old 60s cap there and
	// flake. Locally tests still finish in a few seconds, so this only adds
	// headroom on slow infra (and avoids the retry churn a tight cap caused).
	timeout: 120_000,
	// Warm the app once before any spec so the first spec doesn't race
	// PHP/route-cache cold-start (a long-standing flake on `checklist`, which
	// runs first alphabetically).
	globalSetup: './tests/e2e/global-setup.js',
	// Two retries as a backstop for confirmed slow-CI timing flakes (cold-start
	// races that pass on a later attempt). Retries only re-run FAILED tests, so
	// real failures still fail on the 3rd attempt.
	retries: 2,
	// Reporter: force the verbose `list` reporter in CI too — Playwright otherwise
	// defaults to the terse `dot` reporter when CI is set, which hides test names
	// and makes a hang impossible to attribute. `list` prints one line per test as
	// it finishes (so a stuck test is the one right after the last printed line);
	// also emit the HTML report to populate the uploaded CI artifact.
	reporter: process.env.CI
		? [['list'], ['html', { open: 'never' }]]
		: 'list',
	use: {
		baseURL: 'http://localhost:8891',
		screenshot: 'only-on-failure',
		// Reuse the authenticated session captured in global-setup so specs start
		// already logged in — their ncLogin() then detects the live session and
		// returns immediately instead of driving the full UI login form. Removes
		// ~245 redundant logins and the cold-start login race (top flake source).
		storageState: 'tests/e2e/.auth/admin.json',
	},
	// Exclude the perf spec from the default `npm run test:e2e` run.
	// It requires a separately seeded 2 001-card board (scripts/seed-board.mjs)
	// and is intentionally slow. Run it explicitly with:
	//   npx playwright test --config playwright.perf.config.js
	testIgnore: ['**/perf.spec.js'],
})
