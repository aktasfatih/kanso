// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@playwright/test'

export default defineConfig({
	testDir: './tests/e2e',
	workers: 1,
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
	use: {
		baseURL: 'http://localhost:8891',
		screenshot: 'only-on-failure',
	},
	// Exclude the perf spec from the default `npm run test:e2e` run.
	// It requires a separately seeded 2 001-card board (scripts/seed-board.mjs)
	// and is intentionally slow. Run it explicitly with:
	//   npx playwright test --config playwright.perf.config.js
	testIgnore: ['**/perf.spec.js'],
})
