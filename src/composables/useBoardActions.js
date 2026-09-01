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
 * boards-view tile menu (#3750): export to a downloadable .zip archive,
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

	// ── Export board to a downloadable .zip archive ──────────────────────────
	// The archive carries board.json plus every attachment the exporter may
	// see, so the response is opaque bytes — the server names the file in
	// Content-Disposition and we just save it.
	const exporting = ref(false)
	const exportError = ref('')

	/** Pulls `filename="…"` out of a Content-Disposition header, if it has one. */
	function filenameFromDisposition(disposition) {
		const match = /filename="([^"]+)"/.exec(disposition || '')
		return match ? match[1] : ''
	}

	/**
	 * A failed blob request carries the JSON error body as a Blob, so the usual
	 * `e.response.data.error` read finds nothing. Decode it back to text first.
	 */
	async function errorMessageFrom(e) {
		const data = e?.response?.data
		try {
			if (data instanceof Blob) {
				return JSON.parse(await data.text())?.error || ''
			}
		} catch {
			// Not a JSON error body — fall through to the generic message.
		}
		return data?.error || ''
	}

	async function exportBoardToFile(boardId) {
		exporting.value = true
		exportError.value = ''
		try {
			const res = await apiExportBoard(boardId)
			const blob = res.data instanceof Blob
				? res.data
				: new Blob([res.data], { type: 'application/zip' })
			const name = filenameFromDisposition(res.headers?.['content-disposition']) || 'kanso-board.zip'
			const href = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = href
			a.download = name
			document.body.appendChild(a)
			a.click()
			a.remove()
			URL.revokeObjectURL(href)
			return true
		} catch (e) {
			exportError.value = (await errorMessageFrom(e)) || t('kanso', 'Could not export this board.')
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
