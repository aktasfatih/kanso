<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Integration;

use OC\AppFramework\Http\Request;
use OC\AppFramework\Middleware\Security\BruteForceMiddleware;
use OC\AppFramework\Utility\ControllerMethodReflector;
use OC\Security\Bruteforce\Backend\IBackend;
use OC\SystemConfig;
use OCA\Kanso\AppInfo\Application;
use OCA\Kanso\Controller\WebhookController;
use OCA\Kanso\Db\CardLinkMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Service\AbstractForgeWebhookService;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardMutationService;
use OCA\Kanso\Service\ForgejoWebhookService;
use OCA\Kanso\Service\GithubWebhookService;
use OCA\Kanso\Service\StackService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequestId;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;
use OCP\Server;
use Psr\Log\LoggerInterface;

if (getenv('KANSO_INTEGRATION_DISPOSABLE') !== '1') {
	throw new \RuntimeException('Set KANSO_INTEGRATION_DISPOSABLE=1 only for a disposable Nextcloud installation');
}
$nextcloudRoot = getenv('NEXTCLOUD_ROOT');
if ($nextcloudRoot === false || !is_file($nextcloudRoot . '/lib/base.php')) {
	throw new \RuntimeException('NEXTCLOUD_ROOT must point to an installed Nextcloud checkout');
}
define('OC_CONSOLE', true);
require_once $nextcloudRoot . '/lib/base.php';
set_exception_handler(static function (\Throwable $e): void {
	fwrite(STDERR, (string)$e . "\n");
	exit(1);
});
\OC::$composerAutoloader->addPsr4('OCA\\Kanso\\', dirname(__DIR__, 2) . '/lib/', true);
\OC_App::loadApps();
$container = (new Application())->getContainer();
$config = Server::get(IConfig::class);
$systemConfig = Server::get(SystemConfig::class);
$throttler = Server::get(IThrottler::class);
$backend = Server::get(IBackend::class);
$userSession = Server::get(IUserSession::class);
$user = Server::get(IUserManager::class)->get('admin');
if ($user === null) {
	throw new \RuntimeException('The disposable installation must have an admin user');
}
$userSession->setUser($user);
\OC_Util::setupFS('admin');

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new \RuntimeException($message);
	}
}

function dispatch(Controller $controller, string $method, int $boardId, Request $request): Response {
	$reflector = new ControllerMethodReflector(Server::get(LoggerInterface::class));
	$reflector->reflect($controller, $method);
	$middleware = new BruteForceMiddleware($reflector, Server::get(IThrottler::class), $request, Server::get(LoggerInterface::class));
	try {
		$middleware->beforeController($controller, $method);
		return $middleware->afterController($controller, $method, $controller->$method($boardId));
	} catch (MaxDelayReached $e) {
		return $middleware->afterException($controller, $method, $e);
	}
}

function requestFor(string $ip, string $provider, string $signature): Request {
	$header = $provider === 'github' ? 'HTTP_X_HUB_SIGNATURE_256' : 'HTTP_X_FORGEJO_SIGNATURE';
	return new Request([
		'method' => 'POST',
		'server' => ['REMOTE_ADDR' => $ip, $header => $signature, 'CONTENT_TYPE' => 'application/json'],
	], Server::get(IRequestId::class), Server::get(IConfig::class));
}

function deliver(object $container, string $ip, string $provider, int $boardId, string $body, string $signature): Response {
	$request = requestFor($ip, $provider, $signature);
	$controller = new WebhookController('kanso', $request, Server::get(IUserSession::class),
		$container->get(GithubWebhookService::class), $container->get(ForgejoWebhookService::class));
	(new \ReflectionProperty($controller, 'rawBody'))->setValue($controller, $body);
	return dispatch($controller, $provider, $boardId, $request);
}

function sign(string $provider, string $body, string $secret): string {
	return ($provider === 'github' ? 'sha256=' : '') . hash_hmac('sha256', $body, $secret);
}

function snapshot(object $container, int $boardId): array {
	$cards = $container->get(CardMapper::class);
	$links = $container->get(CardLinkMapper::class);
	$result = [];
	foreach ($cards->findAllIdsByBoard($boardId) as $id) {
		$result[$id] = [$cards->find($id)->jsonSerialize(), array_map(static fn ($link) => $link->jsonSerialize(), $links->findByCard($id))];
	}
	return [$result, $container->get(ChangeMapper::class)->getLatestChangeId($boardId)];
}

foreach ([WebhookController::class, GithubWebhookService::class, ForgejoWebhookService::class, AbstractForgeWebhookService::class, CardMutationService::class] as $class) {
	$source = (new \ReflectionClass($class))->getFileName();
	$expected = dirname(__DIR__, 2) . '/lib/' . str_replace('\\', '/', substr($class, strlen('OCA\\Kanso\\'))) . '.php';
	check(realpath($source) === realpath($expected), 'Production class loaded outside this checkout: ' . $class);
	echo 'SOURCE ' . $class . ' ' . $source . ' sha256=' . hash_file('sha256', $source) . "\n";
}

$ip = '198.51.100.74';
$action = 'kansoWebhook';
$subnet = $ip . '/32';
check(!$throttler->isBypassListed($ip), 'The fixture IP must not bypass brute-force protection');
check($throttler->getAttempts($ip, $action) === 0, 'The fixture IP/action already has attempts; select a fresh disposable installation');
$savedConfig = [];
foreach (['auth.bruteforce.protection.enabled', 'auth.bruteforce.max-attempts'] as $key) {
	$savedConfig[$key] = ['present' => in_array($key, $systemConfig->getKeys(), true), 'value' => $config->getSystemValue($key)];
}
$failures = [];
try {
	$config->setSystemValue('auth.bruteforce.protection.enabled', true);
	$config->setSystemValue('auth.bruteforce.max-attempts', 3);
	check($config->getSystemValueBool('auth.bruteforce.protection.enabled'), 'Brute-force protection was not enabled');
	$services = ['github' => $container->get(GithubWebhookService::class), 'forgejo' => $container->get(ForgejoWebhookService::class)];
	$boards = $container->get(BoardService::class);
	$stacks = $container->get(StackService::class);
	foreach ($services as $provider => $service) {
		$backend->resetAttempts($subnet, $action);
		$fixtures = [];
		foreach (['A', 'B'] as $name) {
			$boardId = $boards->create('Webhook availability ' . $provider . ' ' . $name . ' ' . bin2hex(random_bytes(4)), null, 'admin')->getId();
			$stackId = $stacks->create($boardId, 'Intake', 'admin')->getId();
			$secrets = [];
			foreach ($services as $key => $forge) {
				$secrets[$key] = $forge->rotateSecret($boardId, 'admin')['secret'];
			}
			$service->setIntakeConfig($boardId, $stackId, '', 'admin');
			$url = $provider === 'github' ? 'https://github.com/kanso-fixture/availability/issues/1' : 'https://forge.example.org/kanso-fixture/availability/issues/1';
			$body = json_encode(['action' => 'opened', 'issue' => ['html_url' => $url, 'state' => 'open', 'title' => 'Intake once', 'labels' => []]], JSON_THROW_ON_ERROR);
			$fixtures[] = ['id' => $boardId, 'stack' => $stackId, 'secrets' => $secrets, 'body' => $body];
		}
		$userSession->setUser(null);
		[$a, $b] = $fixtures;
		$before = snapshot($container, $a['id']);
		$invalidStatuses = [];
		for ($i = 0; $i < 20; $i++) {
			$response = deliver($container, $ip, $provider, $a['id'], $a['body'], sign($provider, $a['body'], 'wrong-secret'));
			$invalidStatuses[] = $response->getStatus();
		}
		check(snapshot($container, $a['id']) === $before, 'An invalid-signature burst mutated board A');
		$validStatuses = [];
		for ($i = 0; $i < 100; $i++) {
			$fixture = $fixtures[$i % 2];
			$response = deliver($container, $ip, $provider, $fixture['id'], $fixture['body'], sign($provider, $fixture['body'], $fixture['secrets'][$provider]));
			$validStatuses[] = $response->getStatus();
		}
		echo $provider . ' ' . json_encode(['invalid' => array_count_values($invalidStatuses), 'valid' => array_count_values($validStatuses)], JSON_THROW_ON_ERROR) . "\n";
		if (array_unique($invalidStatuses) !== [401] || array_unique($validStatuses) !== [200]) {
			$failures[] = $provider . ': invalid requests must remain 401 and 100 valid deliveries to both boards must remain 200';
			continue;
		}
		foreach ($fixtures as $fixture) {
			$ids = $container->get(CardMapper::class)->findAllIdsByBoard($fixture['id']);
			check(count($ids) === 1, 'Repeated signed intake created duplicate cards');
			check(count($container->get(CardLinkMapper::class)->findByCard($ids[0])) === 1, 'Repeated signed intake created duplicate links');
		}

		for ($i = 0; $i < 5; $i++) {
			$throttler->registerAttempt($action, $ip, ['action' => $action]);
		}
		check($throttler->getAttempts($ip, $action) > 3, 'The old shared bucket was not populated');
		$control = new class('kanso', requestFor($ip, $provider, '')) extends Controller {
			#[BruteForceProtection(action: 'kansoWebhook')]
			public function accept(int $id): JSONResponse {
				return new JSONResponse([]);
			}
		};
		check(dispatch($control, 'accept', $a['id'], requestFor($ip, $provider, ''))->getStatus() === 429, 'The middleware positive control did not enforce the shared lockout');
		foreach ($fixtures as $fixture) {
			check(deliver($container, $ip, $provider, $fixture['id'], $fixture['body'], sign($provider, $fixture['body'], $fixture['secrets'][$provider]))->getStatus() === 200,
				'A pre-existing shared lockout blocked a valid delivery');
		}
		$before = snapshot($container, $a['id']);
		$other = $provider === 'github' ? 'forgejo' : 'github';
		foreach (['', 'malformed', sign($provider, $a['body'], $b['secrets'][$provider]), sign($provider, $a['body'], $a['secrets'][$other]), sign($provider, $a['body'] . ' ', $a['secrets'][$provider])] as $badSignature) {
			check(deliver($container, $ip, $provider, $a['id'], $a['body'], $badSignature)->getStatus() === 401, 'A missing, malformed, foreign or tampered signature was accepted');
		}
		check(snapshot($container, $a['id']) === $before, 'Rejected signatures changed cards, links or activity');
		$request = requestFor($ip, $provider, '');
		$controller = new WebhookController('kanso', $request, $userSession, $services['github'], $services['forgejo']);
		foreach ($provider === 'github' ? ['config', 'rotate', 'intake', 'disable'] : ['forgejoConfig', 'forgejoRotate', 'forgejoIntake', 'forgejoDisable'] as $method) {
			check($controller->$method($a['id'])->getStatus() === 403, 'Anonymous webhook settings access was permitted');
		}
		check(snapshot($container, $a['id']) === $before, 'Denied settings requests changed board data');
		$service->disable($a['id'], 'admin');
		check(deliver($container, $ip, $provider, $a['id'], $a['body'], sign($provider, $a['body'], $a['secrets'][$provider]))->getStatus() === 401, 'A disabled webhook accepted its old signature');
		echo 'PASS ' . $provider . " shared-bucket control, isolation, signature rejection, permissions and idempotent intake\n";
	}
	check($failures === [], implode("\n", $failures));
} finally {
	try {
		$backend->resetAttempts($subnet, $action);
	} finally {
		foreach ($savedConfig as $key => $saved) {
			if ($saved['present']) {
				$config->setSystemValue($key, $saved['value']);
			} else {
				$config->deleteSystemValue($key);
			}
		}
		$userSession->setUser($user);
	}
}
echo "OK webhook availability; disposable configuration restored\n";
