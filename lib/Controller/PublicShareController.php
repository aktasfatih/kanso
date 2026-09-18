<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\AppInfo\Application;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PublicShareExpiredException;
use OCA\Kanso\Service\PublicShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

/**
 * Public / read-only board share links (#3531).
 *
 * MANAGE-only config endpoints (config/enable/disable) mint, rotate and revoke a
 * board's public token, and TWO unauthenticated endpoints serve the shared board:
 *  - {@see self::data()} returns the STRIPPED JSON payload for a token, and
 *  - {@see self::show()} renders the read-only SPA shell for a token.
 *
 * Both public endpoints are `#[PublicPage] #[NoCSRFRequired]` with
 * `#[BruteForceProtection]`; an unknown/disabled/rotated/expired token is a
 * throttled 404 so the token space can't be enumerated. The one distinction the
 * page route draws (#10466) is EXPIRED vs everything-else, and only in the
 * rendered message - status and throttle are identical. The config endpoints are
 * `#[NoAdminRequired]` (a normal authenticated route) and are gated by MANAGE in
 * the service - they are NEVER public.
 */
class PublicShareController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private PublicShareService $publicShareService,
	) {
		parent::__construct($appName, $request);
	}

	// ── MANAGE config (authenticated) ─────────────────────────────────────────

	#[NoAdminRequired]
	public function config(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->publicShareService->getConfig($id, $this->currentUserId())
			);
		});
	}

	/**
	 * Enable the public link (or rotate an existing one) - both mint a fresh
	 * token, invalidating any previously-issued link.
	 */
	#[NoAdminRequired]
	public function enable(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->publicShareService->enable($id, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function disable(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->publicShareService->disable($id, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * Sets the "show comments (read-only)" opt-in for the public link (#3949).
	 * MANAGE-only; OFF by default. Widening an intentionally person-free surface
	 * is a deliberate owner action, so it is its own explicit endpoint.
	 */
	#[NoAdminRequired]
	public function setComments(int $id, bool $enabled = false): JSONResponse {
		return $this->respond(function () use ($id, $enabled): JSONResponse {
			return new JSONResponse(
				$this->publicShareService->setComments($id, $enabled, $this->currentUserId())
			);
		});
	}

	/**
	 * Sets (or clears) the public link's expiry (#10466). MANAGE-only, like every
	 * other public-link operation.
	 *
	 * `expiresAt` is an ABSOLUTE unix timestamp in SECONDS; `null` clears it. The
	 * client resolves the picked calendar day to the end of that day in the
	 * BROWSER's timezone, so the boundary belongs to whoever set it - the server
	 * only ever compares two instants.
	 *
	 * Declared `mixed`, NOT `?int`, on purpose: the AppFramework dispatcher casts
	 * a declared `int` param, and `(int)'garbage'` is 0, which this service reads
	 * as "no expiry". A malformed request would then answer 200 and CLEAR a live
	 * expiry - a parse failure that leaves a public link open. Taking the raw value
	 * and validating it in the service ({@see PublicShareService::setExpiry()})
	 * makes that a 400 instead.
	 */
	#[NoAdminRequired]
	public function setExpiry(int $id, mixed $expiresAt = null): JSONResponse {
		return $this->respond(function () use ($id, $expiresAt): JSONResponse {
			return new JSONResponse(
				$this->publicShareService->setExpiry($id, $expiresAt, $this->currentUserId())
			);
		});
	}

	// ── Public read-only (unauthenticated) ────────────────────────────────────

	/**
	 * The STRIPPED read-only board payload for a token. No session. An unknown /
	 * disabled / rotated / expired token is a throttled 404 so the token space
	 * can't be brute-forced or enumerated (same failure shape for every reason).
	 *
	 * The SUCCESS arm is deliberately NOT throttled, and deliberately carries no
	 * ETag. Both were proposed (#10302 item 2, #10379 item A) on the reading that
	 * an unauthenticated endpoint should not serve a full board build for free.
	 * Both were measured and argued down; this is the record, because the naive
	 * reading of each is attractive enough to be "fixed" back in.
	 *
	 * NO THROTTLE ON SUCCESS. `#[BruteForceProtection]` is not a rate limiter, it
	 * is a FAILURE counter: `$response->throttle()` makes BruteForceMiddleware
	 * call `IThrottler::registerAttempt()`, and the next request from that address
	 * sleeps `0.1 * 2^attempts` seconds (`Throttler::calculateDelay`), capped at
	 * `IThrottler::MAX_DELAY` = 25s, with a hard `TooManyRequestsResponse` once
	 * `MAX_ATTEMPTS` = 10 is passed inside 30 minutes. Registering a SUCCESSFUL
	 * read there would therefore make the 5th view of a shared board from one
	 * address sleep ~3s, the 8th sleep the full 25s, and the 11th inside half an
	 * hour a flat 429 - for visitors doing nothing but reading a link they were
	 * given. A NAT'd office, a school, a conference wifi and a single recipient
	 * reloading all share one address, so the throttle would fire on exactly the
	 * audience the link exists for. The counter belongs on the 404 arm and only
	 * there: it defends the TOKEN SPACE, which is the thing an attacker can
	 * actually probe. Reaching the success arm already requires the board's real
	 * 64-char ISecureRandom token, and the owner can rotate or revoke it.
	 *
	 * NO ETAG. Conditional reads pay where a client replays the validator - the
	 * ICS feed ({@see CalendarFeedController::feed()}), where calendar clients
	 * poll on a schedule and send `If-None-Match` natively, and the authenticated
	 * board read, where `fetchBoard` in src/services/api.js replays it off the
	 * cached payload. This route has no such caller and cannot cheaply grow one:
	 * the public SPA fetches once in `mounted()` (src/views/PublicBoard.vue) and
	 * holds no cross-load cache, and Nextcloud's `Response` ships
	 * `Cache-Control: no-cache, no-store, must-revalidate` by default, so the
	 * browser never caches this JSON and so never revalidates it on its own. A
	 * validator nobody sends is answered by nobody, and it would not blunt the
	 * loop-reader the proposal was aimed at either - that caller simply omits the
	 * header.
	 *
	 * Worse, the obvious validator would be WRONG here. The board's latest
	 * `kanso_changes` id covers the authenticated payload, but this one also
	 * carries data that log does not describe: the comments opt-in and the expiry
	 * are written straight through `BoardMapper` ({@see PublicShareService} takes
	 * no ChangeNotifier at all, so it cannot append a row), and every display name
	 * this payload substitutes for a `@mention` or a comment byline lives in the
	 * user backend. Verified on the dev stack: renaming a mentioned member changed
	 * the anonymous payload while the board's latest change id stayed put, i.e. a
	 * change-id ETag would have served a stale 304 for as long as the board sat
	 * idle. Getting it right means a composite validator plus a display-name
	 * window - real machinery, for a caller that does not exist.
	 *
	 * What the read actually costs, measured on the dev stack (Postgres, 20
	 * samples): 29ms and 11 DB statements for a 200-card board, 34ms and 127
	 * statements for the same board with 600 mentions of a 10-person team. That is
	 * the number any future proposal here has to beat.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'kansoPublicShare')]
	public function data(string $token): JSONResponse {
		try {
			return new JSONResponse($this->publicShareService->getPublicBoard($token));
		} catch (DoesNotExistException) {
			$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'kansoPublicShare']);
			return $response;
		}
	}

	/**
	 * Renders the read-only SPA shell for a public board. No session, no auth
	 * chrome. The token is validated here too (throttled 404 on a bad token) so
	 * the page never loads for an invalid link; the client then reads
	 * {@see self::data()} for the payload.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'kansoPublicShare')]
	public function show(string $token): Http\Response {
		try {
			// Validate the token before rendering anything - a bad token must not
			// even get the app shell, and this is throttled like the data route.
			// A lightweight existence check (not a full payload build) so the
			// unauthenticated page route can't be used to amplify board queries;
			// the client fetches the real payload via data() once the shell loads.
			$board = $this->publicShareService->assertTokenValid($token);
		} catch (PublicShareExpiredException) {
			// #10466: an expired link used to be indistinguishable from a wrong URL,
			// so a recipient whose link simply ran out would go back to the owner
			// asking them to re-check the address. Name the cause instead. Reaching
			// this page requires the board's real, current token (a rotated or
			// disabled one resolves to nothing and still gets the generic page), so
			// it tells nobody anything they were not already handed - but keep the
			// 404 status and the throttle, unchanged, so the token space stays as
			// un-enumerable as it was. MUST stay above the DoesNotExistException arm:
			// it is a subclass, and PHP matches catch arms in order.
			$response = new TemplateResponse(
				Application::APP_ID,
				'public-expired',
				[],
				TemplateResponse::RENDER_AS_GUEST
			);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'kansoPublicShare']);
			return $response;
		} catch (DoesNotExistException) {
			$response = new TemplateResponse(
				Application::APP_ID,
				'public-notfound',
				[],
				TemplateResponse::RENDER_AS_GUEST
			);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'kansoPublicShare']);
			return $response;
		}

		Util::addScript(Application::APP_ID, Application::APP_ID . '-public');
		// RENDER_AS_PUBLIC: the guest/public layout - no authenticated app
		// navigation, no user menu, no board chrome that assumes a session.
		//
		// `pageTitle` gives this page a <title> of its own (#10446). Without it
		// core's public layout falls through to the instance name alone, so EVERY
		// public link on the server shares one identical tab title and collides
		// with every other guest page - useless in a tab strip, a bookmark or
		// browser history. The layout renders it as "<board> - <instance>", and it
		// is set SERVER-side rather than from the Vue app so the tab is right at
		// first paint (this is the page most likely to be opened cold, on a slow
		// connection) and stays right with JS disabled. The public entry point is
		// deliberately router-free, so there is no client-side navigation that
		// could later invalidate a server-rendered title.
		//
		// This discloses nothing new: the board name is already rendered in the
		// page body to this exact audience, and the URL that lands in the
		// recipient's history carries the share token, which is strictly more
		// sensitive than the name.
		return new TemplateResponse(
			Application::APP_ID,
			'public',
			['token' => $token, 'pageTitle' => $board->getTitle()],
			TemplateResponse::RENDER_AS_PUBLIC
		);
	}

	/**
	 * Serves ONE inline image embedded in a shared card's description or comment
	 * (#152), to an anonymous visitor holding the board's share token.
	 *
	 * The authenticated twin ({@see CardAttachmentController::inline()}) resolves
	 * the reader from the session, so a public-share visitor's `<img>` request
	 * answered 401 and the picture rendered as a broken box on a board that had
	 * been deliberately shared. This route swaps the session gate for the TOKEN
	 * gate and keeps everything else: {@see PublicShareService::getPublicInlineAttachment()}
	 * refuses any card the token's own payload does not already carry, honours the
	 * link's expiry, and still serves only the four allow-listed raster mimes.
	 *
	 * Response headers mirror the authenticated route exactly - the Content-Type
	 * is the stored, allow-listed mime (never client-echoed), `inline` disposition,
	 * and `nosniff` so the browser cannot re-read the bytes as a scriptable type.
	 *
	 * Every failure answers the SAME 404 with the same body, so this cannot be used
	 * to distinguish "wrong token" from "card on another board" from "not an image"
	 * - it is not an existence oracle for anything.
	 *
	 * WHAT IS AND IS NOT THROTTLED, and why the two arms are split. Only a TOKEN
	 * that fails to resolve registers a brute-force attempt. `#[BruteForceProtection]`
	 * is a FAILURE COUNTER shared with {@see self::show()} and {@see self::data()}
	 * under one action name, and `IThrottler` sleeps then 429s the whole ADDRESS
	 * once ten attempts land inside 30 minutes. Counting a card/attachment miss
	 * there would hand any EDIT member a remote kill switch for the board: twenty
	 * `![](…/cards/999999/attachments/1/inline)` references in one description fire
	 * twenty throttled 404s on every anonymous page load, and the first visitor's
	 * own browser would 429 them - and everyone else behind that address - straight
	 * off the board page. A single deleted attachment still referenced by a
	 * description would do the same, slowly, with nobody attacking anything. The
	 * counter belongs on the token space, which is the only thing an attacker can
	 * actually probe; reaching the second arm at all already requires the board's
	 * real 64-char token. This is the same reasoning recorded on {@see self::data()},
	 * and it binds harder here because ordinary readers can reach the failure arm.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'kansoPublicShare')]
	public function inlineAttachment(string $token, int $cardId, int $attachmentId): Http\Response {
		try {
			// Arm 1: the token space. Unknown / disabled / rotated / expired all land
			// here, and only here is the attempt counted.
			$this->publicShareService->assertTokenValid($token);
		} catch (DoesNotExistException) {
			$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'kansoPublicShare']);
			return $response;
		}

		try {
			[$attachment, $bytes] = $this->publicShareService->getPublicInlineAttachment(
				$token,
				$cardId,
				$attachmentId
			);
		} catch (DoesNotExistException) {
			// Arm 2: a real token, but the card/attachment is not part of this share.
			// Same 404, same body - NOT throttled (see above). Deliberately not a
			// `\Throwable` catch: the service raises this one type for every refusal,
			// so a broader catch would only turn a real server fault into a silent
			// 404 and hide it from the log.
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$response = new DataDisplayResponse(
			$bytes,
			Http::STATUS_OK,
			['Content-Type' => $attachment->getMime()]
		);
		$response->addHeader('Content-Disposition', 'inline');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
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
