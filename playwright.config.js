// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@playwright/test'

export default defineConfig({
	testDir: './tests/e2e',
	workers: 1,
	timeout: 60_000,
	// Warm the app once before any spec so the first spec doesn't race
	// PHP/route-cache cold-start (a long-standing flake on `checklist`, which
	// runs first alphabetically).
	globalSetup: './tests/e2e/global-setup.js',
	// One retry as a backstop for transient cold-start hiccups; real failures
	// still fail on the retry.
	retries: 1,
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
