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
