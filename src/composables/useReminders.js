// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQueryClient, useMutation } from '@tanstack/vue-query'
import {
	createCardReminder as apiCreateReminder,
	deleteCardReminder as apiDeleteReminder,
} from '../services/api.js'

/**
 * Personal "remind me" reminders for a card (#3816).
 *
 * Optimistic strategy: patch the ['card', String(cardId)] detail cache's
 * `myReminders` array (the setter's own pending reminders) so the pending list
 * updates instantly; roll back on error; invalidate on settled so the true
 * server rows (with real ids / fired state) win. Personal per-user: only the
 * card detail cache carries these, never the board summary.
 */
let optimisticReminderSequence = 0

export function useReminders() {
	const queryClient = useQueryClient()

	const cardKeyFor = (cardId) => ['card', String(cardId)]

	// ── Schedule a reminder ─────────────────────────────────────────────────────
	const createReminder = useMutation({
		mutationFn: ({ cardId, remindAt, commentId }) => apiCreateReminder(cardId, remindAt, commentId ?? null),

		onMutate: async ({ cardId, remindAt, commentId }) => {
			const cardKey = cardKeyFor(cardId)
			await queryClient.cancelQueries({ queryKey: cardKey })
			const previousCard = queryClient.getQueryData(cardKey)
			const optimisticId = `optimistic-${++optimisticReminderSequence}`

			queryClient.setQueryData(cardKey, (old) => {
				if (!old) return old
				const existing = Array.isArray(old.myReminders) ? old.myReminders : []
				const optimistic = {
					id: optimisticId,
					cardId,
					commentId: commentId ?? null,
					remindAt,
					firedAt: null,
					createdAt: Math.floor(Date.now() / 1000),
				}
				// Keep the list sorted soonest-first, matching the server order.
				const updated = [...existing, optimistic].sort((a, b) => a.remindAt - b.remindAt)
				return { ...old, myReminders: updated }
			})

			return { hadCard: !!previousCard, cardKey, optimisticId }
		},

		onSuccess: (created, _vars, context) => {
			if (!context?.hadCard || !Number.isInteger(created?.id)) return
			queryClient.setQueryData(context.cardKey, (old) => {
				if (!old || !Array.isArray(old.myReminders)) return old
				return { ...old, myReminders: old.myReminders.map((reminder) =>
					reminder.id === context.optimisticId ? created : reminder) }
			})
		},

		onError: (_err, _vars, context) => {
			if (context?.hadCard) {
				queryClient.setQueryData(context.cardKey, (old) => old && ({
					...old,
					myReminders: (old.myReminders ?? []).filter((reminder) => reminder.id !== context.optimisticId),
				}))
			}
		},

		onSettled: (_data, _err, { cardId }) => {
			queryClient.invalidateQueries({ queryKey: cardKeyFor(cardId) })
		},
	})

	// ── Cancel a pending reminder ────────────────────────────────────────────────
	const cancelReminder = useMutation({
		mutationFn: ({ cardId, reminderId }) => {
			if (String(reminderId).startsWith('optimistic-')) throw new Error('Reminder has not been saved yet')
			return apiDeleteReminder(cardId, reminderId)
		},

		onMutate: async ({ cardId, reminderId }) => {
			if (String(reminderId).startsWith('optimistic-')) return undefined
			const cardKey = cardKeyFor(cardId)
			await queryClient.cancelQueries({ queryKey: cardKey })
			const previousCard = queryClient.getQueryData(cardKey)

			queryClient.setQueryData(cardKey, (old) => {
				if (!old) return old
				const updated = Array.isArray(old.myReminders)
					? old.myReminders.filter((r) => r.id !== reminderId)
					: []
				return { ...old, myReminders: updated }
			})

			return { hadCard: !!previousCard, cardKey, removed: previousCard?.myReminders?.find((reminder) => reminder.id === reminderId) }
		},

		onError: (_err, _vars, context) => {
			if (context?.hadCard && context.removed) {
				queryClient.setQueryData(context.cardKey, (old) => {
					if (!old) return old
					const reminders = Array.isArray(old.myReminders) ? old.myReminders : []
					if (reminders.some((reminder) => reminder.id === context.removed.id)) return old
					return { ...old, myReminders: [...reminders, context.removed].sort((a, b) => a.remindAt - b.remindAt) }
				})
			}
		},

		onSettled: (_data, _err, { cardId }) => {
			queryClient.invalidateQueries({ queryKey: cardKeyFor(cardId) })
		},
	})

	return {
		createReminder,
		cancelReminder,
	}
}

/**
 * The four "remind me" presets plus a Custom option. Each returns a unix
 * timestamp (seconds) computed from `now` (a Date). One-shot only - no repeat.
 *
 * - Later today: +3 hours (a nudge before end of day)
 * - Tomorrow: 9am the next day
 * - Next week: 9am one week out
 */
export function reminderPresets(now = new Date()) {
	const laterToday = new Date(now.getTime() + 3 * 60 * 60 * 1000)

	const tomorrow = new Date(now)
	tomorrow.setDate(tomorrow.getDate() + 1)
	tomorrow.setHours(9, 0, 0, 0)

	const nextWeek = new Date(now)
	nextWeek.setDate(nextWeek.getDate() + 7)
	nextWeek.setHours(9, 0, 0, 0)

	const ts = (d) => Math.floor(d.getTime() / 1000)
	return [
		{ key: 'later-today', at: ts(laterToday) },
		{ key: 'tomorrow', at: ts(tomorrow) },
		{ key: 'next-week', at: ts(nextWeek) },
	]
}
