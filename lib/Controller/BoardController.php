<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardRelationMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ParticipantService;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class BoardController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private BoardService $boardService,
		private ParticipantService $participantService,
		private ChangeMapper $changeMapper,
		private StackMapper $stackMapper,
		private CardMapper $cardMapper,
		private LabelMapper $labelMapper,
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private CardReviewMapper $cardReviewMapper,
		private ReviewTypeMapper $reviewTypeMapper,
		private ChecklistItemMapper $checklistItemMapper,
		private CommentMapper $commentMapper,
		private AclMapper $aclMapper,
		private PermissionService $permissionService,
		private SubscriptionService $subscriptionService,
		private CardRelationMapper $cardRelationMapper,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			return new JSONResponse($this->boardService->findAllWithStats($this->currentUserId()));
		});
	}

	#[NoAdminRequired]
	public function create(string $title = '', ?string $color = null): JSONResponse {
		return $this->respond(function () use ($title, $color): JSONResponse {
			return new JSONResponse(
				$this->boardService->create($title, $color, $this->currentUserId())
			);
		});
	}

	/**
	 * Full board payload: the board, its stacks, its labels, its sharing
	 * rules (`acl`), the requesting user's own permission bits and card
	 * SUMMARIES (no descriptions - those load on card open; each summary
	 * carries its labelIds and assigneeIds). The board's latest change id
	 * doubles as ETag: on an If-None-Match hit we return 304 before touching
	 * the stack/card/label/assignee/acl tables at all.
	 */
	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$uid = $this->currentUserId();
			$board = $this->boardService->find($id, $uid);

			$etag = (string)$this->changeMapper->getLatestChangeId($id);
			if ($this->matchesIfNoneMatch($etag)) {
				$response = new JSONResponse([], Http::STATUS_NOT_MODIFIED);
				$response->setETag($etag);
				return $response;
			}

			$labelIdsByCard = $this->cardLabelMapper->findLabelIdsByBoard($id);
			$assigneesByCard = $this->cardAssigneeMapper->findUserIdsByBoard($id);
			$checklistByCard = $this->checklistItemMapper->progressByBoard($id);
			$childProgressByCard = $this->cardMapper->childProgressByBoard($id);
			$commentCountByCard = $this->commentMapper->countsByBoard($id);
			$reviewStateByCard = $this->cardReviewMapper->reviewStatesByBoard($id);
			// Card ids blocked by a not-done card - drives the tile "blocked" badge.
			$blockedIds = array_flip($this->cardRelationMapper->blockedCardIdsByBoard($id));
			$response = new JSONResponse([
				'board' => $board,
				'stacks' => $this->stackMapper->findByBoard($id),
				'cards' => array_map(
					static fn (Card $card): array => $card->jsonSerializeSummary()
						+ ['labelIds' => $labelIdsByCard[$card->getId()] ?? []]
						+ ['assigneeIds' => $assigneesByCard[$card->getId()] ?? []]
						+ ['checklist' => $checklistByCard[$card->getId()] ?? ['total' => 0, 'done' => 0]]
						+ ['childProgress' => $childProgressByCard[$card->getId()] ?? ['total' => 0, 'done' => 0]]
						+ ['commentCount' => $commentCountByCard[$card->getId()] ?? 0]
						+ ['reviewState' => $reviewStateByCard[$card->getId()] ?? null]
						+ ['blocked' => isset($blockedIds[$card->getId()])],
					$this->cardMapper->findSummariesByBoard($id)
				),
				'labels' => $this->labelMapper->findByBoard($id),
				'reviewTypes' => $this->reviewTypeMapper->findByBoard($id),
				'acl' => $this->aclMapper->findByBoard($id),
				// The requester's own bits, so the frontend can gate the
				// share/manage UI without re-deriving ACL semantics.
				'permissions' => $this->permissionService->getPermissions($board, $uid),
				// The requester's board-watch state {subscribed, subscribers, count}.
				'subscription' => $this->subscriptionService->buildBoardSubscription($id, $uid),
			]);
			$response->setETag($etag);
			return $response;
		});
	}

	/**
	 * All users with access to the board - the assignee-picker data source.
	 * Bounded server-side: an optional `?q` filters by display name / uid and
	 * the result is always capped, so a board shared with a very large group
	 * cannot balloon the picker payload.
	 */
	#[NoAdminRequired]
	public function participants(int $id, ?string $q = null): JSONResponse {
		return $this->respond(function () use ($id, $q): JSONResponse {
			return new JSONResponse(
				$this->participantService->getParticipants($id, $this->currentUserId(), $q)
			);
		});
	}

	#[NoAdminRequired]
	public function update(int $id, ?string $title = null, ?string $color = null, ?bool $archived = null, ?string $estimateScale = null, ?bool $newCardsOnTop = null, ?string $prefix = null): JSONResponse {
		return $this->respond(function () use ($id, $title, $color, $archived, $estimateScale, $newCardsOnTop, $prefix): JSONResponse {
			return new JSONResponse(
				$this->boardService->update($id, $title, $color, $archived, $this->currentUserId(), $estimateScale, $newCardsOnTop, $prefix)
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->boardService->delete($id, $this->currentUserId())
			);
		});
	}

	/**
	 * Compares the normalized If-None-Match request header (surrounding
	 * quotes and weak-validator prefix stripped) against the current ETag.
	 */
	private function matchesIfNoneMatch(string $etag): bool {
		$header = trim($this->request->getHeader('If-None-Match'));
		if ($header === '') {
			return false;
		}
		if (str_starts_with($header, 'W/')) {
			$header = substr($header, 2);
		}
		return trim($header, '"') === $etag;
	}

	/**
	 * @throws NotPermittedException if there is no user session
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NotPermittedException('No authenticated user');
		}
		return $user->getUID();
	}
}
