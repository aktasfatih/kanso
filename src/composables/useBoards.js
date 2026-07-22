// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchBoards,
	createBoard as apiCreateBoard,
	updateBoard as apiUpdateBoard,
	deleteBoard as apiDeleteBoard,
} from '../services/api.js'

export function useBoards() {
	const queryClient = useQueryClient()

	const query = useQuery({
		queryKey: ['boards'],
		queryFn: fetchBoards,
	})

	const createBoard = useMutation({
		mutationFn: (data) => apiCreateBoard(data),
		onSettled: () => queryClient.invalidateQueries({ queryKey: ['boards'] }),
	})

	const updateBoard = useMutation({
		mutationFn: ({ id, data }) => apiUpdateBoard(id, data),
		onSettled: () => queryClient.invalidateQueries({ queryKey: ['boards'] }),
	})

	const deleteBoard = useMutation({
		mutationFn: (id) => apiDeleteBoard(id),
		onSettled: () => queryClient.invalidateQueries({ queryKey: ['boards'] }),
	})

	return {
		...query,
		createBoard,
		updateBoard,
		deleteBoard,
	}
}
