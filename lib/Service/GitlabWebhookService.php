<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\CardLink;
use OCA\Kanso\Service\Forge\ForgeEvent;

class GitlabWebhookService extends AbstractForgeWebhookService {
	#[\Override]
	protected function routeName(): string {
		return 'kanso.webhook.gitlab';
	}

	#[\Override]
	protected function provider(): string {
		return CardLink::PROVIDER_GITLAB;
	}

	#[\Override]
	protected function readSecret(Board $board): ?string {
		return $board->getGitlabWebhookSecret();
	}

	#[\Override]
	protected function writeSecret(Board $board, ?string $secret): void {
		$board->setGitlabWebhookSecret($secret);
	}

	#[\Override]
	protected function readIntakeStackId(Board $board): ?int {
		return null;
	}

	#[\Override]
	protected function writeIntakeStackId(Board $board, ?int $stackId): void {
		throw new InvalidInputException('GitLab issue intake is not supported');
	}

	#[\Override]
	protected function readIntakeLabel(Board $board): ?string {
		return null;
	}

	#[\Override]
	protected function writeIntakeLabel(Board $board, ?string $label): void {
		throw new InvalidInputException('GitLab issue intake is not supported');
	}

	#[\Override]
	protected function intakeRetriggerActions(): array {
		return [];
	}

	#[\Override]
	protected function verifySignature(Board $board, string $signatureHeader, string $rawBody): void {
		$secret = $this->readSecret($board) ?? '';
		if ($secret === '' || $signatureHeader === '' || !hash_equals($secret, $signatureHeader)) {
			throw new NotPermittedException('Invalid webhook token');
		}
	}

	#[\Override]
	protected function normalize(array $payload): ForgeEvent {
		$kind = match ($payload['object_kind'] ?? '') {
			'issue' => ForgeEvent::KIND_ISSUE,
			'merge_request' => ForgeEvent::KIND_PR,
			default => ForgeEvent::KIND_NONE,
		};
		$attributes = $payload['object_attributes'] ?? null;
		if ($kind === ForgeEvent::KIND_NONE || !is_array($attributes) || !is_string($attributes['url'] ?? null)) {
			return ForgeEvent::none();
		}
		try {
			[$urlKind, $url] = CardLinkService::parseGitlabUrl($attributes['url']);
		} catch (InvalidInputException) {
			return ForgeEvent::none();
		}
		if (($kind === ForgeEvent::KIND_PR ? CardLink::KIND_PR : CardLink::KIND_ISSUE) !== $urlKind
			|| !in_array($attributes['state'] ?? null, $kind === ForgeEvent::KIND_PR ? ['opened', 'closed', 'merged'] : ['opened', 'closed'], true)) {
			return ForgeEvent::none();
		}
		$action = match ($attributes['action'] ?? '') {
			'open' => 'opened',
			'reopen' => 'reopened',
			'close' => 'closed',
			'merge' => 'merged',
			default => 'updated',
		};
		$state = match ($attributes['state'] ?? '') {
			'opened' => CardLink::STATE_OPEN,
			'closed' => CardLink::STATE_CLOSED,
			'merged' => $kind === ForgeEvent::KIND_PR ? CardLink::STATE_MERGED : CardLink::STATE_UNKNOWN,
			default => CardLink::STATE_UNKNOWN,
		};
		$merged = $kind === ForgeEvent::KIND_PR && ($state === CardLink::STATE_MERGED || $action === 'merged');
		return new ForgeEvent(
			kind: $kind,
			action: $action,
			branch: is_string($attributes['source_branch'] ?? null) ? $attributes['source_branch'] : '',
			htmlUrl: $url,
			title: is_string($attributes['title'] ?? null) ? $attributes['title'] : null,
			state: $merged ? CardLink::STATE_MERGED : $state,
			merged: $merged,
			urlCandidates: [$url, $url . '/'],
		);
	}

	#[\Override]
	protected function cacheLinkState(CardLink $link, ForgeEvent $event): void {
		try {
			$this->cardLinkService->updateFromWebhook($link, $event->state, $event->title);
		} catch (\Throwable) {
			// Link refresh failures must not prevent the workflow transition.
		}
	}
}
