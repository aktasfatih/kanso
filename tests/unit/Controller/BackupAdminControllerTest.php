<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\BackupAdminController;
use OCA\Kanso\Service\BackupService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The gating around the backup endpoints (#161).
 *
 * A backup archive is built at SYSTEM scope - every card on the instance,
 * private ones included, plus every attachment - and with the app-data
 * destination the download endpoint is the ONLY way to retrieve one. So "who may
 * call this" is not an implementation detail of the controller; it is the
 * feature's security boundary, and it is asserted here by reflection so that
 * loosening it takes a deliberate, failing-test-breaking act rather than one
 * attribute added in passing.
 */
class BackupAdminControllerTest extends TestCase {
	/** The actions the controller exposes. Adding one is a conscious change. */
	private const ACTIONS = ['index', 'update', 'run', 'files', 'download', 'delete'];

	private function controller(BackupService $service, string $uid = 'alice'): BackupAdminController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new BackupAdminController('kanso', $this->createMock(IRequest::class), $service, $session);
	}

	public function testEveryActionIsAdminOnly(): void {
		$class = new \ReflectionClass(BackupAdminController::class);

		// Nothing at class level opens it up either.
		self::assertSame([], $class->getAttributes(NoAdminRequired::class));
		self::assertSame([], $class->getAttributes(PublicPage::class));

		$actions = [];
		foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isConstructor() || $method->getDeclaringClass()->getName() !== BackupAdminController::class) {
				continue;
			}
			$actions[] = $method->getName();
			self::assertSame(
				[],
				$method->getAttributes(NoAdminRequired::class),
				$method->getName() . '() must stay admin-only: a backup archive holds every private card on the instance',
			);
			self::assertSame(
				[],
				$method->getAttributes(PublicPage::class),
				$method->getName() . '() must never be a public page',
			);
		}

		sort($actions);
		$expected = self::ACTIONS;
		sort($expected);
		self::assertSame($expected, $actions);
	}

	public function testOnlyTheDownloadSkipsCsrf(): void {
		// The one deliberate exception, and it is about the response being a
		// plain link rather than about who may read it - the admin + session
		// checks still run. Everything that WRITES keeps CSRF.
		$class = new \ReflectionClass(BackupAdminController::class);
		foreach (self::ACTIONS as $action) {
			$attributes = $class->getMethod($action)->getAttributes(NoCSRFRequired::class);
			if ($action === 'download') {
				self::assertCount(1, $attributes, 'download() is linked to directly by the panel');
				continue;
			}
			self::assertSame([], $attributes, $action . '() must keep the CSRF check');
		}
	}

	public function testDownloadOfAnUnknownBackupIs404(): void {
		$service = $this->createMock(BackupService::class);
		$service->method('openBackup')->willThrowException(new NotFoundException('nope'));

		$response = $this->controller($service)->download('kanso-board-7-20260804-153000.zip');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testDownloadDoesNotReportAStorageFailureAsAMissingBackup(): void {
		// The regression this pins: a blanket catch(\Throwable) turned a dead
		// mount, an unreachable destination and an unopenable file into the same
		// "Backup not found" as a deleted archive, so an admin whose storage was
		// down went looking for a file that is still there. Only the not-found
		// case is a 404; a genuine failure surfaces as a server error.
		$service = $this->createMock(BackupService::class);
		$service->method('openBackup')
			->willThrowException(new \RuntimeException('Could not open backup kanso-board-7-20260804-153000.zip'));

		$this->expectException(\RuntimeException::class);
		$this->controller($service)->download('kanso-board-7-20260804-153000.zip');
	}

	public function testFilesReportsABrokenDestinationInsteadOfAnEmptyList(): void {
		// The same class of bug one layer down: listBackups() used to swallow an
		// unresolvable destination and answer [], so the panel rendered "No
		// backups stored yet." over an intact set of archives. It must be a
		// non-2xx, which is what the panel's error state keys off.
		$service = $this->createMock(BackupService::class);
		$service->method('listBackups')
			->willThrowException(new \RuntimeException('Backup account "backup-bot" has no files folder'));

		$response = $this->controller($service)->files();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		// Nothing a client could mistake for a complete listing.
		self::assertArrayNotHasKey('files', (array)$response->getData());
	}

	public function testFilesStillAnswers200ForAHealthyButEmptyDestination(): void {
		// "No backups yet" is a real, correct state and must stay distinguishable
		// from "could not read the destination".
		$service = $this->createMock(BackupService::class);
		$service->method('listBackups')->willReturn([]);
		$service->method('getDestination')->willReturn('appdata');

		$response = $this->controller($service)->files();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['destination' => 'appdata', 'files' => []], $response->getData());
	}

	/**
	 * Nextcloud's SecurityMiddleware decides "may this account call this action"
	 * from exactly two inputs: the #[NoAdminRequired] attribute on the method or
	 * its class, and whether the session user is in the admin group. Neither is
	 * reachable from a unit test - there is no app container here - so this
	 * reproduces that decision over the REAL controller's real attributes and
	 * asserts the answer for a non-admin caller.
	 *
	 * It is not decorative: adding #[NoAdminRequired] to delete() - the one edit
	 * that would actually open the endpoint up - flips this to "allowed" and
	 * fails the test. And delete() is the action that most needs it: it destroys
	 * an archive that, under the app-data destination, exists nowhere else.
	 */
	public function testDeleteIsRefusedToANonAdmin(): void {
		$class = new \ReflectionClass(BackupAdminController::class);
		// The middleware's own rule, in one line.
		$statusFor = static function (string $action, bool $callerIsAdmin) use ($class): int {
			$open = $class->getMethod($action)->getAttributes(NoAdminRequired::class) !== []
				|| $class->getAttributes(NoAdminRequired::class) !== [];
			return ($open || $callerIsAdmin) ? Http::STATUS_OK : Http::STATUS_FORBIDDEN;
		};

		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$statusFor('delete', false),
			'a non-admin must never be able to delete a stored backup',
		);
		// ...and the same gate as the download it mirrors, so the two can never
		// drift apart.
		self::assertSame(Http::STATUS_FORBIDDEN, $statusFor('download', false));
		// The control: an admin is not refused, so the assertion above is about
		// the gate rather than about everything being refused.
		self::assertSame(Http::STATUS_OK, $statusFor('delete', true));
	}

	public function testDeleteOfAnUnknownOrHostileNameIs404(): void {
		// deleteBackup() raises the same NotFoundException for a name that is not
		// an allow-listed Kanso backup name as for one with no file behind it, so
		// the endpoint never confirms which names are even well-formed.
		$service = $this->createMock(BackupService::class);
		$service->method('deleteBackup')->willThrowException(new NotFoundException('No such backup'));

		$response = $this->controller($service)->delete('../../../../etc/passwd');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testDeleteRemovesExactlyTheNamedBackupAndRecordsWhoAskedForIt(): void {
		// The audit line is the only trace a stored archive ever stopped
		// existing - under app data the file is in nobody's Files - so it has to
		// carry the administrator, not just the filename.
		$service = $this->createMock(BackupService::class);
		$service->expects(self::once())
			->method('deleteBackup')
			->with('kanso-board-7-20260804-153000.zip', 'alice');

		$response = $this->controller($service, 'alice')->delete('kanso-board-7-20260804-153000.zip');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDeleteDoesNotReportAStorageFailureAsADeletedBackup(): void {
		// The same distinction files() draws: a destination that could not be
		// reached is a server error, never "deleted". Answering 200 here would
		// tell an admin their archive is gone while it sits intact behind a dead
		// mount - and the body carries the reason, because a bare 500 leaves the
		// one person who can fix it without the sentence naming what broke.
		$service = $this->createMock(BackupService::class);
		$service->method('deleteBackup')
			->willThrowException(new \RuntimeException('Backup folder does not exist: /kanso-bakcups'));

		$response = $this->controller($service)->delete('kanso-board-7-20260804-153000.zip');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = (array)$response->getData();
		self::assertArrayNotHasKey('deleted', $data);
		self::assertStringContainsString('/kanso-bakcups', (string)($data['message'] ?? ''));
	}

	public function testDownloadStreamsWithoutAnEtag(): void {
		$stream = fopen('php://memory', 'r+b');
		fwrite($stream, 'PK-ZIP-BYTES');
		rewind($stream);
		$service = $this->createMock(BackupService::class);
		$service->method('openBackup')->willReturn([
			'stream' => $stream,
			'size' => 12,
			'name' => 'kanso-board-7-20260804-153000.zip',
		]);

		$response = $this->controller($service)->download('kanso-board-7-20260804-153000.zip');

		self::assertInstanceOf(StreamResponse::class, $response);
		// Read the raw header bag: Response::getHeaders() reaches into the server
		// container (for the CSP header) and there is none in a unit test.
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		/** @var array<string, string> $headers */
		$headers = $property->getValue($response);
		self::assertSame('application/zip', $headers['Content-Type']);
		self::assertSame(
			'attachment; filename="kanso-board-7-20260804-153000.zip"',
			$headers['Content-Disposition'],
		);
		self::assertSame('12', $headers['Content-Length']);
		self::assertSame('nosniff', $headers['X-Content-Type-Options']);
		// No ETag: an in-place same-second overwrite leaves the app-data ETag
		// unchanged while the bytes change, so revalidating on it could serve a
		// stale archive.
		self::assertNull($response->getETag());
		self::assertArrayNotHasKey('ETag', $headers);
		fclose($stream);
	}
}
