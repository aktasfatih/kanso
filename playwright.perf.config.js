// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Performance test config — run with:
//   npx playwright test --config playwright.perf.config.js
//
// Requires the 2 001-card board pre-seeded:
//   node scripts/seed-board.mjs
import { defineConfig } from '@playwright/test'

export default defineConfig({
	testDir: './tests/e2e',
	testMatch: ['**/perf.spec.js'],
	workers: 1,
	timeout: 120_000,
	use: {
		baseURL: 'http://localhost:8891',
		screenshot: 'only-on-failure',
	},
})
