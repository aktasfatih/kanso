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

// Subscriptions (card watchers)
export const subscribeCard = (cardId) =>
	axios.put(url(`/api/cards/${cardId}/subscription`)).then((r) => r.data)

export const unsubscribeCard = (cardId) =>
	axios.delete(url(`/api/cards/${cardId}/subscription`)).then((r) => r.data)

// Card links (GitHub PR/issue attachments)
export const fetchCardLinks = (cardId) =>
	axios.get(url(`/api/cards/${cardId}/links`)).then((r) => r.data)

export const addCardLink = (cardId, linkUrl) =>
	axios.post(url(`/api/cards/${cardId}/links`), { url: linkUrl }).then((r) => r.data)

export const deleteCardLink = (cardId, linkId) =>
	axios.delete(url(`/api/cards/${cardId}/links/${linkId}`)).then((r) => r.data)

// Deck import
export const fetchDeckImportBoards = () =>
	axios.get(url('/api/deck-import/boards')).then((r) => r.data)

export const importDeckBoard = (deckBoardId) =>
	axios.post(url(`/api/deck-import/boards/${deckBoardId}`)).then((r) => r.data)

// GitHub webhook config (board-level, MANAGE)
export const fetchWebhookConfig = (boardId) =>
	axios.get(url(`/api/boards/${boardId}/webhook`)).then((r) => r.data)

export const rotateWebhookSecret = (boardId) =>
	axios.post(url(`/api/boards/${boardId}/webhook/rotate`)).then((r) => r.data)

export const disableWebhook = (boardId) =>
	axios.delete(url(`/api/boards/${boardId}/webhook`)).then((r) => r.data)

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

// Inbox (activity feed — comments on watched cards by others)
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
