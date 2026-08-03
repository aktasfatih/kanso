// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/kanso' + path)

// Boards
export const fetchBoards = () =>
	axios.get(url('/api/boards')).then((r) => r.data)

export const fetchBoard = (id) =>
	axios.get(url(`/api/boards/${id}`)).then((r) => r.data)

export const createBoard = (data) =>
	axios.post(url('/api/boards'), data).then((r) => r.data)

export const updateBoard = (id, data) =>
	axios.patch(url(`/api/boards/${id}`), data).then((r) => r.data)

export const deleteBoard = (id) =>
	axios.delete(url(`/api/boards/${id}`)).then((r) => r.data)

// Resolve a board-scoped PREFIX-<board_seq> human reference (e.g. "KAN-123") to
// { cardId, title } (#3611). Used to open a card by its human id from a URL when
// the board cache is not already loaded; the in-app renderer resolves from cache
// without a request. Rejects (404) for an unknown/mismatched reference.
export const resolveCardRef = (boardId, ref) =>
	axios.get(url(`/api/boards/${boardId}/cards/by-ref/${encodeURIComponent(ref)}`)).then((r) => r.data)

// Stacks
export const createStack = (data) =>
	axios.post(url('/api/stacks'), data).then((r) => r.data)

export const updateStack = (id, data) =>
	axios.patch(url(`/api/stacks/${id}`), data).then((r) => r.data)

export const deleteStack = (id) =>
	axios.delete(url(`/api/stacks/${id}`)).then((r) => r.data)

export const moveStack = (id, afterStackId) =>
	axios.post(url(`/api/stacks/${id}/move`), { afterStackId: afterStackId ?? null }).then((r) => r.data)

// Cards
export const fetchCard = (id) =>
	axios.get(url(`/api/cards/${id}`)).then((r) => r.data)

export const createCard = (data) =>
	axios.post(url('/api/cards'), data).then((r) => r.data)

export const updateCard = (id, data) =>
	axios.patch(url(`/api/cards/${id}`), data).then((r) => r.data)

export const deleteCard = (id) =>
	axios.delete(url(`/api/cards/${id}`)).then((r) => r.data)

export const moveCard = (id, data) =>
	axios.post(url(`/api/cards/${id}/move`), data).then((r) => r.data)

export const copyCard = (id, targetStackId) =>
	axios.post(url(`/api/cards/${id}/copy`), { targetStackId }).then((r) => r.data)

// Bulk (multi-select) card actions (#3523). One fixed action applied to a list
// of card ids server-side; returns { ok: [...], skipped: [{id, reason}] }.
export const bulkApplyCards = (cardIds, action, params = {}) =>
	axios.post(url('/api/cards/bulk'), { cardIds, action, params }).then((r) => r.data)

// Labels
export const createLabel = (data) =>
	axios.post(url('/api/labels'), data).then((r) => r.data)

export const updateLabel = (id, data) =>
	axios.patch(url(`/api/labels/${id}`), data).then((r) => r.data)

export const deleteLabel = (id) =>
	axios.delete(url(`/api/labels/${id}`)).then((r) => r.data)

export const assignLabel = (cardId, labelId) =>
	axios.put(url(`/api/cards/${cardId}/labels/${labelId}`)).then((r) => r.data)

export const unassignLabel = (cardId, labelId) =>
	axios.delete(url(`/api/cards/${cardId}/labels/${labelId}`)).then((r) => r.data)

// Assignees
export const fetchParticipants = (boardId) =>
	axios.get(url(`/api/boards/${boardId}/participants`)).then((r) => r.data)

export const assignUser = (cardId, userId) =>
	axios.put(url(`/api/cards/${cardId}/assignees/${userId}`)).then((r) => r.data)

export const unassignUser = (cardId, userId) =>
	axios.delete(url(`/api/cards/${cardId}/assignees/${userId}`)).then((r) => r.data)

// ACL (board sharing)
export const searchSharees = (boardId, q) =>
	axios.get(url(`/api/boards/${boardId}/acl/search`), { params: { q } }).then((r) => r.data)

export const createAcl = (boardId, data) =>
	axios.post(url(`/api/boards/${boardId}/acl`), data).then((r) => r.data)

export const updateAcl = (boardId, aclId, permission) =>
	axios.patch(url(`/api/boards/${boardId}/acl/${aclId}`), { permission }).then((r) => r.data)

export const deleteAcl = (boardId, aclId) =>
	axios.delete(url(`/api/boards/${boardId}/acl/${aclId}`)).then((r) => r.data)

// Archive rules
export const fetchArchiveRules = (boardId) =>
	axios.get(url(`/api/boards/${boardId}/archive-rules`)).then((r) => r.data)

export const createArchiveRule = (boardId, data) =>
	axios.post(url(`/api/boards/${boardId}/archive-rules`), data).then((r) => r.data)

export const updateArchiveRule = (id, data) =>
	axios.patch(url(`/api/archive-rules/${id}`), data).then((r) => r.data)

export const deleteArchiveRule = (id) =>
	axios.delete(url(`/api/archive-rules/${id}`)).then((r) => r.data)

export const archiveNow = (id) =>
	axios.post(url(`/api/archive-rules/${id}/archive-now`)).then((r) => r.data)

// Recur rules
export const fetchRecurRules = (boardId) =>
	axios.get(url(`/api/boards/${boardId}/recur-rules`)).then((r) => r.data)

export const createRecurRule = (boardId, data) =>
	axios.post(url(`/api/boards/${boardId}/recur-rules`), data).then((r) => r.data)

export const updateRecurRule = (id, data) =>
	axios.patch(url(`/api/recur-rules/${id}`), data).then((r) => r.data)

export const deleteRecurRule = (id) =>
	axios.delete(url(`/api/recur-rules/${id}`)).then((r) => r.data)

export const createNowRecurRule = (id) =>
	axios.post(url(`/api/recur-rules/${id}/create-now`)).then((r) => r.data)

// Automation rules
export const fetchAutomationRules = (boardId) =>
	axios.get(url(`/api/boards/${boardId}/automation-rules`)).then((r) => r.data)

export const createAutomationRule = (boardId, data) =>
	axios.post(url(`/api/boards/${boardId}/automation-rules`), data).then((r) => r.data)

export const setAutomationRuleEnabled = (id, enabled) =>
	axios.patch(url(`/api/automation-rules/${id}`), { enabled }).then((r) => r.data)

export const deleteAutomationRule = (id) =>
	axios.delete(url(`/api/automation-rules/${id}`)).then((r) => r.data)

// Parent / child hierarchy
export const setCardParent = (cardId, parentCardId) =>
	axios.put(url(`/api/cards/${cardId}/parent`), { parentCardId: parentCardId ?? null }).then((r) => r.data)

// Card relations (blocks / blocked-by / duplicates / relates)
export const addCardRelation = (cardId, otherCardId, kind) =>
	axios.post(url(`/api/cards/${cardId}/relations`), { otherCardId, kind }).then((r) => r.data)

export const removeCardRelation = (cardId, relationId) =>
	axios.delete(url(`/api/cards/${cardId}/relations/${relationId}`)).then((r) => r.data)

// Checklist
export const fetchChecklist = (cardId) =>
	axios.get(url(`/api/cards/${cardId}/checklist`)).then((r) => r.data)

export const createChecklistItem = (cardId, data) =>
	axios.post(url(`/api/cards/${cardId}/checklist`), data).then((r) => r.data)

export const updateChecklistItem = (itemId, data) =>
	axios.patch(url(`/api/checklist/${itemId}`), data).then((r) => r.data)

export const moveChecklistItem = (itemId, afterItemId) =>
	axios.post(url(`/api/checklist/${itemId}/move`), { afterItemId: afterItemId ?? null }).then((r) => r.data)

export const deleteChecklistItem = (itemId) =>
	axios.delete(url(`/api/checklist/${itemId}`)).then((r) => r.data)

// Trash
export const fetchTrash = (boardId) =>
	axios.get(url(`/api/boards/${boardId}/trash`)).then((r) => r.data)

export const restoreCard = (cardId) =>
	axios.post(url(`/api/cards/${cardId}/restore`)).then((r) => r.data)

export const restoreStack = (stackId) =>
	axios.post(url(`/api/stacks/${stackId}/restore`)).then((r) => r.data)

export const purgeCard = (cardId) =>
	axios.delete(url(`/api/cards/${cardId}/purge`)).then((r) => r.data)

// Comments
export const fetchComments = (cardId) =>
	axios.get(url(`/api/cards/${cardId}/comments`)).then((r) => r.data)

export const createComment = (cardId, data) =>
	axios.post(url(`/api/cards/${cardId}/comments`), data).then((r) => r.data)

export const updateComment = (commentId, data) =>
	axios.patch(url(`/api/comments/${commentId}`), data).then((r) => r.data)

export const deleteComment = (commentId) =>
	axios.delete(url(`/api/comments/${commentId}`)).then((r) => r.data)

// Emoji reactions on comments (#3550) — idempotent toggle. The emoji is a
// multi-byte value, so it is URL-encoded into the path segment. React=PUT,
// unreact=DELETE.
export const reactToComment = (commentId, emoji) =>
	axios.put(url(`/api/comments/${commentId}/reactions/${encodeURIComponent(emoji)}`)).then((r) => r.data)

export const unreactToComment = (commentId, emoji) =>
	axios.delete(url(`/api/comments/${commentId}/reactions/${encodeURIComponent(emoji)}`)).then((r) => r.data)

// Subscriptions (card watchers)
export const subscribeCard = (cardId) =>
	axios.put(url(`/api/cards/${cardId}/subscription`)).then((r) => r.data)

export const unsubscribeCard = (cardId) =>
	axios.delete(url(`/api/cards/${cardId}/subscription`)).then((r) => r.data)

// Add / remove ANOTHER board participant as a watcher (EDIT-gated on the server).
export const subscribeWatcher = (cardId, userId) =>
	axios.put(url(`/api/cards/${cardId}/subscription/${userId}`)).then((r) => r.data)

export const unsubscribeWatcher = (cardId, userId) =>
	axios.delete(url(`/api/cards/${cardId}/subscription/${userId}`)).then((r) => r.data)

// Card links (GitHub PR/issue attachments)
export const fetchCardLinks = (cardId) =>
	axios.get(url(`/api/cards/${cardId}/links`)).then((r) => r.data)

export const addCardLink = (cardId, linkUrl) =>
	axios.post(url(`/api/cards/${cardId}/links`), { url: linkUrl }).then((r) => r.data)

export const deleteCardLink = (cardId, linkId) =>
	axios.delete(url(`/api/cards/${cardId}/links/${linkId}`)).then((r) => r.data)

// Card file attachments (#3526). Bytes live in Kanso's app-data, gated by
// board permission; metadata only over JSON, bytes via the download URL.
export const fetchCardAttachments = (cardId) =>
	axios.get(url(`/api/cards/${cardId}/attachments`)).then((r) => r.data)

export const uploadCardAttachment = (cardId, file) => {
	const form = new FormData()
	form.append('file', file)
	return axios.post(url(`/api/cards/${cardId}/attachments`), form, {
		headers: { 'Content-Type': 'multipart/form-data' },
	}).then((r) => r.data)
}

export const deleteCardAttachment = (cardId, attachmentId) =>
	axios.delete(url(`/api/cards/${cardId}/attachments/${attachmentId}`)).then((r) => r.data)

// Direct download URL (browser navigates to it; server sets
// Content-Disposition: attachment). Board-READ gated server-side.
export const cardAttachmentUrl = (cardId, attachmentId) =>
	url(`/api/cards/${cardId}/attachments/${attachmentId}`)

// INLINE image URL (#3525). Server serves Content-Disposition: inline ONLY for
// the raster-image allow-list (png/jpeg/gif/webp), everything else 404s. Used
// as the src of an embedded pasted image; the markdown sanitiser only permits
// <img> whose src matches exactly this path shape (see services/markdown.js).
export const cardAttachmentInlineUrl = (cardId, attachmentId) =>
	url(`/api/cards/${cardId}/attachments/${attachmentId}/inline`)

// Deck import
export const fetchDeckImportBoards = () =>
	axios.get(url('/api/deck-import/boards')).then((r) => r.data)

export const importDeckBoard = (deckBoardId) =>
	axios.post(url(`/api/deck-import/boards/${deckBoardId}`)).then((r) => r.data)

// Full-board portability (Kanso's own round-trippable JSON format)
export const exportBoard = (boardId) =>
	axios.get(url(`/api/boards/${boardId}/export`)).then((r) => r.data)

// `document` is the raw export-file text; the server parses + validates it.
export const importBoard = (document) =>
	axios.post(url('/api/boards/import'), { document }).then((r) => r.data)

// Server-side duplicate of a board the caller can READ into a fresh board they
// own (export→import in-process). `withCards` also clones the card graph.
export const duplicateBoard = (boardId, withCards) =>
	axios.post(url(`/api/boards/${boardId}/duplicate`), { withCards: !!withCards }).then((r) => r.data)

// Trello board JSON import; `document` is the raw uploaded Trello export text.
export const importTrelloBoard = (document) =>
	axios.post(url('/api/trello-import'), { document }).then((r) => r.data)

// GitHub webhook config (board-level, MANAGE)
export const fetchWebhookConfig = (boardId) =>
	axios.get(url(`/api/boards/${boardId}/webhook`)).then((r) => r.data)

export const rotateWebhookSecret = (boardId) =>
	axios.post(url(`/api/boards/${boardId}/webhook/rotate`)).then((r) => r.data)

export const disableWebhook = (boardId) =>
	axios.delete(url(`/api/boards/${boardId}/webhook`)).then((r) => r.data)

// Public / read-only board share link (board-level, MANAGE)
export const fetchPublicShareConfig = (boardId) =>
	axios.get(url(`/api/boards/${boardId}/public-share`)).then((r) => r.data)

// Enable or rotate (both mint a fresh token, invalidating any old link).
export const enablePublicShare = (boardId) =>
	axios.post(url(`/api/boards/${boardId}/public-share`)).then((r) => r.data)

export const disablePublicShare = (boardId) =>
	axios.delete(url(`/api/boards/${boardId}/public-share`)).then((r) => r.data)

// Subscriptions (board watchers)
export const subscribeBoard = (boardId) =>
	axios.put(url(`/api/boards/${boardId}/subscription`)).then((r) => r.data)

export const unsubscribeBoard = (boardId) =>
	axios.delete(url(`/api/boards/${boardId}/subscription`)).then((r) => r.data)

// Search
export const search = ({ q, boardId, limit = 25, offset = 0 }) =>
	axios.get(url('/api/search'), { params: { q, boardId, limit, offset } }).then((r) => r.data)

// Reviews
export const getMyReviews = () =>
	axios.get(url('/api/reviews/mine')).then((r) => r.data)

export const requestReview = (cardId, userId, reviewTypeId) =>
	axios.put(url(`/api/cards/${cardId}/reviews/${userId}`), reviewTypeId != null ? { reviewTypeId } : undefined).then((r) => r.data)

export const withdrawReview = (cardId, reviewId) =>
	axios.delete(url(`/api/cards/${cardId}/reviews/${reviewId}`)).then((r) => r.data)

export const setReviewState = (cardId, reviewId, state, reason = null) =>
	axios.patch(url(`/api/cards/${cardId}/reviews/${reviewId}`),
		reason != null && reason !== '' ? { state, reason } : { state },
	).then((r) => r.data)

// Inbox (activity feed - comments on watched cards by others)
export const getInbox = () =>
	axios.get(url('/api/inbox')).then((r) => r.data)

// My tasks (open cards assigned to me, across every board I can read)
export const getMyCards = () =>
	axios.get(url('/api/my-cards')).then((r) => r.data)

// Review types
export const createReviewType = (boardId, title, color) =>
	axios.post(url('/api/review-types'), { boardId, title, color: color || null }).then((r) => r.data)

export const updateReviewType = (id, data) =>
	axios.patch(url(`/api/review-types/${id}`), data).then((r) => r.data)

export const deleteReviewType = (id) =>
	axios.delete(url(`/api/review-types/${id}`)).then((r) => r.data)

// Per-card activity feed (read-only view over the change log)
export const getCardActivity = (cardId) =>
	axios.get(url(`/api/cards/${cardId}/activity`)).then((r) => r.data)

// Per-user settings (default board on start, …)
export const getSettings = () =>
	axios.get(url('/api/settings')).then((r) => r.data)

export const updateSettings = (data) =>
	axios.put(url('/api/settings'), data).then((r) => r.data)

// Per-user board folders / groups in the nav (#3529). FLAT, one-level,
// per-user; distinct from Projects. A board's group is surfaced on the
// board-list payload (`groupId`); these endpoints manage the folders themselves.
export const fetchBoardGroups = () =>
	axios.get(url('/api/board-groups')).then((r) => r.data)

export const createBoardGroup = (name) =>
	axios.post(url('/api/board-groups'), { name }).then((r) => r.data)

export const renameBoardGroup = (id, name) =>
	axios.patch(url(`/api/board-groups/${id}`), { name }).then((r) => r.data)

export const deleteBoardGroup = (id) =>
	axios.delete(url(`/api/board-groups/${id}`)).then((r) => r.data)

export const reorderBoardGroups = (groupIds) =>
	axios.post(url('/api/board-groups/reorder'), { groupIds }).then((r) => r.data)

export const assignBoardToGroup = (groupId, boardId) =>
	axios.put(url(`/api/board-groups/${groupId}/boards/${boardId}`)).then((r) => r.data)

export const unassignBoardFromGroup = (boardId) =>
	axios.delete(url(`/api/board-groups/boards/${boardId}`)).then((r) => r.data)

// Saved filter views (#3407) — per-user, per-board named filter snapshots.
export const fetchSavedFilters = (boardId) =>
	axios.get(url(`/api/boards/${boardId}/saved-filters`)).then((r) => r.data)

export const saveSavedFilter = (boardId, name, filter) =>
	axios.put(url(`/api/boards/${boardId}/saved-filters`), { name, filter }).then((r) => r.data)

export const deleteSavedFilter = (boardId, name) =>
	axios.delete(url(`/api/boards/${boardId}/saved-filters/${encodeURIComponent(name)}`)).then((r) => r.data)

// Projects (cross-board card collections)
export const getProjects = () =>
	axios.get(url('/api/projects')).then((r) => r.data)

export const createProject = (data) =>
	axios.post(url('/api/projects'), data).then((r) => r.data)

export const updateProject = (id, data) =>
	axios.patch(url(`/api/projects/${id}`), data).then((r) => r.data)

export const deleteProject = (id) =>
	axios.delete(url(`/api/projects/${id}`)).then((r) => r.data)

export const getProjectCards = (id) =>
	axios.get(url(`/api/projects/${id}/cards`)).then((r) => r.data)

export const addCardToProject = (projectId, cardId) =>
	axios.put(url(`/api/projects/${projectId}/cards/${cardId}`)).then((r) => r.data)

export const removeCardFromProject = (projectId, cardId) =>
	axios.delete(url(`/api/projects/${projectId}/cards/${cardId}`)).then((r) => r.data)

// Cross-board project analytics (owner-only)
export const getProjectStats = (projectId) =>
	axios.get(url(`/api/projects/${projectId}/stats`)).then((r) => r.data)

// Project comments — an owner-only personal discussion log on a project (#3563).
// One-level threads, mirroring the card-comment API but project-scoped. The
// update/delete paths are /api/project-comments/{id} (distinct from card
// comments' /api/comments/{id}).
export const fetchProjectComments = (projectId) =>
	axios.get(url(`/api/projects/${projectId}/comments`)).then((r) => r.data)

export const createProjectComment = (projectId, data) =>
	axios.post(url(`/api/projects/${projectId}/comments`), data).then((r) => r.data)

export const updateProjectComment = (commentId, data) =>
	axios.patch(url(`/api/project-comments/${commentId}`), data).then((r) => r.data)

export const deleteProjectComment = (commentId) =>
	axios.delete(url(`/api/project-comments/${commentId}`)).then((r) => r.data)

// Board analytics
export const getBoardStats = (boardId) =>
	axios.get(url(`/api/boards/${boardId}/stats`)).then((r) => r.data)
