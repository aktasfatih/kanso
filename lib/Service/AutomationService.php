<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\AutomationRule;
use OCA\Kanso\Db\AutomationRuleMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Per-board automation: a deliberately small, FIXED trigger→action menu (no
 * scripting, no DSL - the charter's Jira trap). Rules are configured by a MANAGE
 * user and evaluated server-side at the card-move choke point.
 *
 * v1 trigger: `card_entered_role` - a card moved into a stack carrying role R.
 * v1 actions: `request_review` (from a reviewer) and `add_label`. Neither action
 * moves the card or changes its role/done state, so an automated action can
 * never re-trigger a rule - no loop is possible in v1 (a guard becomes necessary
 * only when a card-moving action like move-to-role is added).
 */
class AutomationService {
	private const TRIGGERS = [AutomationRule::TRIGGER_CARD_ENTERED_ROLE];
	private const ACTIONS = [AutomationRule::ACTION_REQUEST_REVIEW, AutomationRule::ACTION_ADD_LABEL];

	public function __construct(
		private AutomationRuleMapper $ruleMapper,
		private BoardMapper $boardMapper,
		private PermissionService $permissionService,
		private ReviewService $reviewService,
		private LabelMapper $labelMapper,
		private CardLabelMapper $cardLabelMapper,
		private ChangeNotifier $changeNotifier,
	) {
	}

	/**
	 * A board's automation rules. Requires MANAGE (this is board configuration).
	 *
	 * @return AutomationRule[]
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function listRules(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		return $this->ruleMapper->findByBoard($boardId);
	}

	/**
	 * Creates a rule. Requires MANAGE. Validates the trigger/action against the
	 * fixed menu and the params against the chosen action.
	 *
	 * @param array<string, mixed> $params
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 * @throws InvalidInputException on an unknown trigger/action or bad params
	 */
	public function createRule(int $boardId, string $trigger, string $action, array $params, string $actorUid): AutomationRule {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		if (!in_array($trigger, self::TRIGGERS, true)) {
			throw new InvalidInputException('Unknown trigger: ' . $trigger);
		}
		if (!in_array($action, self::ACTIONS, true)) {
			throw new InvalidInputException('Unknown action: ' . $action);
		}
		$normalized = $this->validateParams($boardId, $action, $params);

		$rule = new AutomationRule();
		$rule->setBoardId($boardId);
		$rule->setTrigger($trigger);
		$rule->setAction($action);
		$rule->setParams(json_encode($normalized));
		$rule->setEnabled(true);
		$rule->setCreatedAt(time());
		return $this->ruleMapper->insert($rule);
	}

	/**
	 * @throws DoesNotExistException if the rule or its board does not exist
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function setEnabled(int $ruleId, bool $enabled, string $actorUid): AutomationRule {
		$rule = $this->ruleMapper->find($ruleId);
		$board = $this->loadBoard($rule->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		$rule->setEnabled($enabled);
		return $this->ruleMapper->update($rule);
	}

	/**
	 * @throws DoesNotExistException if the rule or its board does not exist
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function deleteRule(int $ruleId, string $actorUid): void {
		$rule = $this->ruleMapper->find($ruleId);
		$board = $this->loadBoard($rule->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		$this->ruleMapper->delete($rule);
	}

	/**
	 * Fire path: a card just entered a stack with role $role. Applies every
	 * enabled `card_entered_role` rule of the board whose configured role matches.
	 * Best-effort - a failing rule (e.g. a reviewer who lost access) is skipped,
	 * never blocking the move that triggered it. No permission check: the caller
	 * (CardService::move) has already gated the move with EDIT.
	 */
	public function runCardEnteredRole(Card $card, int $role, string $actorUid): void {
		if ($role === Stack::ROLE_NONE) {
			return;
		}
		try {
			$rules = $this->ruleMapper->findEnabledByBoardAndTrigger($card->getBoardId(), AutomationRule::TRIGGER_CARD_ENTERED_ROLE);
		} catch (\Throwable) {
			return;
		}
		foreach ($rules as $rule) {
			$params = $rule->paramsArray();
			if ((int)($params['role'] ?? 0) !== $role) {
				continue;
			}
			try {
				$this->applyAction($rule->getAction(), $params, $card, $actorUid);
			} catch (\Throwable) {
				// Best-effort - a broken rule never blocks the triggering move.
			}
		}
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function applyAction(string $action, array $params, Card $card, string $actorUid): void {
		if ($action === AutomationRule::ACTION_REQUEST_REVIEW) {
			$reviewer = (string)($params['reviewer'] ?? '');
			if ($reviewer !== '') {
				$this->reviewService->requestReview($card->getId(), $reviewer, $actorUid);
			}
			return;
		}
		if ($action === AutomationRule::ACTION_ADD_LABEL) {
			$labelId = (int)($params['label'] ?? 0);
			if ($labelId > 0 && !$this->cardLabelMapper->exists($card->getId(), $labelId)) {
				$this->cardLabelMapper->insertAssignment($card->getId(), $labelId);
				$this->changeNotifier->notify(
					$card->getBoardId(),
					Change::ENTITY_CARD,
					$card->getId(),
					Change::ACTION_UPDATE,
					$actorUid
				);
			}
		}
	}

	/**
	 * Validates + normalizes the params for an action, returning the stored blob.
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 * @throws InvalidInputException
	 */
	private function validateParams(int $boardId, string $action, array $params): array {
		$role = (int)($params['role'] ?? 0);
		if ($role < Stack::ROLE_BACKLOG || $role > Stack::ROLE_DONE) {
			throw new InvalidInputException('Rule role must be a valid stack role');
		}

		if ($action === AutomationRule::ACTION_REQUEST_REVIEW) {
			$reviewer = (string)($params['reviewer'] ?? '');
			if ($reviewer === '') {
				throw new InvalidInputException('request_review needs a reviewer');
			}
			return ['role' => $role, 'reviewer' => $reviewer];
		}

		// add_label - the label must belong to this board.
		$labelId = (int)($params['label'] ?? 0);
		try {
			$label = $this->labelMapper->find($labelId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Label does not exist');
		}
		if ($label->getBoardId() !== $boardId) {
			throw new InvalidInputException('Label belongs to another board');
		}
		return ['role' => $role, 'label' => $labelId];
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 */
	private function loadBoard(int $boardId): Board {
		$board = $this->boardMapper->find($boardId);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $boardId . ' is deleted');
		}
		return $board;
	}
}
