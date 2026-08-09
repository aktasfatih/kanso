// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ref } from 'vue'
import { useQueryClient } from '@tanstack/vue-query'
import { translate as t } from '@nextcloud/l10n'
import {
	exportBoard as apiExportBoard,
	duplicateBoard as apiDuplicateBoard,
} from '../services/api.js'
import { useBoards } from './useBoards.js'

/**
 * Heavyweight per-board actions shared by BoardSettingsModal and the
 * boards-view tile menu (#3750): export to a downloadable .json file,
 * server-side duplicate, and (soft) delete.
 *
 * Each action resolves with the API result on success, or resolves falsy after
 * writing the matching error ref on failure — it never throws. Navigation and
 * close behaviour stay with the caller (the modal navigates, the tile menu
 * stays on the boards page).
 */
export function useBoardActions() {
	const queryClient = useQueryClient()
	const { deleteBoard: deleteBoardMutation } = useBoards()

	// ── Export board to a downloadable .json file ────────────────────────────
	const exporting = ref(false)
	const exportError = ref('')
	async function exportBoardToFile(boardId) {
		exporting.value = true
		exportError.value = ''
		try {
			const doc = await apiExportBoard(boardId)
			const title = (doc?.board?.title || 'board')
				.replace(/[^\w.-]+/g, '-')
				.replace(/^-+|-+$/g, '') || 'board'
			const blob = new Blob([JSON.stringify(doc, null, 2)], { type: 'application/json' })
			const href = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = href
			a.download = `kanso-${title}.json`
			document.body.appendChild(a)
			a.click()
			a.remove()
			URL.revokeObjectURL(href)
			return doc
		} catch (e) {
			exportError.value = e?.response?.data?.error || t('kanso', 'Could not export this board.')
			return null
		} finally {
			exporting.value = false
		}
	}

	// ── Server-side duplicate into a fresh board the caller owns ─────────────
	const duplicating = ref(false)
	const duplicateError = ref('')
	async function duplicateBoardNow(boardId, withCards) {
		duplicating.value = true
		duplicateError.value = ''
		try {
			const res = await apiDuplicateBoard(boardId, withCards)
			await queryClient.invalidateQueries({ queryKey: ['boards'] })
			return res
		} catch (e) {
			duplicateError.value = e?.response?.data?.error || t('kanso', 'Could not duplicate this board.')
			return null
		} finally {
			duplicating.value = false
		}
	}

	// ── Delete (soft server-side; the UI treats it as destructive) ───────────
	const isDeletingBoard = ref(false)
	const deleteBoardError = ref('')
	async function deleteBoardNow(boardId) {
		isDeletingBoard.value = true
		deleteBoardError.value = ''
		try {
			await deleteBoardMutation.mutateAsync(Number(boardId))
			return true
		} catch (e) {
			deleteBoardError.value = e?.response?.data?.error || t('kanso', 'Could not delete the board.')
			return false
		} finally {
			isDeletingBoard.value = false
		}
	}

	return {
		exporting,
		exportError,
		exportBoardToFile,
		duplicating,
		duplicateError,
		duplicateBoardNow,
		isDeletingBoard,
		deleteBoardError,
		deleteBoardNow,
	}
}
