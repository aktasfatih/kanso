// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@playwright/test'

export default defineConfig({
	testDir: './tests/e2e',
	workers: 1,
	timeout: 60_000,
	use: {
		baseURL: 'http://localhost:8891',
		screenshot: 'only-on-failure',
	},
})
