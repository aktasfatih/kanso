<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardAttachmentMapper;
use OCA\Kanso\Db\CardContactMapper;
use OCA\Kanso\Db\CardFieldValue;
use OCA\Kanso\Db\CardFieldValueMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardTimeEntryMapper;
use OCA\Kanso\Db\ChecklistItem;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\ProjectCardMapper;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Service\AssigneeService;
use OCA\Kanso\Service\CardRelationService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\ContactService;
use OCA\Kanso\Service\LabelService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ReminderService;
use OCA\Kanso\Service\ReviewService;
use OCA\Kanso\Service\SubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Single-card endpoints. All responses serialize the full card payload
 * (including the description) - only the board/stack listings use the
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
		private ContactService $contactService,
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private CardContactMapper $cardContactMapper,
		private ReviewService $reviewService,
		private ReminderService $reminderService,
		private ChecklistItemMapper $checklistItemMapper,
		private CardMapper $cardMapper,
		private CommentMapper $commentMapper,
		private SubscriptionService $subscriptionService,
		private CardRelationService $relationService,
		private ProjectCardMapper $projectCardMapper,
		private CardAttachmentMapper $cardAttachmentMapper,
		private CardTimeEntryMapper $cardTimeEntryMapper,
		private CardFieldValueMapper $cardFieldValueMapper,
		private BoardMapper $boardMapper,
		private BoardAccess $boardAccess,
		private \OCA\Kanso\Service\CardVisibilityGuard $visibilityGuard,
		private RecurRuleMapper $recurRuleMapper,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function create(int $stackId = 0, string $title = '', ?string $duedate = null, ?bool $allDay = null): JSONResponse {
		return $this->respond(function () use ($stackId, $title, $duedate, $allDay): JSONResponse {
			return new JSONResponse(
				$this->cardService->create($stackId, $title, $this->currentUserId(), $duedate, $allDay)
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
	 * Resolves a board-scoped `PREFIX-<board_seq>` human reference (e.g.
	 * "KAN-123") to the referenced card's numeric id + title (#3611). Powers
	 * opening a card by its human id from a URL/deep link, and is the fallback
	 * the markdown renderer can call when the card isn't in the board cache.
	 * Board-scoped (per-board prefixes); requires READ on the board. Returns
	 * {"cardId": <int>, "title": "..."} on a hit, or 404 for an
	 * unknown/mismatched/malformed reference.
	 */
	#[NoAdminRequired]
	public function resolveRef(int $id, string $ref): JSONResponse {
		return $this->respond(function () use ($id, $ref): JSONResponse {
			$uid = $this->currentUserId();
			$card = $this->cardService->findByRef($id, $ref, $uid);
			if ($card === null) {
				return new JSONResponse(['error' => 'Not found'], \OCP\AppFramework\Http::STATUS_NOT_FOUND);
			}
			return new JSONResponse(['cardId' => $card->getId(), 'title' => $card->getTitle()]);
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
		// The viewer's resolved side on the card's board (#3743): scopes the
		// children list, masks a hidden parent and masks hidden relation
		// counterparts. The caller has already asserted READ + visibility on
		// $card itself (CardService::find / the mutating service).
		$board = $this->boardMapper->find($card->getBoardId());
		$viewer = $this->boardAccess->contextFor($board, $uid);

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
				// A hidden parent reads as no parent (#3743) - its title must
				// not surface through a child's breadcrumb.
				if ($parentCard->getDeletedAt() === 0
					&& $this->visibilityGuard->isVisible($board, $parentCard, $uid)) {
					$parent = $parentCard->jsonSerializeSummary();
				}
			} catch (DoesNotExistException) {
				$parent = null;
			}
		}
		$children = array_map(
			static fn (Card $child): array => $child->jsonSerializeSummary(),
			$this->cardMapper->findVisibleChildren($id, $viewer)
		);

		return $card->jsonSerialize()
			+ ['labelIds' => $this->cardLabelMapper->findLabelIdsByCard($id)]
			+ ['assigneeIds' => $this->cardAssigneeMapper->findUserIdsByCard($id)]
			+ ['contacts' => $this->cardContactMapper->findContactsByCard($id)]
			+ ['reviews' => $this->reviewService->serializeReviewsForCard($id)]
			// The viewer's OWN pending personal reminders on this card (#3816) -
			// per-user + private, so another member sees only their own.
			+ ['myReminders' => $this->reminderService->listMineForCard($id, $uid)]
			+ ['checklistItems' => $checklistItems]
			+ ['checklist' => ['total' => count($checklistItems), 'done' => $checklistDone]]
			+ ['parent' => $parent]
			+ ['children' => $children]
			+ ['commentCount' => $this->commentMapper->countByCard($id)]
			+ ['attachmentCount' => $this->cardAttachmentMapper->countByCard($id)]
			+ ['timeSpent' => $this->cardTimeEntryMapper->sumSecondsByCard($id)]
			+ ['subscription' => $this->subscriptionService->buildCardSubscription($id, $uid)]
			+ ['relations' => $this->relationService->groupedForCard($id, $board, $uid)]
			+ ['projectIds' => $this->projectCardMapper->findProjectIdsByCard($id)]
			// Custom-field VALUES (#3537): [{fieldId, value}] - detail-only, never
			// in the board summary (the definitions ride the board payload).
			+ ['fieldValues' => array_map(
				static fn (CardFieldValue $v): array => $v->jsonSerialize(),
				$this->cardFieldValueMapper->findByCard($id)
			)]
			// Live (enabled) recurrence rule present? (#61 follow-up) One boolean
			// so the open card can swap the Due Date pill's calendar icon for a
			// repeat icon for ALL viewers - matching the board tile. The rrule/rule
			// object stays out; it loads via the manager-only rules fetch.
			+ ['recurring' => $this->recurRuleMapper->hasEnabledRuleForCard($id)];
	}

	/**
	 * Sets ($parentCardId given) or clears ($parentCardId null) the card's
	 * parent. One level, same board - invalid requests surface as 400 via
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
		?string $estimate = null,
		?bool $allDay = null,
		?bool $dueReminderDayBefore = null,
		?string $coverColor = null,
		?string $type = null,
		?string $visibility = null,
	): JSONResponse {
		return $this->respond(function () use ($id, $title, $description, $duedate, $done, $archived, $priority, $startDate, $status, $estimate, $allDay, $dueReminderDayBefore, $coverColor, $type, $visibility): JSONResponse {
			return new JSONResponse(
				$this->cardService->update($id, $title, $description, $duedate, $done, $archived, $this->currentUserId(), $priority, $startDate, $status, $estimate, $allDay, $dueReminderDayBefore, $coverColor, $type, $visibility)
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
	 * Copies (duplicates) the card's content into $targetStackId - the same
	 * stack, another stack on the same board, or a stack on another board the
	 * user can EDIT. Clones title/description/labels/checklist/estimate/
	 * priority/status only; comments, activity, relations and assignees are NOT
	 * copied. Cross-board labels map by title+color or drop. Returns the full
	 * detail payload of the new card. A sort-key overflow surfaces as
	 * 409 {"error": "rebalance_required"} via ApiErrorTrait.
	 */
	#[NoAdminRequired]
	public function copy(int $id, int $targetStackId = 0): JSONResponse {
		return $this->respond(function () use ($id, $targetStackId): JSONResponse {
			$uid = $this->currentUserId();
			$card = $this->cardService->copy($id, $targetStackId, $uid);
			return new JSONResponse($this->detailPayload($card, $uid));
		});
	}

	/**
	 * MOVES the card to $targetStackId on ANOTHER board (#3679): the card is
	 * re-created on the target board and REMOVED from the source, in one
	 * transaction. Requires EDIT on BOTH boards. Content, checklist, and
	 * title/status/dates cross over; labels map by title+color or drop; assignees
	 * and watchers are KEPT only for uids that can READ the target board (dropped
	 * otherwise). The KAN-id is re-issued on the target. Returns the full detail
	 * payload of the moved card. A sort-key overflow surfaces as
	 * 409 {"error": "rebalance_required"} via ApiErrorTrait.
	 */
	#[NoAdminRequired]
	public function moveToBoard(int $id, int $targetStackId = 0): JSONResponse {
		return $this->respond(function () use ($id, $targetStackId): JSONResponse {
			$uid = $this->currentUserId();
			$card = $this->cardService->moveToBoard($id, $targetStackId, $uid);
			return new JSONResponse($this->detailPayload($card, $uid));
		});
	}

	/**
	 * Per-board template picker (#3409): summaries of the board's template cards
	 * (the complement of the live board render, which excludes them). Requires
	 * READ on the board (same gate as the board payload). Returns a flat list of
	 * card summaries (id + title etc.); the client shows title as the picker label.
	 */
	#[NoAdminRequired]
	public function templates(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$templates = $this->cardService->listTemplates($id, $this->currentUserId());
			return new JSONResponse(array_map(
				static fn (Card $card): array => $card->jsonSerializeSummary(),
				$templates
			));
		});
	}

	/**
	 * Flags (isTemplate=true) or unflags (false) the card as a per-board template
	 * (#3409). EDIT-gated on the board. Returns the full updated card detail so
	 * the client patches its cache (and drops/re-adds it from the live board list).
	 */
	#[NoAdminRequired]
	public function setTemplate(int $id, bool $isTemplate = false): JSONResponse {
		return $this->respond(function () use ($id, $isTemplate): JSONResponse {
			$uid = $this->currentUserId();
			$card = $this->cardService->setTemplate($id, $isTemplate, $uid);
			return new JSONResponse($this->detailPayload($card, $uid));
		});
	}

	/**
	 * Creates a NEW card in $targetStackId pre-filled from the per-board template
	 * $id (#3409): title/description/labels/checklist/priority/type/estimate are
	 * cloned; comments/assignees/history are NOT. The template must be a template
	 * card on the same board as the target stack. EDIT-gated. Returns the full
	 * detail payload of the new (live) card. A sort-key overflow surfaces as
	 * 409 {"error": "rebalance_required"} via ApiErrorTrait.
	 */
	#[NoAdminRequired]
	public function createFromTemplate(int $id, int $targetStackId = 0): JSONResponse {
		return $this->respond(function () use ($id, $targetStackId): JSONResponse {
			$uid = $this->currentUserId();
			$card = $this->cardService->createFromTemplate($id, $targetStackId, $uid);
			return new JSONResponse($this->detailPayload($card, $uid));
		});
	}

	/**
	 * Assigns a label of the card's board to the card. Idempotent - PUT of
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
	 * Removes a label from the card. Idempotent - DELETE of an absent
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
	 * Assigns a user of the card's board to the card. Idempotent - PUT of
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
	 * Removes an assigned user from the card. Idempotent - DELETE of an
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
	 * Links a Nextcloud Contacts entry to the card as a read-only reference
	 * (#3530). The CardDAV URI + display name travel in the body (the URI is not
	 * path-safe). Idempotent - re-linking succeeds without writing anything.
	 * Returns the persisted {contactUri, displayName} (the display name is the
	 * server-resolved snapshot).
	 */
	#[NoAdminRequired]
	public function linkContact(int $id, string $contactUri = '', string $displayName = ''): JSONResponse {
		return $this->respond(function () use ($id, $contactUri, $displayName): JSONResponse {
			return new JSONResponse(
				$this->contactService->link($id, $contactUri, $displayName, $this->currentUserId())
			);
		});
	}

	/**
	 * Unlinks a contact from the card. Idempotent - DELETE of an absent link
	 * succeeds without writing anything. Works even when the Contacts app is
	 * later disabled, so a dangling link is always removable.
	 */
	#[NoAdminRequired]
	public function unlinkContact(int $id, string $contactUri = ''): JSONResponse {
		return $this->respond(function () use ($id, $contactUri): JSONResponse {
			$this->contactService->unlink($id, $contactUri, $this->currentUserId());
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
