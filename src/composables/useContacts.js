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

		onMutate: async ({ cardId, contact, link }) => {
			const boardKey = getBoardKey()
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

			return { previousBoard, previousCard, cardKey }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousBoard !== undefined) {
				queryClient.setQueryData(getBoardKey(), context.previousBoard)
			}
			if (context?.previousCard !== undefined && context?.cardKey) {
				queryClient.setQueryData(context.cardKey, context.previousCard)
			}
		},

		onSettled: (_data, _err, { cardId }) => {
			queryClient.invalidateQueries({ queryKey: ['card', String(cardId)] })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	return {
		toggleContact,
	}
}
