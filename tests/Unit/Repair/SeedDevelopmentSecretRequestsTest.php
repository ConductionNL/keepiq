<?php

/**
 * Unit tests for SeedDevelopmentSecretRequests.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Repair
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

namespace OCA\Keepiq\Tests\Unit\Repair;

use DateTime;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Db\SecretRequestMapper;
use OCA\Keepiq\Repair\SeedDevelopmentSecretRequests;
use OCA\Keepiq\Repair\SeedVersionGate;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SeedDevelopmentSecretRequests: in debug mode, once per version, it
 * seeds up to three secret requests (pending, re-request, expired) against the
 * dev user's secrets, and never twice.
 *
 * @covers \OCA\Keepiq\Repair\SeedDevelopmentSecretRequests
 * @uses   \OCA\Keepiq\Repair\SeedVersionGate
 * @uses   \OCA\Keepiq\Repair\DevVaultLoader
 * @uses   \OCA\Keepiq\Repair\DevVault
 * @uses   \OCA\Keepiq\Db\EncryptionSuite
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\SecretRequest
 */
class SeedDevelopmentSecretRequestsTest extends TestCase {

	private SecretRequestMapper&MockObject $requests;
	private SecretMapper&MockObject $secrets;
	private EncryptionSuiteMapper&MockObject $suites;
	private LoggerInterface&MockObject $logger;
	private IOutput&MockObject $output;

	/**
	 * App config values written by the version gate.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Requests inserted, in order.
	 *
	 * @var array<int, SecretRequest>
	 */
	private array $inserted = [];

	protected function setUp(): void {
		$this->requests = $this->createMock(SecretRequestMapper::class);
		$this->secrets  = $this->createMock(SecretMapper::class);
		$this->suites   = $this->createMock(EncryptionSuiteMapper::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
		$this->output   = $this->createMock(IOutput::class);
		$this->requests->method('insert')->willReturnCallback(
			function (SecretRequest $request): SecretRequest {
				$this->inserted[] = $request;
				return $request;
			}
		);
	}//end setUp()

	/**
	 * The repair step, with debug on or off and version 1.2.3 installed.
	 *
	 * @param bool $debug Whether debug mode is on.
	 *
	 * @return SeedDevelopmentSecretRequests
	 */
	private function step(bool $debug = true): SeedDevelopmentSecretRequests {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn($debug);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'installed_version' ? '1.2.3' : $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->written[$key] = $value;
				return true;
			}
		);

		return new SeedDevelopmentSecretRequests(
			requestMapper: $this->requests,
			secretMapper: $this->secrets,
			suiteMapper: $this->suites,
			seedGate: new SeedVersionGate(config: $config, appConfig: $appConfig),
			logger: $this->logger,
		);
	}//end step()

	/**
	 * The dev user 'admin' has suite-1 and these secret ids.
	 *
	 * @param array<int, string> $ids The secret ids.
	 *
	 * @return void
	 */
	private function devVault(array $ids): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$this->suites->method('findActiveByOwner')->with('user', 'admin')->willReturn($suite);
		$secrets = array_map(
			static function (string $id): Secret {
				$secret = new Secret();
				$secret->setId($id);
				return $secret;
			},
			$ids
		);
		$this->secrets->method('findByOwner')->with('user', 'admin')->willReturn($secrets);
	}//end devVault()

	/**
	 * Nothing exists yet for any request id.
	 *
	 * @return void
	 */
	private function nothingSeededYet(): void {
		$this->requests->method('findById')->willThrowException(new DoesNotExistException('none'));
	}//end nothingSeededYet()

	/**
	 * The repair step has a stable name.
	 *
	 * @return void
	 */
	public function testName(): void {
		$this->assertSame('Seed Keepiq development secret requests (debug only)', $this->step()->getName());
	}//end testName()

	/**
	 * Outside debug mode nothing is read or written.
	 *
	 * @return void
	 */
	public function testNothingHappensOutsideDebugMode(): void {
		$this->suites->expects($this->never())->method('findActiveByOwner');

		$this->step(debug: false)->run($this->output);

		$this->assertSame([], $this->inserted);
		$this->assertSame([], $this->written);
	}//end testNothingHappensOutsideDebugMode()

	/**
	 * Without a dev suite it skips, and does not mark the version seeded.
	 *
	 * @return void
	 */
	public function testWithoutADevSuiteItSkipsAndTriesAgainLater(): void {
		$this->suites->method('findActiveByOwner')->willThrowException(new DoesNotExistException('none'));
		$this->output->expects($this->once())->method('info')->with('Keepiq: no dev EncryptionSuite, skipping secret-request seed');

		$this->step()->run($this->output);

		$this->assertSame([], $this->inserted);
		$this->assertSame([], $this->written);
	}//end testWithoutADevSuiteItSkipsAndTriesAgainLater()

	/**
	 * With one secret only the pending request is seeded, fully filled in.
	 *
	 * @return void
	 */
	public function testOneSecretGetsOnePendingRequest(): void {
		$this->devVault(['s-1']);
		$this->nothingSeededYet();
		$this->output->expects($this->once())->method('info')->with('Keepiq: seeded 1 development secret requests');
		$this->logger->expects($this->once())->method('info')->with('Keepiq dev seed: created 1 secret requests');
		$before = new DateTime('+7 days -1 minute');

		$this->step()->run($this->output);

		$this->assertCount(1, $this->inserted);
		$request = $this->inserted[0];
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $request->getId());
		$this->assertSame('s-1', $request->getSecretId());
		$this->assertSame('suite-1', $request->getEncryptionSuiteId());
		$this->assertSame(SecretRequest::STATUS_PENDING, $request->getStatus());
		$this->assertFalse($request->getIsReRequest());
		$this->assertSame('admin', $request->getCreatedBy());
		$this->assertSame('["key","login"]', $request->getRequestedFields());
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $request->getToken());
		$this->assertGreaterThan($before, $request->getExpiresAt());
		$this->assertLessThan(new DateTime('+7 days +1 minute'), $request->getExpiresAt());
		$this->assertSame(['dev_seed_secret_requests_version' => '1.2.3'], $this->written);
	}//end testOneSecretGetsOnePendingRequest()

	/**
	 * With three secrets it also seeds a re-request and an already-expired request.
	 *
	 * @return void
	 */
	public function testThreeSecretsGetPendingReRequestAndExpired(): void {
		$this->devVault(['s-1', 's-2', 's-3', 's-4']);
		$this->nothingSeededYet();
		$this->output->expects($this->once())->method('info')->with('Keepiq: seeded 3 development secret requests');

		$this->step()->run($this->output);

		$this->assertSame(['s-1', 's-2', 's-3'], array_map(static fn (SecretRequest $r): string => $r->getSecretId(), $this->inserted));
		$this->assertSame([false, true, false], array_map(static fn (SecretRequest $r): bool => $r->getIsReRequest(), $this->inserted));
		$this->assertCount(3, array_unique(array_map(static fn (SecretRequest $r): string => $r->getId(), $this->inserted)));
		$this->assertCount(3, array_unique(array_map(static fn (SecretRequest $r): string => $r->getToken(), $this->inserted)));
		$now = new DateTime();
		$this->assertGreaterThan(new DateTime('+3 days -1 minute'), $this->inserted[1]->getExpiresAt());
		$this->assertLessThan(new DateTime('+3 days +1 minute'), $this->inserted[1]->getExpiresAt());
		$this->assertLessThan($now, $this->inserted[2]->getExpiresAt());
	}//end testThreeSecretsGetPendingReRequestAndExpired()

	/**
	 * The request ids are deterministic, so a second run finds them and inserts nothing.
	 *
	 * @return void
	 */
	public function testIdsAreDeterministicAndExistingRequestsAreSkipped(): void {
		$this->devVault(['s-1', 's-2']);
		$this->nothingSeededYet();
		$this->step()->run($this->output);
		$firstIds = array_map(static fn (SecretRequest $r): string => $r->getId(), $this->inserted);

		$requests = $this->createMock(SecretRequestMapper::class);
		$looked = [];
		$requests->method('findById')->willReturnCallback(
			static function (string $id) use (&$looked): SecretRequest {
				$looked[] = $id;
				return new SecretRequest();
			}
		);
		$requests->expects($this->never())->method('insert');
		$this->requests = $requests;
		$this->logger->expects($this->exactly(2))->method('debug');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Keepiq: seeded 0 development secret requests');

		$this->step()->run($output);

		$this->assertSame($firstIds, $looked);
	}//end testIdsAreDeterministicAndExistingRequestsAreSkipped()
}//end class
