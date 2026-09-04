<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\CardLink;
use OCA\Kanso\Service\Forge\ForgeEvent;

/**
 * Inbound GitHub webhooks (slice 2 of the GitHub integration). A board opts in
 * by generating a per-board secret (MANAGE) and pointing a GitHub webhook
 * sending `pull_request` and `issues` events at its endpoint. Each delivery is
 * verified by HMAC-SHA256 against that secret - the endpoint is the ONLY
 * unauthenticated write path, so every field is treated as untrusted and the
 * signature is checked in constant time before anything is parsed.
 *
 * Everything that happens AFTER a delivery is understood - branch and
 * `PREFIX-<seq>` title matching, auto-move, issue intake, link-state caching,
 * the egress gate - lives in {@see AbstractForgeWebhookService} and is shared
 * with the other forges. This class supplies only what is GitHub-specific: the
 * `sha256=`-prefixed signature header, the payload spellings, the github.com URL
 * layout, and which board columns hold the config.
 */
class GithubWebhookService extends AbstractForgeWebhookService {
	#[\Override]
	protected function routeName(): string {
		return 'kanso.webhook.github';
	}

	// ---- config columns ----------------------------------------------------

	#[\Override]
	protected function readSecret(Board $board): ?string {
		return $board->getWebhookSecret();
	}

	#[\Override]
	protected function writeSecret(Board $board, ?string $secret): void {
		$board->setWebhookSecret($secret);
	}

	#[\Override]
	protected function readIntakeStackId(Board $board): ?int {
		return $board->getWebhookIntakeStackId();
	}

	#[\Override]
	protected function writeIntakeStackId(Board $board, ?int $stackId): void {
		$board->setWebhookIntakeStackId($stackId);
	}

	#[\Override]
	protected function readIntakeLabel(Board $board): ?string {
		return $board->getWebhookIntakeLabel();
	}

	#[\Override]
	protected function writeIntakeLabel(Board $board, ?string $label): void {
		$board->setWebhookIntakeLabel($label);
	}

	// ---- signature ---------------------------------------------------------

	/**
	 * Constant-time HMAC-SHA256 verification of `sha256=<hex>` against the
	 * board's secret.
	 *
	 * @throws NotPermittedException if the webhook is disabled or the signature is absent/invalid
	 */
	#[\Override]
	protected function verifySignature(Board $board, string $signatureHeader, string $rawBody): void {
		$secret = $this->readSecret($board) ?? '';
		if ($secret === '') {
			throw new NotPermittedException('Webhook is not enabled for this board');
		}
		$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
		if ($signatureHeader === '' || !hash_equals($expected, $signatureHeader)) {
			throw new NotPermittedException('Invalid webhook signature');
		}
	}

	// ---- payload -----------------------------------------------------------

	/**
	 * Maps a GitHub delivery onto the shared event shape. Anything that is not a
	 * `pull_request` or `issues` delivery (a ping, say) becomes KIND_NONE and is
	 * accepted as a no-op.
	 *
	 * An `issue_comment` delivery on a PR also carries an `issue` object (whose
	 * html_url is a /pull/ URL) - that is not a Kanso issue link, so it falls out
	 * at the KIND_ISSUE check.
	 *
	 * @param array<string, mixed> $payload
	 */
	#[\Override]
	protected function normalize(array $payload): ForgeEvent {
		$action = is_string($payload['action'] ?? null) ? $payload['action'] : '';

		$pr = $payload['pull_request'] ?? null;
		if (is_array($pr)) {
			$branch = '';
			if (isset($pr['head']) && is_array($pr['head']) && is_string($pr['head']['ref'] ?? null)) {
				$branch = $pr['head']['ref'];
			}
			// A merge reaches us in three spellings across forge generations, and a
			// merged PR reports `state: closed` - so read all three or a merge is
			// indistinguishable from a plain close.
			$merged = ($pr['merged'] ?? false) === true
				|| !empty($pr['merged_at'])
				|| $action === 'merged';

			return new ForgeEvent(
				kind: ForgeEvent::KIND_PR,
				action: $action,
				branch: $branch,
				htmlUrl: is_string($pr['html_url'] ?? null) ? $pr['html_url'] : '',
				title: is_string($pr['title'] ?? null) ? $pr['title'] : null,
				state: $merged ? CardLink::STATE_MERGED : $this->mapState($pr['state'] ?? null),
				merged: $merged,
			);
		}

		$issue = $payload['issue'] ?? null;
		if (is_array($issue)) {
			$issueUrl = is_string($issue['html_url'] ?? null) ? $issue['html_url'] : '';
			if ($issueUrl === '') {
				return ForgeEvent::none();
			}
			try {
				[$kind, $owner, $repo, $number] = CardLinkService::parseGitHubUrl($issueUrl);
			} catch (InvalidInputException) {
				return ForgeEvent::none();
			}
			if ($kind !== CardLink::KIND_ISSUE) {
				return ForgeEvent::none();
			}

			return new ForgeEvent(
				kind: ForgeEvent::KIND_ISSUE,
				action: $action,
				htmlUrl: $issueUrl,
				title: is_string($issue['title'] ?? null) ? $issue['title'] : null,
				state: $this->mapState($issue['state'] ?? null),
				labels: $this->labelNames($issue['labels'] ?? null),
				urlCandidates: $this->issueUrlCandidates($owner, $repo, $number),
			);
		}

		// Not a pull_request/issues event (e.g. a ping) - accepted, nothing to do.
		return ForgeEvent::none();
	}

	/** The payload's raw state mapped onto the CardLink state enum. */
	private function mapState(mixed $rawState): string {
		return match (is_string($rawState) ? $rawState : '') {
			'open' => CardLink::STATE_OPEN,
			'closed' => CardLink::STATE_CLOSED,
			default => CardLink::STATE_UNKNOWN,
		};
	}

	/**
	 * The label names the payload lists, flattened for {@see ForgeEvent::hasLabel}.
	 *
	 * @return string[]
	 */
	private function labelNames(mixed $labels): array {
		if (!is_array($labels)) {
			return [];
		}
		$names = [];
		foreach ($labels as $label) {
			if (is_array($label) && is_string($label['name'] ?? null)) {
				$names[] = $label['name'];
			}
		}
		return $names;
	}

	/**
	 * The URL spellings under which a github.com issue may have been attached
	 * as a link (host www or not, trailing slash or not). Matching by candidate
	 * set keeps the reverse lookup a plain indexed `url IN (...)` while still
	 * being repo + issue-number based.
	 *
	 * @return string[]
	 */
	private function issueUrlCandidates(string $owner, string $repo, int $number): array {
		$path = '/' . $owner . '/' . $repo . '/issues/' . $number;
		return [
			'https://github.com' . $path,
			'https://github.com' . $path . '/',
			'https://www.github.com' . $path,
			'https://www.github.com' . $path . '/',
		];
	}
}
