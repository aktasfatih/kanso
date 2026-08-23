<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A per-board automation rule (table `kanso_automation_rules`). Fixed
 * trigger→action menu; `params` is a small JSON object ({role, reviewer|label}).
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method string getTrigger()
 * @method void setTrigger(string $trigger)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string getParams()
 * @method void setParams(string $params)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class AutomationRule extends Entity implements \JsonSerializable {
	public const TRIGGER_CARD_ENTERED_ROLE = 'card_entered_role';

	public const ACTION_REQUEST_REVIEW = 'request_review';
	public const ACTION_ADD_LABEL = 'add_label';
	public const ACTION_START_TIMER = 'start_timer';
	public const ACTION_STOP_TIMER = 'stop_timer';

	protected ?int $boardId = null;
	protected ?string $trigger = null;
	protected ?string $action = null;
	protected ?string $params = null;
	protected ?bool $enabled = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('trigger', Types::STRING);
		$this->addType('action', Types::STRING);
		$this->addType('params', Types::STRING);
		$this->addType('enabled', Types::BOOLEAN);
		$this->addType('createdAt', Types::INTEGER);
	}

	/**
	 * The decoded params object, always an array.
	 *
	 * @return array<string, mixed>
	 */
	public function paramsArray(): array {
		$decoded = json_decode((string)$this->params, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'boardId' => $this->getBoardId(),
			'trigger' => $this->getTrigger(),
			'action' => $this->getAction(),
			'params' => $this->paramsArray(),
			'enabled' => $this->getEnabled() ?? true,
			'createdAt' => $this->getCreatedAt() ?? 0,
		];
	}
}
