<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\UserSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user Kanso preferences, stored in the NC user config (app 'kanso'):
 * "default board on start" (an empty value means the board list), plus the
 * view preferences {@see UserSettingsService} owns.
 *
 * The storage itself lives in that service, because the page controllers embed
 * the very same payload in the app shell as initial state (#10460) so the first
 * paint already uses the user's layout. This controller owns only the HTTP
 * contract on top of it.
 *
 * Every preference is saved on its own, by whichever control owns it, so the
 * write path here touches ONLY the keys present in the request body. See
 * update() - that rule is the endpoint's contract, not an implementation detail.
 */
class SettingsController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private UserSettingsService $settings,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The current user's Kanso preferences.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			return new JSONResponse($this->settings->readAll($this->currentUserId()));
		});
	}

	/**
	 * Updates the preferences the client actually sent, and only those.
	 *
	 * **A key the request body omits is left untouched; sending it with `null`
	 * clears it.** That distinction is the whole contract here: every control in
	 * the settings dialog saves just its own key, so anything that wrote on every
	 * request would wipe the other preferences on each unrelated toggle (which is
	 * exactly how "default board on start" used to vanish behind the user's back).
	 *
	 * Per key, when sent:
	 * - `defaultBoardId` - which board the app opens to; `null`/0 clears it (the
	 *   app opens to the board list). Board existence is NOT validated here - the
	 *   client falls back to the board list if the stored board is gone.
	 * - `collapsedBoardGroups` - replaces the set of collapsed nav folders (#3529).
	 * - `dismissedHints` - replaces the set of dismissed one-time hints (#3413).
	 * - `hiddenNavSections` - replaces the set of hidden left-nav sections (#69).
	 * - `editorToolbarHidden` - editor formatting-toolbar visibility.
	 * - `cardDiscussionPosition` - 'side' or 'bottom' (#10408); an unknown value
	 *   resets it to 'side'.
	 *
	 * `null` on any of the list/flag keys resets it to its default (empty list,
	 * toolbar shown, discussion beside the card).
	 *
	 * @param ?int[] $collapsedBoardGroups
	 * @param ?string[] $dismissedHints
	 * @param ?string[] $hiddenNavSections
	 * @param ?bool $editorToolbarHidden
	 * @param ?string $cardDiscussionPosition
	 */
	#[NoAdminRequired]
	public function update(?int $defaultBoardId = null, ?array $collapsedBoardGroups = null, ?array $dismissedHints = null, ?array $hiddenNavSections = null, ?bool $editorToolbarHidden = null, ?string $cardDiscussionPosition = null): JSONResponse {
		// Which keys the client actually sent. It has to come from the raw request
		// body: once the dispatcher has filled the arguments above, an omitted key
		// and an explicit `null` are the same value, and "omit" must not mean
		// "clear". `array_key_exists` (not `isset`, and not IRequest::getParam()'s
		// sentinel-default trick, which is `isset`-based and so reports an explicit
		// null as absent) is what keeps a sent-but-null key visible here.
		$sent = $this->request->getParams();
		return $this->respond(function () use ($sent, $defaultBoardId, $collapsedBoardGroups, $dismissedHints, $hiddenNavSections, $editorToolbarHidden, $cardDiscussionPosition): JSONResponse {
			$uid = $this->currentUserId();

			if (array_key_exists('defaultBoardId', $sent)) {
				$this->settings->setDefaultBoard($uid, $defaultBoardId);
			}

			if (array_key_exists('collapsedBoardGroups', $sent)) {
				$this->settings->setCollapsedGroups($uid, $collapsedBoardGroups ?? []);
			}

			if (array_key_exists('dismissedHints', $sent)) {
				$this->settings->setDismissedHints($uid, $dismissedHints ?? []);
			}

			if (array_key_exists('hiddenNavSections', $sent)) {
				$this->settings->setHiddenNav($uid, $hiddenNavSections ?? []);
			}

			if (array_key_exists('editorToolbarHidden', $sent)) {
				$this->settings->setEditorToolbarHidden($uid, $editorToolbarHidden ?? false);
			}

			if (array_key_exists('cardDiscussionPosition', $sent)) {
				$this->settings->setDiscussionPosition($uid, $cardDiscussionPosition);
			}

			// Always answer with what is actually stored now, so the client sees the
			// untouched keys exactly as they are rather than the request echoed back.
			return new JSONResponse($this->settings->readAll($uid));
		});
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
