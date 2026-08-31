// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig, devices } from '@playwright/test'

// The mobile PWA spec runs on TWO engines so the phone experience is covered on
// both the Chromium (Android Chrome) and WebKit (iOS Safari) families — WebKit is
// the closest we can get to iOS Safari without Apple hardware. Everything else in
// the suite is desktop Chromium exactly as before; projects only fan the ONE
// mobile spec across devices (matched by file), so the existing suite's test
// count and behaviour are unchanged.
const MOBILE_SPEC = '**/mobile-pwa.spec.js'

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
	projects: [
		{
			// The whole existing suite: desktop Chromium, unchanged. No device spread
			// so the environment is byte-for-byte the pre-projects behaviour (default
			// chromium, default viewport); it only adds the mobile-spec/perf excludes.
			name: 'desktop',
			testIgnore: ['**/perf.spec.js', MOBILE_SPEC],
		},
		{
			// The mobile spec on Android-Chrome (Chromium engine).
			name: 'mobile-chromium',
			use: { ...devices['Pixel 7'] },
			testMatch: MOBILE_SPEC,
		},
		{
			// The mobile spec on iOS-Safari (WebKit engine) — no KVM/Apple hardware
			// needed; the closest approximation to real iOS Safari on Linux CI.
			name: 'mobile-webkit',
			use: {
				...devices['iPhone 14'],
				// Playwright's WebKit advertises a synthetic Safari version
				// ("Version/26.5") that Nextcloud's supported-browser gate rejects,
				// redirecting the app to /unsupported. Pin a real, NC-supported iOS
				// Safari UA so we get into the app; the rendering ENGINE is still
				// WebKit (that's what we're exercising — the UA is just a header).
				userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
			},
			testMatch: MOBILE_SPEC,
		},
	],
})
