// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

export function formatDuration(totalSeconds) {
	const seconds = Math.max(0, Math.floor(Number(totalSeconds) || 0))
	if (seconds === 0) return '0m'
	const hours = Math.floor(seconds / 3600)
	const minutes = Math.floor((seconds % 3600) / 60)
	const remainder = seconds % 60
	const parts = []
	if (hours > 0) parts.push(`${hours}h`)
	if (minutes > 0) parts.push(`${minutes}m`)
	if (remainder > 0 && hours === 0 && minutes === 0) parts.push(`${remainder}s`)
	return parts.join(' ')
}
