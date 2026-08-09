<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A kanban board (table `kanso_boards`).
 *
 * A board's `prefix` is the shared half of every card's human-readable id
 * ({@see BoardPrefix}); the card's `board_seq` supplies the number.
 *
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 * @method string|null getBackground()
 * @method void setBackground(?string $background)
 * @method bool getArchived()
 * @method void setArchived(bool $archived)
 * @method int getLastModified()
 * @method void setLastModified(int $lastModified)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $deletedAt)
 * @method string|null getWebhookSecret()
 * @method void setWebhookSecret(?string $webhookSecret)
 * @method int|null getWebhookIntakeStackId()
 * @method void setWebhookIntakeStackId(?int $webhookIntakeStackId)
 * @method string|null getWebhookIntakeLabel()
 * @method void setWebhookIntakeLabel(?string $webhookIntakeLabel)
 * @method string getEstimateScale()
 * @method void setEstimateScale(string $estimateScale)
 * @method bool|null getNewCardsOnTop()
 * @method void setNewCardsOnTop(?bool $newCardsOnTop)
 * @method string|null getPrefix()
 * @method void setPrefix(?string $prefix)
 * @method string|null getPublicShareToken()
 * @method void setPublicShareToken(?string $publicShareToken)
 * @method int|null getPublicShareExpiresAt()
 * @method void setPublicShareExpiresAt(?int $publicShareExpiresAt)
 * @method string|null getIcalFeedToken()
 * @method void setIcalFeedToken(?string $icalFeedToken)
 */
class Board extends Entity implements \JsonSerializable {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?string $title = null;
	protected ?string $owner = null;
	protected ?string $color = null;
	// A CURATED preset KEY (validated by BackgroundValidator) rendered as the
	// board's full-view background. NULL = no background. Never free-form CSS.
	protected ?string $background = null;
	protected ?bool $archived = null;
	protected ?int $lastModified = null;
	protected ?int $deletedAt = null;
	// MANAGE-only; deliberately NEVER emitted by jsonSerialize().
	protected ?string $webhookSecret = null;
	// GitHub issue intake (#3752). MANAGE-only webhook config, OFF by default.
	// NULL stack = intake disabled (webhook stays react-only); a non-null stack
	// means an `issues`/`opened` delivery auto-creates a link-only card there.
	// The label is an optional free-text GitHub label filter (NULL = all
	// issues). Both ride the webhook config endpoint, not the board payload.
	protected ?int $webhookIntakeStackId = null;
	protected ?string $webhookIntakeLabel = null;
	protected ?string $estimateScale = null;
	protected ?bool $newCardsOnTop = null;
	// The per-board human-id prefix (e.g. "KAN"); a card's reference is
	// prefix + '-' + board_seq. Derived from the title, editable in settings.
	protected ?string $prefix = null;
	// Public / read-only share (#3531). MANAGE-only, OFF by default. NULL = no
	// public link; a non-null value is a long ISecureRandom token that grants an
	// unauthenticated reader the STRIPPED read-only board view. Deliberately
	// NEVER emitted by jsonSerialize() - it must not leak into the authenticated
	// board payload; the token is only ever returned by the dedicated MANAGE
	// config endpoints. Rotating replaces it, disabling clears it.
	protected ?string $publicShareToken = null;
	// Optional unix-ts expiry for the public link (NULL / 0 = never). v1 defaults
	// to no expiry (revocable-until-disabled).
	protected ?int $publicShareExpiresAt = null;
	// iCal / ICS read-only due-date feed (#3541). MANAGE-only, OFF by default. NULL
	// = no feed; a non-null value is a long ISecureRandom token that lets any
	// calendar client subscribe to a read-only VCALENDAR of this board's due cards.
	// A DISTINCT token from publicShareToken - the two share features have
	// independent lifecycles. Deliberately NEVER emitted by jsonSerialize(); the
	// token is only ever returned by the dedicated MANAGE feed-config endpoints.
	protected ?string $icalFeedToken = null;

	public function __construct() {
		$this->addType('title', Types::STRING);
		$this->addType('owner', Types::STRING);
		$this->addType('color', Types::STRING);
		$this->addType('background', Types::STRING);
		$this->addType('archived', Types::BOOLEAN);
		$this->addType('lastModified', Types::INTEGER);
		$this->addType('deletedAt', Types::INTEGER);
		$this->addType('webhookSecret', Types::STRING);
		$this->addType('webhookIntakeStackId', Types::INTEGER);
		$this->addType('webhookIntakeLabel', Types::STRING);
		$this->addType('estimateScale', Types::STRING);
		$this->addType('newCardsOnTop', Types::BOOLEAN);
		$this->addType('prefix', Types::STRING);
		$this->addType('publicShareToken', Types::STRING);
		$this->addType('publicShareExpiresAt', Types::INTEGER);
		$this->addType('icalFeedToken', Types::STRING);
	}

	/**
	 * @return array{id: int, title: ?string, owner: ?string, color: ?string, background: ?string, archived: bool, lastModified: int, estimateScale: string, newCardsOnTop: bool, prefix: string}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'title' => $this->title,
			'owner' => $this->owner,
			'color' => $this->color,
			'background' => $this->background,
			'archived' => $this->archived ?? false,
			'lastModified' => $this->lastModified ?? 0,
			'estimateScale' => $this->estimateScale ?? EstimateScale::NONE,
			'newCardsOnTop' => $this->newCardsOnTop ?? false,
			// The human-id prefix; falls back to the shared default for boards
			// that predate the column and haven't been backfilled yet.
			'prefix' => $this->prefix ?? BoardPrefix::DEFAULT,
		];
	}
}
