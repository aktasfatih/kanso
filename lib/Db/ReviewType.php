<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A board-scoped review type (table `kanso_review_types`) - a customizable
 * category (QA / Code / Legal / …) a review request can carry. Modelled on
 * {@see Label}: a named, optionally coloured, board-owned tag, plus a `stage`
 * ordering it in the board's review chain. Lower stages gate higher ones: a
 * review is "gated" (its reviewer not yet notified) while the card carries any
 * OTHER unapproved review of a lower stage - see {@see ReviewService}. Untyped
 * reviews are implicitly stage 0.
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 * @method int getStage()
 * @method void setStage(int $stage)
 */
class ReviewType extends Entity implements \JsonSerializable {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $boardId = null;
	protected ?string $title = null;
	protected ?string $color = null;
	protected ?int $stage = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('color', Types::STRING);
		$this->addType('stage', Types::INTEGER);
	}

	/**
	 * @return array{id: int, boardId: ?int, title: ?string, color: ?string, stage: int}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'boardId' => $this->boardId,
			'title' => $this->title,
			'color' => $this->color,
			'stage' => $this->stage ?? 0,
		];
	}
}
