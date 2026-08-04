<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Public / read-only board share (#3531). UNAUTHENTICATED: `/p/{token}`
		// renders the read-only SPA shell and `/api/public/{token}` returns the
		// STRIPPED payload. Both are #[PublicPage] + brute-force throttled; a bad
		// token is a 404. The {token} is an opaque 64-char alnum string.
		['name' => 'publicShare#show', 'url' => '/p/{token}', 'verb' => 'GET'],
		['name' => 'publicShare#data', 'url' => '/api/public/{token}', 'verb' => 'GET'],

		// Read-only iCal / ICS feed of a board's card due dates (#3541).
		// UNAUTHENTICATED: `/feed/{token}.ics` returns a `text/calendar` VCALENDAR
		// with one VEVENT per due card. #[PublicPage] + brute-force throttled; a bad
		// token is a 404. The {token} is an opaque 64-char alnum string; the `.ics`
		// suffix lets calendar clients recognise the URL by extension.
		['name' => 'calendarFeed#feed', 'url' => '/feed/{token}.ics', 'verb' => 'GET'],

		['name' => 'search#index', 'url' => '/api/search', 'verb' => 'GET'],

		['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],

		// Per-user, per-board saved filter views (#3407). Personal preference in
		// user config, keyed by board id; the {name} path segment identifies a
		// view for deletion (URL-encoded by the client).
		['name' => 'savedFilter#index', 'url' => '/api/boards/{boardId}/saved-filters', 'verb' => 'GET'],
		['name' => 'savedFilter#create', 'url' => '/api/boards/{boardId}/saved-filters', 'verb' => 'PUT'],
		['name' => 'savedFilter#destroy', 'url' => '/api/boards/{boardId}/saved-filters/{name}', 'verb' => 'DELETE'],

		// Admin-only backup config + on-demand run (#3615). No #[NoAdminRequired]
		// on the controller, so Nextcloud gates these to admins.
		['name' => 'backupAdmin#index', 'url' => '/api/admin/backup', 'verb' => 'GET'],
		['name' => 'backupAdmin#update', 'url' => '/api/admin/backup', 'verb' => 'PUT'],
		['name' => 'backupAdmin#run', 'url' => '/api/admin/backup/run', 'verb' => 'POST'],

		['name' => 'board#index', 'url' => '/api/boards', 'verb' => 'GET'],
		['name' => 'board#create', 'url' => '/api/boards', 'verb' => 'POST'],

		// Per-user board folders / groups in the nav (#3529). FLAT, one-level,
		// per-user - personal nav organization, distinct from Projects. All
		// literal /board-groups segments, declared before the /api/boards/{id}
		// CRUD so the router never captures "board-groups" as a board id.
		['name' => 'boardGroup#index', 'url' => '/api/board-groups', 'verb' => 'GET'],
		['name' => 'boardGroup#create', 'url' => '/api/board-groups', 'verb' => 'POST'],
		['name' => 'boardGroup#reorder', 'url' => '/api/board-groups/reorder', 'verb' => 'POST'],
		['name' => 'boardGroup#rename', 'url' => '/api/board-groups/{id}', 'verb' => 'PATCH'],
		['name' => 'boardGroup#destroy', 'url' => '/api/board-groups/{id}', 'verb' => 'DELETE'],
		['name' => 'boardGroup#assign', 'url' => '/api/board-groups/{id}/boards/{boardId}', 'verb' => 'PUT'],
		// Unfile a board from whatever folder it's in - not nested under a folder
		// id (the caller need not know which folder holds it).
		['name' => 'boardGroup#unassign', 'url' => '/api/board-groups/boards/{boardId}', 'verb' => 'DELETE'],
		// Full-board portability. The literal /import segment is a distinct POST
		// path (board#show is GET {id}), and export is nested under an existing
		// board id - neither collides with the CRUD routes above.
		['name' => 'boardPortability#import', 'url' => '/api/boards/import', 'verb' => 'POST'],
		['name' => 'boardPortability#export', 'url' => '/api/boards/{id}/export', 'verb' => 'GET'],
		// Server-side board duplicate (export→import in-process) into a fresh
		// board owned by the caller. Nested under an existing board id, distinct
		// from board#show (GET {id}).
		['name' => 'boardPortability#duplicate', 'url' => '/api/boards/{id}/duplicate', 'verb' => 'POST'],
		// Trello board JSON import → a fresh Kanso board owned by the importer.
		// A distinct literal POST path, alongside boardPortability#import above.
		['name' => 'trelloImport#import', 'url' => '/api/trello-import', 'verb' => 'POST'],
		['name' => 'board#show', 'url' => '/api/boards/{id}', 'verb' => 'GET'],
		['name' => 'boardStats#show', 'url' => '/api/boards/{id}/stats', 'verb' => 'GET'],
		['name' => 'board#participants', 'url' => '/api/boards/{id}/participants', 'verb' => 'GET'],
		// Contact picker data source (#3530): searches the user's address books.
		// Returns [] when the optional Contacts app is disabled.
		['name' => 'board#contacts', 'url' => '/api/boards/{id}/contacts', 'verb' => 'GET'],
		// Resolve a board-scoped PREFIX-<board_seq> human reference (e.g. KAN-123)
		// to a card {cardId, title} - opens a card by its human id from a URL and
		// backs the markdown cross-reference renderer (#3611). The literal
		// /cards/by-ref segment is distinct from every card CRUD path.
		['name' => 'card#resolveRef', 'url' => '/api/boards/{id}/cards/by-ref/{ref}', 'verb' => 'GET'],
		// Per-board template picker (#3409): the board's template cards, which the
		// live board render excludes. Literal /cards/templates, distinct from the
		// card CRUD paths.
		['name' => 'card#templates', 'url' => '/api/boards/{id}/cards/templates', 'verb' => 'GET'],
		['name' => 'board#update', 'url' => '/api/boards/{id}', 'verb' => 'PATCH'],
		['name' => 'board#destroy', 'url' => '/api/boards/{id}', 'verb' => 'DELETE'],

		// The literal /search segment must precede the {aclId} routes so the
		// router never captures "search" as an id.
		['name' => 'acl#search', 'url' => '/api/boards/{id}/acl/search', 'verb' => 'GET'],
		['name' => 'acl#create', 'url' => '/api/boards/{id}/acl', 'verb' => 'POST'],
		['name' => 'acl#update', 'url' => '/api/boards/{id}/acl/{aclId}', 'verb' => 'PATCH'],
		['name' => 'acl#destroy', 'url' => '/api/boards/{id}/acl/{aclId}', 'verb' => 'DELETE'],

		['name' => 'stack#create', 'url' => '/api/stacks', 'verb' => 'POST'],
		['name' => 'stack#update', 'url' => '/api/stacks/{id}', 'verb' => 'PATCH'],
		['name' => 'stack#destroy', 'url' => '/api/stacks/{id}', 'verb' => 'DELETE'],
		['name' => 'stack#restore', 'url' => '/api/stacks/{id}/restore', 'verb' => 'POST'],
		['name' => 'stack#move', 'url' => '/api/stacks/{id}/move', 'verb' => 'POST'],

		['name' => 'trash#index', 'url' => '/api/boards/{id}/trash', 'verb' => 'GET'],
		['name' => 'trash#restore', 'url' => '/api/cards/{id}/restore', 'verb' => 'POST'],
		['name' => 'trash#purge', 'url' => '/api/cards/{id}/purge', 'verb' => 'DELETE'],

		['name' => 'card#create', 'url' => '/api/cards', 'verb' => 'POST'],
		// Bulk (multi-select) card actions (#3523). One fixed action applied to a
		// list of card ids, looping the existing per-card services (board ACL per
		// card, each mutation appends kanso_changes). The literal /cards/bulk
		// segment is declared before the numeric card CRUD routes so the router
		// never captures "bulk" as a card id.
		['name' => 'bulkCard#apply', 'url' => '/api/cards/bulk', 'verb' => 'POST'],
		['name' => 'card#show', 'url' => '/api/cards/{id}', 'verb' => 'GET'],
		['name' => 'card#update', 'url' => '/api/cards/{id}', 'verb' => 'PATCH'],
		['name' => 'card#destroy', 'url' => '/api/cards/{id}', 'verb' => 'DELETE'],
		['name' => 'card#move', 'url' => '/api/cards/{id}/move', 'verb' => 'POST'],
		['name' => 'card#copy', 'url' => '/api/cards/{id}/copy', 'verb' => 'POST'],
		// Per-board card templates (#3409). Flag/unflag a card as a template
		// (EDIT-gated), and create a new live card pre-filled from a template.
		['name' => 'card#setTemplate', 'url' => '/api/cards/{id}/template', 'verb' => 'PUT'],
		['name' => 'card#createFromTemplate', 'url' => '/api/cards/{id}/create-from-template', 'verb' => 'POST'],
		['name' => 'card#setParent', 'url' => '/api/cards/{id}/parent', 'verb' => 'PUT'],
		['name' => 'card#assignLabel', 'url' => '/api/cards/{id}/labels/{labelId}', 'verb' => 'PUT'],
		['name' => 'card#unassignLabel', 'url' => '/api/cards/{id}/labels/{labelId}', 'verb' => 'DELETE'],
		['name' => 'card#assignUser', 'url' => '/api/cards/{id}/assignees/{userId}', 'verb' => 'PUT'],
		['name' => 'card#unassignUser', 'url' => '/api/cards/{id}/assignees/{userId}', 'verb' => 'DELETE'],
		// Contact links carry a CardDAV URI (contains '/' and ':'), so it travels
		// in the body rather than the path.
		['name' => 'card#linkContact', 'url' => '/api/cards/{id}/contacts', 'verb' => 'POST'],
		['name' => 'card#unlinkContact', 'url' => '/api/cards/{id}/contacts', 'verb' => 'DELETE'],

		['name' => 'inbox#index', 'url' => '/api/inbox', 'verb' => 'GET'],

		['name' => 'myCards#index', 'url' => '/api/my-cards', 'verb' => 'GET'],

		['name' => 'review#mine', 'url' => '/api/reviews/mine', 'verb' => 'GET'],
		['name' => 'review#request', 'url' => '/api/cards/{id}/reviews/{userId}', 'verb' => 'PUT'],
		['name' => 'review#withdraw', 'url' => '/api/cards/{id}/reviews/{reviewId}', 'verb' => 'DELETE'],
		['name' => 'review#setState', 'url' => '/api/cards/{id}/reviews/{reviewId}', 'verb' => 'PATCH'],

		['name' => 'project#index', 'url' => '/api/projects', 'verb' => 'GET'],
		['name' => 'project#create', 'url' => '/api/projects', 'verb' => 'POST'],
		['name' => 'project#update', 'url' => '/api/projects/{id}', 'verb' => 'PATCH'],
		['name' => 'project#destroy', 'url' => '/api/projects/{id}', 'verb' => 'DELETE'],
		['name' => 'project#cards', 'url' => '/api/projects/{id}/cards', 'verb' => 'GET'],
		['name' => 'project#stats', 'url' => '/api/projects/{id}/stats', 'verb' => 'GET'],
		['name' => 'project#addCard', 'url' => '/api/projects/{id}/cards/{cardId}', 'verb' => 'PUT'],
		['name' => 'project#removeCard', 'url' => '/api/projects/{id}/cards/{cardId}', 'verb' => 'DELETE'],

		['name' => 'activity#index', 'url' => '/api/cards/{cardId}/activity', 'verb' => 'GET'],

		['name' => 'subscription#index', 'url' => '/api/cards/{cardId}/subscription', 'verb' => 'GET'],
		['name' => 'subscription#subscribe', 'url' => '/api/cards/{cardId}/subscription', 'verb' => 'PUT'],
		['name' => 'subscription#unsubscribe', 'url' => '/api/cards/{cardId}/subscription', 'verb' => 'DELETE'],
		['name' => 'subscription#subscribeOther', 'url' => '/api/cards/{cardId}/subscription/{userId}', 'verb' => 'PUT'],
		['name' => 'subscription#unsubscribeOther', 'url' => '/api/cards/{cardId}/subscription/{userId}', 'verb' => 'DELETE'],
		['name' => 'deckImport#index', 'url' => '/api/deck-import/boards', 'verb' => 'GET'],
		['name' => 'deckImport#import', 'url' => '/api/deck-import/boards/{deckBoardId}', 'verb' => 'POST'],

		// Public-share config (MANAGE, authenticated). Nested under a board id,
		// distinct from board#show. Enable/rotate = POST, disable = DELETE.
		['name' => 'publicShare#config', 'url' => '/api/boards/{id}/public-share', 'verb' => 'GET'],
		['name' => 'publicShare#enable', 'url' => '/api/boards/{id}/public-share', 'verb' => 'POST'],
		['name' => 'publicShare#disable', 'url' => '/api/boards/{id}/public-share', 'verb' => 'DELETE'],

		// Calendar-feed config (MANAGE, authenticated). Nested under a board id,
		// distinct from board#show. Enable/rotate = POST, disable = DELETE.
		['name' => 'calendarFeed#config', 'url' => '/api/boards/{id}/calendar-feed', 'verb' => 'GET'],
		['name' => 'calendarFeed#enable', 'url' => '/api/boards/{id}/calendar-feed', 'verb' => 'POST'],
		['name' => 'calendarFeed#disable', 'url' => '/api/boards/{id}/calendar-feed', 'verb' => 'DELETE'],

		// Per-user board pinning (#3632). Nested under a board id, distinct from
		// board#show. Pin = PUT (READ-gated), unpin = DELETE (own pin only).
		['name' => 'boardPin#pin', 'url' => '/api/boards/{id}/pin', 'verb' => 'PUT'],
		['name' => 'boardPin#unpin', 'url' => '/api/boards/{id}/pin', 'verb' => 'DELETE'],

		['name' => 'webhook#github', 'url' => '/api/boards/{id}/github-webhook', 'verb' => 'POST'],
		['name' => 'webhook#config', 'url' => '/api/boards/{id}/webhook', 'verb' => 'GET'],
		['name' => 'webhook#rotate', 'url' => '/api/boards/{id}/webhook/rotate', 'verb' => 'POST'],
		['name' => 'webhook#disable', 'url' => '/api/boards/{id}/webhook', 'verb' => 'DELETE'],

		['name' => 'cardLink#index', 'url' => '/api/cards/{cardId}/links', 'verb' => 'GET'],
		['name' => 'cardLink#create', 'url' => '/api/cards/{cardId}/links', 'verb' => 'POST'],
		['name' => 'cardLink#destroy', 'url' => '/api/cards/{cardId}/links/{linkId}', 'verb' => 'DELETE'],

		['name' => 'cardAttachment#index', 'url' => '/api/cards/{cardId}/attachments', 'verb' => 'GET'],
		['name' => 'cardAttachment#create', 'url' => '/api/cards/{cardId}/attachments', 'verb' => 'POST'],
		// "Share from Files" (#3645): copy a file from the actor's own Nextcloud
		// Files into the card. Distinct literal /from-file segment, declared before
		// the numeric {attachmentId} routes so the router never captures
		// "from-file" as an attachment id. Body: {fileId}. EDIT-gated server-side.
		['name' => 'cardAttachment#createFromFile', 'url' => '/api/cards/{cardId}/attachments/from-file', 'verb' => 'POST'],
		['name' => 'cardAttachment#download', 'url' => '/api/cards/{cardId}/attachments/{attachmentId}', 'verb' => 'GET'],
		// INLINE raster-image serve (#3525). Distinct trailing /inline segment,
		// board-READ gated + IDOR-guarded; only png/jpeg/gif/webp are served
		// Content-Disposition: inline (everything else 404s), so a pasted image
		// can be embedded in a description/comment without opening an XSS/SSRF hole.
		['name' => 'cardAttachment#inline', 'url' => '/api/cards/{cardId}/attachments/{attachmentId}/inline', 'verb' => 'GET'],
		['name' => 'cardAttachment#destroy', 'url' => '/api/cards/{cardId}/attachments/{attachmentId}', 'verb' => 'DELETE'],

		['name' => 'cardRelation#index', 'url' => '/api/cards/{cardId}/relations', 'verb' => 'GET'],
		['name' => 'cardRelation#create', 'url' => '/api/cards/{cardId}/relations', 'verb' => 'POST'],
		['name' => 'cardRelation#destroy', 'url' => '/api/cards/{cardId}/relations/{relationId}', 'verb' => 'DELETE'],

		['name' => 'subscription#boardIndex', 'url' => '/api/boards/{boardId}/subscription', 'verb' => 'GET'],
		['name' => 'subscription#boardSubscribe', 'url' => '/api/boards/{boardId}/subscription', 'verb' => 'PUT'],
		['name' => 'subscription#boardUnsubscribe', 'url' => '/api/boards/{boardId}/subscription', 'verb' => 'DELETE'],

		['name' => 'comment#index', 'url' => '/api/cards/{cardId}/comments', 'verb' => 'GET'],
		['name' => 'comment#create', 'url' => '/api/cards/{cardId}/comments', 'verb' => 'POST'],
		['name' => 'comment#update', 'url' => '/api/comments/{commentId}', 'verb' => 'PATCH'],
		['name' => 'comment#destroy', 'url' => '/api/comments/{commentId}', 'verb' => 'DELETE'],

		// Emoji reactions on comments (#3550): idempotent toggle. The {emoji}
		// segment is URL-encoded by the client (a multi-byte emoji); the service
		// validates it against a FIXED allowed set. React=PUT, unreact=DELETE.
		['name' => 'commentReaction#react', 'url' => '/api/comments/{commentId}/reactions/{emoji}', 'verb' => 'PUT'],
		['name' => 'commentReaction#unreact', 'url' => '/api/comments/{commentId}/reactions/{emoji}', 'verb' => 'DELETE'],

		// Project comments — an owner-only personal discussion log (#3563). The
		// update/destroy paths are scoped under /api/project-comments so they never
		// collide with the card-comment /api/comments/{commentId} routes above.
		['name' => 'projectComment#index', 'url' => '/api/projects/{projectId}/comments', 'verb' => 'GET'],
		['name' => 'projectComment#create', 'url' => '/api/projects/{projectId}/comments', 'verb' => 'POST'],
		['name' => 'projectComment#update', 'url' => '/api/project-comments/{commentId}', 'verb' => 'PATCH'],
		['name' => 'projectComment#destroy', 'url' => '/api/project-comments/{commentId}', 'verb' => 'DELETE'],

		['name' => 'checklist#index', 'url' => '/api/cards/{cardId}/checklist', 'verb' => 'GET'],
		['name' => 'checklist#create', 'url' => '/api/cards/{cardId}/checklist', 'verb' => 'POST'],
		['name' => 'checklist#update', 'url' => '/api/checklist/{itemId}', 'verb' => 'PATCH'],
		['name' => 'checklist#move', 'url' => '/api/checklist/{itemId}/move', 'verb' => 'POST'],
		['name' => 'checklist#destroy', 'url' => '/api/checklist/{itemId}', 'verb' => 'DELETE'],

		['name' => 'label#create', 'url' => '/api/labels', 'verb' => 'POST'],
		['name' => 'label#update', 'url' => '/api/labels/{id}', 'verb' => 'PATCH'],
		['name' => 'label#destroy', 'url' => '/api/labels/{id}', 'verb' => 'DELETE'],

		['name' => 'reviewType#create', 'url' => '/api/review-types', 'verb' => 'POST'],
		['name' => 'reviewType#update', 'url' => '/api/review-types/{id}', 'verb' => 'PATCH'],
		['name' => 'reviewType#destroy', 'url' => '/api/review-types/{id}', 'verb' => 'DELETE'],

		['name' => 'archiveRule#index', 'url' => '/api/boards/{id}/archive-rules', 'verb' => 'GET'],
		['name' => 'archiveRule#create', 'url' => '/api/boards/{id}/archive-rules', 'verb' => 'POST'],
		['name' => 'archiveRule#update', 'url' => '/api/archive-rules/{id}', 'verb' => 'PATCH'],
		['name' => 'archiveRule#destroy', 'url' => '/api/archive-rules/{id}', 'verb' => 'DELETE'],
		['name' => 'archiveRule#archiveNow', 'url' => '/api/archive-rules/{id}/archive-now', 'verb' => 'POST'],

		['name' => 'recurRule#index', 'url' => '/api/boards/{id}/recur-rules', 'verb' => 'GET'],
		['name' => 'recurRule#create', 'url' => '/api/boards/{id}/recur-rules', 'verb' => 'POST'],
		['name' => 'recurRule#update', 'url' => '/api/recur-rules/{id}', 'verb' => 'PATCH'],
		['name' => 'recurRule#destroy', 'url' => '/api/recur-rules/{id}', 'verb' => 'DELETE'],
		['name' => 'recurRule#createNow', 'url' => '/api/recur-rules/{id}/create-now', 'verb' => 'POST'],

		['name' => 'automation#index', 'url' => '/api/boards/{id}/automation-rules', 'verb' => 'GET'],
		['name' => 'automation#create', 'url' => '/api/boards/{id}/automation-rules', 'verb' => 'POST'],
		['name' => 'automation#setEnabled', 'url' => '/api/automation-rules/{ruleId}', 'verb' => 'PATCH'],
		['name' => 'automation#destroy', 'url' => '/api/automation-rules/{ruleId}', 'verb' => 'DELETE'],
	],
];
