<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\CardLink;
use OCA\Kanso\Service\Forge\ForgeEvent;

/**
 * Inbound Forgejo (and Gitea) webhooks. A board opts in by generating a
 * per-board secret (MANAGE) and pointing a Forgejo webhook sending
 * `pull_request` and `issues` events at its endpoint. Everything after the
 * delivery is understood is shared with GitHub - see
 * {@see AbstractForgeWebhookService}.
 *
 * DELIVERY-ONLY BY DESIGN. Kanso never calls a Forgejo instance: no base URL is
 * stored, no token, no outbound HTTP. A link's state comes from the deliveries
 * themselves, which are authoritative and free. The trade-off is explicit - a
 * link pasted for something that never changes again stays `unknown`, because
 * no delivery will ever mention it. Fixing that needs a per-board API token
 * (most self-hosted instances are private, so an unauthenticated poll would 404
 * anyway), which is a new secret-at-rest surface and deliberately out of scope
 * until someone asks for it.
 *
 * What differs from GitHub:
 *
 *  - Signature. Forgejo sends `X-Forgejo-Signature` / `X-Gitea-Signature` as a
 *    RAW lowercase hex digest with no `sha256=` prefix, and also emits the
 *    GitHub-compatible `X-Hub-Signature-256`. All three are accepted. A hook
 *    saved WITHOUT a secret still sends the headers, empty - so an empty digest
 *    must be rejected, not treated as absent-and-fine.
 *  - URL layout. Forgejo uses `/{owner}/{repo}/pulls/{n}` (plural). Issues and
 *    PRs share ONE number sequence and each redirects to the other, so a user
 *    can legitimately paste `/issues/5` for a PR - both spellings are therefore
 *    reverse-lookup candidates.
 *  - Host. Self-hosted, so nothing may be pinned; the delivery's own html_url is
 *    the match key, which needs no host knowledge at all.
 */
class ForgejoWebhookService extends AbstractForgeWebhookService {
	/** A raw hex HMAC-SHA256 digest is exactly this many lowercase hex chars. */
	private const HEX_DIGEST_LENGTH = 64;

	#[\Override]
	protected function routeName(): string {
		return 'kanso.webhook.forgejo';
	}

	#[\Override]
	protected function provider(): string {
		return CardLink::PROVIDER_FORGEJO;
	}

	// ---- config columns ----------------------------------------------------

	#[\Override]
	protected function readSecret(Board $board): ?string {
		return $board->getForgejoWebhookSecret();
	}

	#[\Override]
	protected function writeSecret(Board $board, ?string $secret): void {
		$board->setForgejoWebhookSecret($secret);
	}

	#[\Override]
	protected function readIntakeStackId(Board $board): ?int {
		return $board->getForgejoIntakeStackId();
	}

	#[\Override]
	protected function writeIntakeStackId(Board $board, ?int $stackId): void {
		$board->setForgejoIntakeStackId($stackId);
	}

	#[\Override]
	protected function readIntakeLabel(Board $board): ?string {
		return $board->getForgejoIntakeLabel();
	}

	#[\Override]
	protected function writeIntakeLabel(Board $board, ?string $label): void {
		$board->setForgejoIntakeLabel($label);
	}

	// ---- signature ---------------------------------------------------------

	/**
	 * Constant-time HMAC-SHA256 verification against the board's secret.
	 *
	 * Rejects on SHAPE before comparing: a raw digest is exactly 64 lowercase
	 * hex chars, so the empty header a secret-less Forgejo hook sends falls out
	 * as a special case of malformed rather than needing its own branch. The
	 * GitHub-compatible `sha256=<hex>` spelling is accepted too, since Forgejo
	 * emits it alongside its own header.
	 *
	 * @throws NotPermittedException if the webhook is disabled or the signature is absent/invalid
	 */
	#[\Override]
	protected function verifySignature(Board $board, string $signatureHeader, string $rawBody): void {
		$secret = $this->readSecret($board) ?? '';
		if ($secret === '') {
			throw new NotPermittedException('Webhook is not enabled for this board');
		}

		$candidate = strtolower(trim($signatureHeader));
		// Accept the GitHub-compatible spelling Forgejo also sends.
		if (str_starts_with($candidate, 'sha256=')) {
			$candidate = substr($candidate, 7);
		}
		if (preg_match('/^[0-9a-f]{' . self::HEX_DIGEST_LENGTH . '}$/', $candidate) !== 1) {
			throw new NotPermittedException('Invalid webhook signature');
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);
		if (!hash_equals($expected, $candidate)) {
			throw new NotPermittedException('Invalid webhook signature');
		}
	}

	// ---- payload -----------------------------------------------------------

	/**
	 * Maps a Forgejo/Gitea delivery onto the shared event shape. The payloads are
	 * largely GitHub-compatible, so the differences absorbed here are narrow:
	 * a merge may arrive as `action: closed` + `merged: true`, as a non-empty
	 * `merged_at`, or as `action: merged`, depending on generation. Anything
	 * unrecognized becomes KIND_NONE and is an accepted no-op - drift therefore
	 * costs a MISSING move, never a wrong one.
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
			$merged = ($pr['merged'] ?? false) === true
				|| !empty($pr['merged_at'])
				|| $action === 'merged';
			$htmlUrl = is_string($pr['html_url'] ?? null) ? $pr['html_url'] : '';

			return new ForgeEvent(
				kind: ForgeEvent::KIND_PR,
				action: $action,
				branch: $branch,
				htmlUrl: $htmlUrl,
				title: is_string($pr['title'] ?? null) ? $pr['title'] : null,
				state: $merged ? CardLink::STATE_MERGED : $this->mapState($pr['state'] ?? null),
				merged: $merged,
				urlCandidates: $this->urlCandidates($htmlUrl),
			);
		}

		$issue = $payload['issue'] ?? null;
		if (is_array($issue)) {
			$issueUrl = is_string($issue['html_url'] ?? null) ? $issue['html_url'] : '';
			$candidates = $this->urlCandidates($issueUrl);
			if ($candidates === []) {
				return ForgeEvent::none();
			}

			return new ForgeEvent(
				kind: ForgeEvent::KIND_ISSUE,
				action: $action,
				htmlUrl: $issueUrl,
				title: is_string($issue['title'] ?? null) ? $issue['title'] : null,
				state: $this->mapState($issue['state'] ?? null),
				labels: $this->labelNames($issue['labels'] ?? null),
				urlCandidates: $candidates,
			);
		}

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
	 * The URL spellings under which this delivery's issue/PR may have been
	 * attached as a link. Host-agnostic by design - the delivery's own html_url
	 * is the key, because a self-hosted instance has no host we could pin.
	 *
	 * BOTH `/issues/{n}` and `/pulls/{n}` are candidates: Forgejo shares one
	 * number sequence between issues and PRs and redirects each spelling to the
	 * other, so someone can legitimately paste `/issues/5` for a PR whose
	 * deliveries then carry `/pulls/5`. Matching only the delivered spelling
	 * would leave that link stuck on `unknown` forever. Unambiguous precisely
	 * because the sequence is shared.
	 *
	 * @return string[] empty when the URL is not a recognizable issue/PR URL
	 */
	private function urlCandidates(string $url): array {
		$url = trim($url);
		if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
			return [];
		}
		if (preg_match('#^(.*)/(?:issues|pulls|pull)/(\d+)/?$#', $url, $m) !== 1) {
			return [];
		}
		$base = $m[1];
		$number = (int)$m[2];

		$candidates = [];
		foreach (['issues', 'pulls'] as $segment) {
			$candidates[] = $base . '/' . $segment . '/' . $number;
			$candidates[] = $base . '/' . $segment . '/' . $number . '/';
		}
		return $candidates;
	}
}
