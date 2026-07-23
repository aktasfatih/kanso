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
