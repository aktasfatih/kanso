// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	getProjects as apiGetProjects,
	createProject as apiCreateProject,
	updateProject as apiUpdateProject,
	deleteProject as apiDeleteProject,
} from '../services/api.js'

/**
 * Composable for the projects list — create / update / delete mutations
 * all invalidate ['projects'] so every consumer stays in sync.
 */
export function useProjects() {
	const queryClient = useQueryClient()

	const query = useQuery({
		queryKey: ['projects'],
		queryFn: apiGetProjects,
	})

	const create = useMutation({
		mutationFn: (data) => apiCreateProject(data),
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: ['projects'] })
		},
	})

	const update = useMutation({
		mutationFn: ({ id, ...data }) => apiUpdateProject(id, data),
		onMutate: async ({ id, ...data }) => {
			await queryClient.cancelQueries({ queryKey: ['projects'] })
			const previous = queryClient.getQueryData(['projects'])
			queryClient.setQueryData(['projects'], (old) => {
				if (!Array.isArray(old)) return old
				return old.map((p) => p.id === id ? { ...p, ...data } : p)
			})
			return { previous }
		},
		onError: (_err, _vars, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(['projects'], context.previous)
			}
		},
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: ['projects'] })
		},
	})

	const remove = useMutation({
		mutationFn: (id) => apiDeleteProject(id),
		onMutate: async (id) => {
			await queryClient.cancelQueries({ queryKey: ['projects'] })
			const previous = queryClient.getQueryData(['projects'])
			queryClient.setQueryData(['projects'], (old) => {
				if (!Array.isArray(old)) return old
				return old.filter((p) => p.id !== id)
			})
			return { previous }
		},
		onError: (_err, _vars, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(['projects'], context.previous)
			}
		},
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: ['projects'] })
		},
	})

	return {
		...query,
		create,
		update,
		remove,
	}
}
