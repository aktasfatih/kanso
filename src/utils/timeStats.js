// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

export function timeStatsRange(preset, now = new Date()) {
	const end = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()))
	const start = new Date(end)
	if (preset === 'month') start.setUTCDate(1)
	else start.setUTCDate(start.getUTCDate() - ({ today: 0, week: 6, month30: 29 }[preset] ?? 29))
	return { from: start.toISOString().slice(0, 10), to: end.toISOString().slice(0, 10) }
}
