<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Db\AclMapper;
use OCA\Kanso\Db\CardFieldMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardRelationService;
use OCA\Kanso\Service\CardSummaryService;
use OCA\Kanso\Service\ContactService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ParticipantService;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class BoardController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private BoardService $boardService,
		private ParticipantService $participantService,
		private ContactService $contactService,
		private ChangeMapper $changeMapper,
		private StackMapper $stackMapper,
		private CardMapper $cardMapper,
		private LabelMapper $labelMapper,
		private ReviewTypeMapper $reviewTypeMapper,
		private CardFieldMapper $cardFieldMapper,
		private AclMapper $aclMapper,
		private PermissionService $permissionService,
		private SubscriptionService $subscriptionService,
		private BoardAccess $boardAccess,
		private CardSummaryService $cardSummaryService,
		private CardRelationService $cardRelationService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			return new JSONResponse($this->boardService->findAllWithStats($this->currentUserId()));
		});
	}

	#[NoAdminRequired]
	public function create(string $title = '', ?string $color = null): JSONResponse {
		return $this->respond(function () use ($title, $color): JSONResponse {
			return new JSONResponse(
				$this->boardService->create($title, $color, $this->currentUserId())
			);
		});
	}

	/**
	 * Full board payload: the board, its stacks, its labels, its sharing
	 * rules (`acl`), the requesting user's own permission bits and card
	 * SUMMARIES (no descriptions - those load on card open; each summary
	 * carries its labelIds and assigneeIds). The board's latest change id
	 * doubles as ETag: on an If-None-Match hit we return 304 before touching
	 * the stack/card/label/assignee/acl tables at all.
	 */
	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$uid = $this->currentUserId();
			$board = $this->boardService->find($id, $uid);
			// The viewer's resolved side on this board scopes every card row
			// and count below (#3743). Resolved once, after the READ gate.
			$viewer = $this->boardAccess->contextFor($board, $uid);

			$etag = (string)$this->changeMapper->getLatestChangeId($id);
			if ($this->matchesIfNoneMatch($etag)) {
				$response = new JSONResponse([], Http::STATUS_NOT_MODIFIED);
				$response->setETag($etag);
				return $response;
			}

			$response = new JSONResponse([
				'board' => $board,
				'stacks' => $this->stackMapper->findByBoard($id),
				'cards' => $this->cardSummaryService->serialize($id, $this->cardMapper->findSummariesByBoard($id, $viewer), $viewer),
				'labels' => $this->labelMapper->findByBoard($id),
				'reviewTypes' => $this->reviewTypeMapper->findByBoard($id),
				// Custom-field DEFINITIONS ride the board payload (#3537); their
				// per-card VALUES live only in the card detail payload.
				'cardFields' => $this->cardFieldMapper->findByBoard($id),
				// Board-scoped `blocks` edge list feeding the timeline's dependency
				// arrows - one query here instead of a relations fetch per card.
				// Visibility-masked WHOLE-EDGE: an edge with an endpoint this viewer
				// cannot see is dropped entirely, never half-emitted (the reasoning
				// lives on CardRelationService::blocksEdgesForBoard). The ETag above
				// already covers it - adding or removing a relation notifies BOTH
				// endpoint cards (ChangeNotifier), so new kanso_changes rows move the
				// board's latest change id, which IS this response's ETag.
				'blocksEdges' => $this->cardRelationService->blocksEdgesForBoard($board, $uid),
				'acl' => $this->aclMapper->findByBoard($id),
				// The requester's own bits, so the frontend can gate the
				// share/manage UI without re-deriving ACL semantics.
				'permissions' => $this->permissionService->getPermissions($board, $uid),
				// The requester's board side (#3744) - 'internal' or 'external'.
				// Gates the internal-only UI (export/duplicate) client-side; the
				// server enforces regardless.
				'role' => $viewer->role,
				// The requester's board-watch state {subscribed, subscribers, count}.
				'subscription' => $this->subscriptionService->buildBoardSubscription($id, $uid),
				// The board's latest change id - the same value as the ETag. Seeds
				// the client's delta-sync cursor from the body so it can poll
				// `?since=<cursor>` without parsing the ETag header.
				'cursor' => (int)$etag,
			]);
			$response->setETag($etag);
			return $response;
		});
	}

	/**
	 * Delta-sync read (#3675): the board changes since the client's cursor, so a
	 * single edit patches the client cache instead of forcing a whole-board
	 * refetch. Same ACL gate as {@see self::show()} (via BoardService::find). The
	 * response advances the client's cursor and carries per-entity upserts/removes:
	 *
	 *   {cursor, resync, cards:{upsert:[...], remove:[ids]}, stacks:{upsert:[...], remove:[ids]}}
	 *
	 * `resync:true` (a 200 with the flag, never an error status) tells the client
	 * to drop its cursor and do a full {@see self::show()} refetch. It fires when:
	 *   - the cursor is unusable (`since <= 0`, or below the board's retained tail
	 *     after pruning), so a delta would be incomplete;
	 *   - the window is saturated (client too far behind - more than the row cap);
	 *   - the window touched an entity kind this delta path does not (yet) model
	 *     (labels / acl / review-types / custom-fields / board itself). Those are
	 *     rare relative to card/stack edits, and a full refetch on one is an
	 *     acceptable MVP cut that avoids replicating the board-wide array
	 *     enrichment (labels list, acl, permissions, subscription) here.
	 *
	 * The cursor is ALWAYS the board's latest change id (even on an empty delta or
	 * a resync), so the client advances and a subsequent poll starts from there.
	 * No ETag: the request is already conditional via `since`.
	 */
	#[NoAdminRequired]
	public function changes(int $id, int $since = 0): JSONResponse {
		return $this->respond(function () use ($id, $since): JSONResponse {
			$uid = $this->currentUserId();
			// Same ACL gate show() trusts: throws NotPermitted (→403) / DoesNotExist
			// (→404), which ApiErrorTrait maps exactly as it does for show().
			$board = $this->boardService->find($id, $uid);
			$viewer = $this->boardAccess->contextFor($board, $uid);

			$latest = $this->changeMapper->getLatestChangeId($id);

			// Unusable cursor → resync. `since <= 0` is a client with no cursor yet;
			// a cursor below the board's oldest RETAINED change (minus one, so the
			// exact oldest row is still deliverable) has fallen off the pruned tail
			// and a delta from it would be incomplete.
			if ($since <= 0 || $since < $this->changeMapper->getOldestChangeId($id) - 1) {
				return new JSONResponse(['cursor' => $latest, 'resync' => true]);
			}

			$limit = 500;
			$rows = $this->changeMapper->findSince($id, $since, $limit);
			// Saturated window: the client is more than one page behind, so the
			// delta may be truncated - force a full refetch instead of a partial.
			if (count($rows) === $limit) {
				return new JSONResponse(['cursor' => $latest, 'resync' => true]);
			}

			// Collapse to the latest intent per (entity_type, entity_id): a card
			// created then moved then edited in the window is one upsert; a card
			// deleted last is a remove. We only need which cards / stacks to
			// re-serialize (or drop), and whether any out-of-scope kind appeared.
			$cardAction = [];
			$stackAction = [];
			foreach ($rows as $row) {
				switch ($row->getEntityType()) {
					case Change::ENTITY_CARD:
						$cardAction[$row->getEntityId()] = $row->getAction();
						break;
					case Change::ENTITY_STACK:
						$stackAction[$row->getEntityId()] = $row->getAction();
						break;
					default:
						// A label / acl / review-type / custom-field / board change
						// rode the window - the MVP scope cut: resync rather than
						// replicate board-wide array enrichment here.
						return new JSONResponse(['cursor' => $latest, 'resync' => true]);
				}
			}

			// Cards: re-serialize the ones still live (byte-identical to show()),
			// and remove those the summary query no longer returns - either the
			// last action was DELETE, or the row was deleted / turned into a
			// template between the cursor and now.
			$cardIds = array_keys($cardAction);
			// Visibility (#3743): findSummariesByIds is viewer-scoped, so a card
			// hidden from THIS viewer is absent from $liveCards and lands in the
			// remove list below - the client drops it from its cache, and its
			// change rows (entity_id/action only, no title) leak nothing.
			$liveCards = $this->cardSummaryService->serialize($id, $this->cardMapper->findSummariesByIds($id, $cardIds, $viewer), $viewer);
			$presentCardIds = array_flip(array_map(static fn (array $c): int => $c['id'], $liveCards));
			$removedCardIds = array_values(array_filter(
				$cardIds,
				static fn (int $cid): bool => !isset($presentCardIds[$cid])
			));

			// Stacks: same shape as show() (raw entity JSON), remove the absent ones.
			$liveStacks = $this->stackMapper->findByIds($id, array_keys($stackAction));
			$presentStackIds = array_flip(array_map(static fn ($s): int => $s->getId(), $liveStacks));
			$removedStackIds = array_values(array_filter(
				array_keys($stackAction),
				static fn (int $sid): bool => !isset($presentStackIds[$sid])
			));

			$payload = [
				'cursor' => $latest,
				'resync' => false,
				'cards' => [
					'upsert' => $liveCards,
					'remove' => $removedCardIds,
				],
				'stacks' => [
					'upsert' => $liveStacks,
					'remove' => $removedStackIds,
				],
			];

			// Dependency arrows are NOT derivable from the card summaries above, so
			// a delta that changed cards must also refresh them. A relation add or
			// remove always lands ENTITY_CARD rows for BOTH endpoints in the window,
			// so "window non-empty" is a sound (if slightly eager) trigger: we resend
			// the whole freshly-masked list and the client replaces its copy - far
			// cheaper than modelling per-edge upsert/remove for a list this small.
			// An EMPTY window omits the key entirely - the client keeps what it has,
			// which keeps the idle delta poll free of this extra query. The resync
			// early-returns above deliberately skip it too: the client refetches via
			// show(), which carries the list anyway.
			if ($rows !== []) {
				$payload['blocksEdges'] = $this->cardRelationService->blocksEdgesForBoard($board, $uid);
			}

			return new JSONResponse($payload);
		});
	}

	/**
	 * All users with access to the board - the assignee-picker data source.
	 * Bounded server-side: an optional `?q` filters by display name / uid and
	 * the result is always capped, so a board shared with a very large group
	 * cannot balloon the picker payload.
	 */
	#[NoAdminRequired]
	public function participants(int $id, ?string $q = null): JSONResponse {
		return $this->respond(function () use ($id, $q): JSONResponse {
			return new JSONResponse(
				$this->participantService->getParticipants($id, $this->currentUserId(), $q)
			);
		});
	}

	/**
	 * Contact-picker data source (#3530): searches the requester's address books
	 * for the card contact picker. Requires READ on the board (board-scoped, same
	 * gate as the assignee picker). Returns an empty list when the optional
	 * Contacts app is disabled - the picker hides itself rather than erroring.
	 */
	#[NoAdminRequired]
	public function contacts(int $id, ?string $q = null): JSONResponse {
		return $this->respond(function () use ($id, $q): JSONResponse {
			return new JSONResponse(
				$this->contactService->search($id, $this->currentUserId(), $q)
			);
		});
	}

	/**
	 * `$cardFeatures` is a PARTIAL built-in card feature patch (#5894), e.g.
	 * `{"attachments": false}`; omitted keys keep their current value. MANAGE-only,
	 * enforced (like every other field here) in BoardService.
	 *
	 * @param array<array-key, mixed>|null $cardFeatures
	 */
	#[NoAdminRequired]
	public function update(int $id, ?string $title = null, ?string $color = null, ?bool $archived = null, ?string $estimateScale = null, ?bool $newCardsOnTop = null, ?string $prefix = null, ?string $background = null, ?string $chatUrl = null, ?array $cardFeatures = null): JSONResponse {
		return $this->respond(function () use ($id, $title, $color, $archived, $estimateScale, $newCardsOnTop, $prefix, $background, $chatUrl, $cardFeatures): JSONResponse {
			return new JSONResponse(
				$this->boardService->update($id, $title, $color, $archived, $this->currentUserId(), $estimateScale, $newCardsOnTop, $prefix, $background, $chatUrl, $cardFeatures)
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->boardService->delete($id, $this->currentUserId())
			);
		});
	}

	/**
	 * Compares the normalized If-None-Match request header (surrounding
	 * quotes and weak-validator prefix stripped) against the current ETag.
	 */
	private function matchesIfNoneMatch(string $etag): bool {
		$header = trim($this->request->getHeader('If-None-Match'));
		if ($header === '') {
			return false;
		}
		if (str_starts_with($header, 'W/')) {
			$header = substr($header, 2);
		}
		return trim($header, '"') === $etag;
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
