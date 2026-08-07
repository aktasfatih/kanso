<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One review request on a card (table `kanso_card_reviews`).
 *
 * A FLAT row - (card_id, reviewer) is unique. `state` is the reviewer's
 * verdict: pending until they act, then approved or changes_requested. The row
 * itself carries no stage: multi-stage gating is DERIVED at read time from the
 * review type's `stage` (see {@see ReviewType} and {@see ReviewService}), never
 * stored as a fourth state. `requested_by` records who asked; `notified_at` is
 * the unix time the reviewer was pinged, or null while the request is deferred
 * (gated behind a lower-stage review) - stamped once so a re-approval upstream
 * never re-notifies.
 *
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method string getReviewer()
 * @method void setReviewer(string $reviewer)
 * @method string getState()
 * @method void setState(string $state)
 * @method string getRequestedBy()
 * @method void setRequestedBy(string $requestedBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getReviewTypeId()
 * @method void setReviewTypeId(int $reviewTypeId)
 * @method int|null getNotifiedAt()
 * @method void setNotifiedAt(?int $notifiedAt)
 */
class CardReview extends Entity implements \JsonSerializable {
	public const STATE_PENDING = 'pending';
	public const STATE_APPROVED = 'approved';
	public const STATE_CHANGES_REQUESTED = 'changes_requested';

	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements. Note this is
	// only an INSERT-mechanics default: `review_type_id` is NOT NULL in the
	// schema (Version001600, 0 = the implicit single-stage review), so a loaded
	// row always carries a non-null reviewTypeId - hence the non-null getter.
	protected ?int $cardId = null;
	protected ?string $reviewer = null;
	protected ?string $state = null;
	protected ?string $requestedBy = null;
	protected ?int $createdAt = null;
	protected ?int $reviewTypeId = null;
	protected ?int $notifiedAt = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('reviewer', Types::STRING);
		$this->addType('state', Types::STRING);
		$this->addType('requestedBy', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('reviewTypeId', Types::INTEGER);
		$this->addType('notifiedAt', Types::INTEGER);
	}

	/**
	 * @return array{id: int, cardId: int, reviewer: string, state: string, requestedBy: string, createdAt: int, reviewTypeId: int, notifiedAt: ?int}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (int)$this->getId(),
			'cardId' => (int)$this->getCardId(),
			'reviewer' => (string)$this->getReviewer(),
			'state' => (string)$this->getState(),
			'requestedBy' => (string)$this->getRequestedBy(),
			'createdAt' => (int)$this->getCreatedAt(),
			'reviewTypeId' => (int)$this->getReviewTypeId(),
			'notifiedAt' => $this->getNotifiedAt() !== null ? (int)$this->getNotifiedAt() : null,
		];
	}

	/**
	 * The valid reviewer verdicts a client may PATCH a review to. Requesting a
	 * review always starts at STATE_PENDING (server-set), so it is not settable.
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function settableStates(): array {
		return [self::STATE_APPROVED, self::STATE_CHANGES_REQUESTED];
	}
}
