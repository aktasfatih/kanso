// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useCardLinks — a card's GitHub PR/issue links.
 *
 * Loaded separately from the card detail (like comments): the GET triggers a
 * throttled server-side state refresh, so keep it off the hot card-open path.
 * Add/remove are simple mutations that invalidate the list.
 *
 * `cardId` may be a plain value, a Vue ref, or a getter function.
 */

import { computed, unref } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchCardLinks,
	addCardLink as apiAddCardLink,
	deleteCardLink as apiDeleteCardLink,
} from '../services/api.js'

/**
 * Compute the deterministic git branch name for a card: `kanso-{id}-{slug}`.
 * Mirrors CardLinkService::branchName on the server.
 * @param {number|string} id
 * @param {string} title
 * @returns {string}
 */
export function branchName(id, title) {
	let slug = String(title || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
	if (slug.length > 50) slug = slug.slice(0, 50).replace(/-+$/g, '')
	return slug === '' ? `kanso-${id}` : `kanso-${id}-${slug}`
}

/**
 * @param {import('vue').Ref<string|number>|string|number|Function} cardId
 */
export function useCardLinks(cardId) {
	const queryClient = useQueryClient()

	const resolvedId = computed(() => {
		const v = typeof cardId === 'function' ? cardId() : unref(cardId)
		return String(v)
	})

	const linksKey = computed(() => ['card-links', resolvedId.value])

	const query = useQuery({
		queryKey: linksKey,
		queryFn: () => fetchCardLinks(resolvedId.value),
	})

	const addLink = useMutation({
		mutationFn: (linkUrl) => apiAddCardLink(resolvedId.value, linkUrl),
		onSettled: () => queryClient.invalidateQueries({ queryKey: linksKey.value }),
	})

	const removeLink = useMutation({
		mutationFn: (linkId) => apiDeleteCardLink(resolvedId.value, linkId),
		onSettled: () => queryClient.invalidateQueries({ queryKey: linksKey.value }),
	})

	return { ...query, links: query.data, addLink, removeLink }
}
