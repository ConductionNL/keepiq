<?php

/**
 * Golden outcomes of the breach range proxy.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\BreachProxyController;
use OCA\Keepiq\Service\Connection\ConnectionReporter;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Who is refused with which status and message, and what an allowed lookup
 * reads, fetches, caches, reports and logs, in order.
 *
 * @covers \OCA\Keepiq\Controller\BreachProxyController
 */
final class BreachProxyRangeGoldenTest extends TestCase {
	/**
	 * Every collaborator call, in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The scenarios: signed in, gate on, prefix, cached value, upstream
	 * ('ok' or 'throw'), the expected status and data, and the expected calls.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function scenarios(): array {
		$gate = ['gate', 'keepiq', 'breach_check_enabled', false];
		return [
			'signed out'     => [false, true, 'abcde', null, 'ok', 401, ['message' => 'Unauthorized'], []],
			'gate off'       => [true, false, 'abcde', null, 'ok', 403, ['message' => 'Breach checking is disabled on this instance'], [$gate]],
			'short prefix'   => [true, true, 'abcd', null, 'ok', 400, ['message' => 'prefix must be exactly 5 hexadecimal characters'], [$gate]],
			'non-hex prefix' => [true, true, 'abcdg', null, 'ok', 400, ['message' => 'prefix must be exactly 5 hexadecimal characters'], [$gate]],
			'cached'         => [true, true, ' abcde ', 'CACHED', 'ok', 200, ['suffixes' => 'CACHED'], [$gate, ['cacheGet', 'ABCDE']]],
			'fetched'        => [true, true, 'abcde', null, 'ok', 200, ['suffixes' => 'BODY'], [
				$gate, ['cacheGet', 'ABCDE'],
				['get', 'https://api.pwnedpasswords.com/range/ABCDE', ['timeout' => 5, 'headers' => ['Add-Padding' => 'true']]],
				['report', 200], ['cacheSet', 'ABCDE', 'BODY', 43200],
			]],
			'upstream fails' => [true, true, 'abcde', null, 'throw', 503, ['message' => 'Breach service unavailable'], [
				$gate, ['cacheGet', 'ABCDE'],
				['get', 'https://api.pwnedpasswords.com/range/ABCDE', ['timeout' => 5, 'headers' => ['Add-Padding' => 'true']]],
				['httpStatusOf', 'RuntimeException'],
				['warning', 'Keepiq: HIBP range lookup failed: RuntimeException (HTTP 429)', ['app' => 'keepiq']],
				['report', 429],
			]],
		];
	}//end scenarios()

	/**
	 * A range lookup is refused or answered exactly as pinned.
	 *
	 * @param bool        $signedIn Whether a user is signed in
	 * @param bool        $enabled  Whether the admin gate is on
	 * @param string      $prefix   The requested prefix
	 * @param string|null $cached   The cached suffixes, or null
	 * @param string      $upstream 'ok' or 'throw'
	 * @param int         $status   The expected HTTP status
	 * @param array       $data     The expected response data
	 * @param array       $calls    The expected calls
	 *
	 * @dataProvider scenarios
	 *
	 * @return void
	 */
	public function testRange(bool $signedIn, bool $enabled, string $prefix, ?string $cached, string $upstream, int $status, array $data, array $calls): void {
		$response = $this->controller(signedIn: $signedIn, enabled: $enabled, cached: $cached, upstream: $upstream)->range($prefix);

		self::assertSame([$status, $data], [$response->getStatus(), $response->getData()]);
		self::assertSame($calls, $this->calls);
	}//end testRange()

	/**
	 * The controller over recording collaborators.
	 *
	 * @param bool        $signedIn Whether a user is signed in
	 * @param bool        $enabled  Whether the admin gate is on
	 * @param string|null $cached   The cached suffixes, or null
	 * @param string      $upstream 'ok' or 'throw'
	 *
	 * @return BreachProxyController
	 */
	private function controller(bool $signedIn, bool $enabled, ?string $cached, string $upstream): BreachProxyController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(function (string $app, string $key, bool $default) use ($enabled): bool {
			$this->calls[] = ['gate', $app, $key, $default];
			return $enabled;
		});
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(function (string $key) use ($cached): ?string {
			$this->calls[] = ['cacheGet', $key];
			return $cached;
		});
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value, int $ttl): bool {
			$this->calls[] = ['cacheSet', $key, $value, $ttl];
			return true;
		});
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($cache);
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $uri, array $options) use ($upstream): IResponse {
			$this->calls[] = ['get', $uri, $options];
			if ($upstream === 'throw') {
				throw new RuntimeException('upstream refused ' . $uri);
			}

			$response = $this->createMock(IResponse::class);
			$response->method('getBody')->willReturn('BODY');
			$response->method('getStatusCode')->willReturn(200);
			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->calls[] = ['warning', $message, $context];
		});
		$reporter = $this->createMock(ConnectionReporter::class);
		$reporter->method('httpStatusOf')->willReturnCallback(function (\Throwable $exception): ?int {
			$this->calls[] = ['httpStatusOf', $exception::class];
			return 429;
		});
		$reporter->method('reportBreachLookup')->willReturnCallback(function (?int $httpStatus): bool {
			$this->calls[] = ['report', $httpStatus];
			return true;
		});

		return new BreachProxyController($this->createMock(IRequest::class), $config, $clients, $caches, $session, $logger, $reporter);
	}//end controller()
}//end class
