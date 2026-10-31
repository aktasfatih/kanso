// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQueryClient, useMutation } from '@tanstack/vue-query'
import {
	linkContact as apiLinkContact,
	unlinkContact as apiUnlinkContact,
} from '../services/api.js'
import { boardQueryKey } from './useBoard.js'

/**
 * Resolve a boardId argument that may be a plain value, a Vue ref (.value),
 * a computed ref (.value), or a plain getter function (e.g. () => props.boardId).
 */
function resolveBoardId(boardId) {
	if (typeof boardId === 'function') return boardId()
	if (boardId !== null && typeof boardId === 'object' && boardId.value !== undefined) return boardId.value
	return boardId
}

/**
 * Card contact links (#3530): mutations for linking / unlinking a Nextcloud
 * Contacts entry to a card. Read-only reference - each contact is
 * {contactUri, displayName}. The picker's address-book SEARCH is a one-shot
 * request done in the modal (fetchCardContacts), not a cached query here, since
 * the result set depends on the live search term.
 *
 * Optimistic strategy for toggleContact (link / unlink):
 *   Mirrors useAssignees' onMutate - patch the card's `contacts` array in BOTH
 *   the board summary cache (via boardQueryKey) and the ['card', String(cardId)]
 *   detail cache; rollback both on error; invalidate both on settled. Contacts
 *   are objects, so dedup / removal keys on contactUri.
 */
export function useContacts(boardId) {
	const queryClient = useQueryClient()

	function getBoardKey() {
		return boardQueryKey(resolveBoardId(boardId))
	}

	const toggleContact = useMutation({
		mutationFn: ({ cardId, contact, link }) =>
			link
				? apiLinkContact(cardId, contact.contactUri, contact.displayName)
				: apiUnlinkContact(cardId, contact.contactUri),

		onMutate: async ({ cardId, contact, link, boardKey }) => {
			const cardKey = ['card', String(cardId)]
			await queryClient.cancelQueries({ queryKey: boardKey })
			await queryClient.cancelQueries({ queryKey: cardKey })

			const previousBoard = queryClient.getQueryData(boardKey)
			const previousCard = queryClient.getQueryData(cardKey)

			const patch = (list) => {
				const arr = Array.isArray(list) ? list : []
				if (link) {
					return arr.some((c) => c.contactUri === contact.contactUri)
						? arr
						: [...arr, { contactUri: contact.contactUri, displayName: contact.displayName }]
				}
				return arr.filter((c) => c.contactUri !== contact.contactUri)
			}

			queryClient.setQueryData(boardKey, (old) => {
				if (!old) return old
				return {
					...old,
					cards: old.cards.map((c) => (c.id !== cardId ? c : { ...c, contacts: patch(c.contacts) })),
				}
			})

			queryClient.setQueryData(cardKey, (old) => {
				if (!old) return old
				return { ...old, contacts: patch(old.contacts) }
			})

			return { contact, link, previousContacts: previousCard?.contacts, hadCard: !!previousCard, previousBoardContacts: previousBoard?.cards?.find((c) => c.id === cardId)?.contacts, hadBoardCard: !!previousBoard?.cards?.find((c) => c.id === cardId), cardKey, boardKey, cardId }
		},

		onError: (_err, _vars, context) => {
			const restoreContact = (current, previous) => {
				const existing = Array.isArray(current) ? current : []
				const prior = Array.isArray(previous) ? previous.find((item) => item.contactUri === context.contact.contactUri) : undefined
				const without = existing.filter((item) => item.contactUri !== context.contact.contactUri)
				return prior ? [...without, prior] : without
			}
			if (context?.hadBoardCard) {
				queryClient.setQueryData(context.boardKey, (old) => old && ({
					...old,
					cards: old.cards.map((card) => card.id === context.cardId ? { ...card, contacts: restoreContact(card.contacts, context.previousBoardContacts) } : card),
				}))
			}
			if (context?.hadCard) {
				queryClient.setQueryData(context.cardKey, (old) => old && ({ ...old, contacts: restoreContact(old.contacts, context.previousContacts) }))
			}
		},

		onSettled: (_data, _err, { cardId, boardKey }) => {
			queryClient.invalidateQueries({ queryKey: ['card', String(cardId)] })
			queryClient.invalidateQueries({ queryKey: boardKey })
		},
	})

	return {
		toggleContact: {
			...toggleContact,
			mutate: (variables, options) => toggleContact.mutate({ ...variables, boardKey: getBoardKey() }, options),
			mutateAsync: (variables, options) => toggleContact.mutateAsync({ ...variables, boardKey: getBoardKey() }, options),
		},
	}
}
