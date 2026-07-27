<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\ChecklistItem;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Service\AssigneeService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\LabelService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\SubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Single-card endpoints. All responses serialize the full card payload
 * (including the description) — only the board/stack listings use the
 * summary shape.
 */
class CardController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CardService $cardService,
		private LabelService $labelService,
		private AssigneeService $assigneeService,
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private CardReviewMapper $cardReviewMapper,
		private ChecklistItemMapper $checklistItemMapper,
		private CardMapper $cardMapper,
		private CommentMapper $commentMapper,
		private SubscriptionService $subscriptionService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function create(int $stackId = 0, string $title = ''): JSONResponse {
		return $this->respond(function () use ($stackId, $title): JSONResponse {
			return new JSONResponse(
				$this->cardService->create($stackId, $title, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$uid = $this->currentUserId();
			$card = $this->cardService->find($id, $uid);
			return new JSONResponse($this->detailPayload($card, $uid));
		});
	}

	/**
	 * Full single-card detail payload (description + labels + assignees +
	 * checklist + parent/children). Shared by show() and setParent() so both
	 * return the identical shape. Takes a fully-hydrated Card (from
	 * CardService::find or ::setParent, both of which load the description).
	 *
	 * @return array<string, mixed>
	 */
	private function detailPayload(Card $card, string $uid): array {
		$id = $card->getId();
		$checklistItems = $this->checklistItemMapper->findByCard($id);
		$checklistDone = count(array_filter(
			$checklistItems,
			static fn (ChecklistItem $item): bool => $item->getDone()
		));

		$parentId = $card->getParentCardId();
		$parent = null;
		if ($parentId !== null) {
			try {
				$parentCard = $this->cardMapper->find($parentId);
				if ($parentCard->getDeletedAt() === 0) {
					$parent = $parentCard->jsonSerializeSummary();
				}
			} catch (DoesNotExistException) {
				$parent = null;
			}
		}
		$children = array_map(
			static fn (Card $child): array => $child->jsonSerializeSummary(),
			$this->cardMapper->findChildren($id)
		);

		return $card->jsonSerialize()
			+ ['labelIds' => $this->cardLabelMapper->findLabelIdsByCard($id)]
			+ ['assigneeIds' => $this->cardAssigneeMapper->findUserIdsByCard($id)]
			+ ['reviews' => $this->cardReviewMapper->findByCard($id)]
			+ ['checklistItems' => $checklistItems]
			+ ['checklist' => ['total' => count($checklistItems), 'done' => $checklistDone]]
			+ ['parent' => $parent]
			+ ['children' => $children]
			+ ['commentCount' => $this->commentMapper->countByCard($id)]
			+ ['subscription' => $this->subscriptionService->buildCardSubscription($id, $uid)];
	}

	/**
	 * Sets ($parentCardId given) or clears ($parentCardId null) the card's
	 * parent. One level, same board — invalid requests surface as 400 via
	 * ApiErrorTrait. Returns the updated full card detail.
	 */
	#[NoAdminRequired]
	public function setParent(int $id, ?int $parentCardId = null): JSONResponse {
		return $this->respond(function () use ($id, $parentCardId): JSONResponse {
			$uid = $this->currentUserId();
			$card = $this->cardService->setParent($id, $parentCardId, $uid);
			return new JSONResponse($this->detailPayload($card, $uid));
		});
	}

	#[NoAdminRequired]
	public function update(
		int $id,
		?string $title = null,
		?string $description = null,
		?string $duedate = null,
		?bool $done = null,
		?bool $archived = null,
		?int $priority = null,
		?string $startDate = null,
		?string $status = null,
	): JSONResponse {
		return $this->respond(function () use ($id, $title, $description, $duedate, $done, $archived, $priority, $startDate, $status): JSONResponse {
			return new JSONResponse(
				$this->cardService->update($id, $title, $description, $duedate, $done, $archived, $this->currentUserId(), $priority, $startDate, $status)
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->cardService->delete($id, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * Moves the card into $targetStackId, directly after $afterCardId
	 * (null = top of the stack). A sort-key overflow surfaces as
	 * 409 {"error": "rebalance_required"} via ApiErrorTrait.
	 */
	#[NoAdminRequired]
	public function move(int $id, int $targetStackId = 0, ?int $afterCardId = null): JSONResponse {
		return $this->respond(function () use ($id, $targetStackId, $afterCardId): JSONResponse {
			return new JSONResponse(
				$this->cardService->move($id, $targetStackId, $afterCardId, $this->currentUserId())
			);
		});
	}

	/**
	 * Assigns a label of the card's board to the card. Idempotent — PUT of
	 * an already assigned label succeeds without writing anything.
	 */
	#[NoAdminRequired]
	public function assignLabel(int $id, int $labelId): JSONResponse {
		return $this->respond(function () use ($id, $labelId): JSONResponse {
			$this->labelService->assign($id, $labelId, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * Removes a label from the card. Idempotent — DELETE of an absent
	 * assignment succeeds without writing anything.
	 */
	#[NoAdminRequired]
	public function unassignLabel(int $id, int $labelId): JSONResponse {
		return $this->respond(function () use ($id, $labelId): JSONResponse {
			$this->labelService->unassign($id, $labelId, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * Assigns a user of the card's board to the card. Idempotent — PUT of
	 * an already assigned user succeeds without writing anything.
	 */
	#[NoAdminRequired]
	public function assignUser(int $id, string $userId): JSONResponse {
		return $this->respond(function () use ($id, $userId): JSONResponse {
			$this->assigneeService->assign($id, $userId, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * Removes an assigned user from the card. Idempotent — DELETE of an
	 * absent assignment succeeds without writing anything.
	 */
	#[NoAdminRequired]
	public function unassignUser(int $id, string $userId): JSONResponse {
		return $this->respond(function () use ($id, $userId): JSONResponse {
			$this->assigneeService->unassign($id, $userId, $this->currentUserId());
			return new JSONResponse([]);
		});
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
