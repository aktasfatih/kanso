<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ParticipantService;
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
		private ChangeMapper $changeMapper,
		private StackMapper $stackMapper,
		private CardMapper $cardMapper,
		private LabelMapper $labelMapper,
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			return new JSONResponse($this->boardService->findAll($this->currentUserId()));
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
	 * Full board payload: the board, its stacks, its labels and card
	 * SUMMARIES (no descriptions — those load on card open; each summary
	 * carries its labelIds and assigneeIds). The board's latest change id
	 * doubles as ETag: on an If-None-Match hit we return 304 before touching
	 * the stack/card/label/assignee tables at all.
	 */
	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$board = $this->boardService->find($id, $this->currentUserId());

			$etag = (string)$this->changeMapper->getLatestChangeId($id);
			if ($this->matchesIfNoneMatch($etag)) {
				$response = new JSONResponse([], Http::STATUS_NOT_MODIFIED);
				$response->setETag($etag);
				return $response;
			}

			$labelIdsByCard = $this->cardLabelMapper->findLabelIdsByBoard($id);
			$assigneesByCard = $this->cardAssigneeMapper->findUserIdsByBoard($id);
			$response = new JSONResponse([
				'board' => $board,
				'stacks' => $this->stackMapper->findByBoard($id),
				'cards' => array_map(
					static fn (Card $card): array => $card->jsonSerializeSummary()
						+ ['labelIds' => $labelIdsByCard[$card->getId()] ?? []]
						+ ['assigneeIds' => $assigneesByCard[$card->getId()] ?? []],
					$this->cardMapper->findSummariesByBoard($id)
				),
				'labels' => $this->labelMapper->findByBoard($id),
			]);
			$response->setETag($etag);
			return $response;
		});
	}

	/**
	 * All users with access to the board — the assignee-picker data source.
	 */
	#[NoAdminRequired]
	public function participants(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->participantService->getParticipants($id, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function update(int $id, ?string $title = null, ?string $color = null, ?bool $archived = null): JSONResponse {
		return $this->respond(function () use ($id, $title, $color, $archived): JSONResponse {
			return new JSONResponse(
				$this->boardService->update($id, $title, $color, $archived, $this->currentUserId())
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
