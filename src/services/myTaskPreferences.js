// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { reactive } from 'vue'

export const myTaskPreferences = reactive({
	search: '',
	board: '',
	hubBoard: null,
	status: 'all',
	due: 'all',
	sort: 'due',
	view: 'list',
})
