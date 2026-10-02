<?php

/**
 * Refusal tests for a TOTP seed kept on a login (vault-login-totp-codes).
 *
 * The seed lives inside the login's encrypted additional fields, so it must be
 * stored and returned under exactly the rules of the password: another user's
 * login, an application's secret and a missing secret answer the same 404 and
 * write nothing; a signed-out caller gets 401; a blocked suite withholds the
 * seed with the password; the seed ciphertext never reaches the log.
 *
 * Real controller, real SecretService, SecretTypeService, LinkShareService and
 * WriteLockService; only the database mappers are doubles, plus the
 * MigrationService write-lock probe (not the subject here).
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
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\SecretController;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\WriteLockService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The seed on a login follows the password's access rules.
 */
class SecretSeedRefusalTest extends TestCase {
	/**
	 * The encrypted additional-fields blob that carries the seed.
	 */
	private const SEED_BLOB = 'RSA-CIPHERTEXT-ADDITIONAL-FIELDS-WITH-TOTP-SEED';

	/** @var SecretMapper&MockObject */
	private SecretMapper $mapper;

	/** @var EncryptionSuiteMapper&MockObject */
	private EncryptionSuiteMapper $suiteMapper;

	/** @var list<string> Every log line, with its context flattened */
	private array $logLines = [];

	/** @var list<object> Every dispatched event */
	private array $events = [];

	private SecretService $service;

	/**
	 * Wire the real service graph over mapper doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->buildService('active');
	}//end setUp()

	/**
	 * Build the real service graph with a suite in the given status.
	 *
	 * @param string $suiteStatus active, revoked or compromised
	 *
	 * @return void
	 */
	private function buildService(string $suiteStatus): void {
		$this->logLines = [];
		$this->events = [];
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suite = new EncryptionSuite();
		$suite->setStatus($suiteStatus);
		$this->suiteMapper->method('findById')->willReturn($suite);

		$lines = &$this->logLines;
		$logger = new class($lines) extends AbstractLogger {
			/**
			 * @param list<string> $lines Sink
			 */
			public function __construct(
				private array &$lines,
			) {
			}

			/**
			 * @param mixed                $level   Level
			 * @param string|\Stringable   $message Message
			 * @param array<string, mixed> $context Context
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = (string)$message . ' ' . (string)json_encode($context);
			}
		};

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->events[] = $event;
			}
		);

		$writeLock = new WriteLockService(
			migrationMapper: $this->createMock(SuiteMigrationMapper::class),
			suiteMapper: $this->suiteMapper,
		);
		$migration = $this->createMock(MigrationService::class);
		$migration->method('isWriteLocked')->willReturn(false);

		$this->service = new SecretService(
			mapper: $this->mapper,
			typeService: new SecretTypeService(
				mapper: $this->createMock(SecretTypeMapper::class),
				secretMapper: $this->mapper,
				logger: $logger,
			),
			suiteMapper: $this->suiteMapper,
			migrationService: $migration,
			linkShareService: new LinkShareService(
				mapper: $this->createMock(LinkShareMapper::class),
				logger: $logger,
				writeLockService: $writeLock,
			),
			logger: $logger,
			eventDispatcher: $dispatcher,
		);
	}//end buildService()

	/**
	 * A controller acting for the given user, or for nobody.
	 *
	 * @param string|null $uid The signed-in user, null when signed out
	 *
	 * @return SecretController
	 */
	private function controllerFor(?string $uid): SecretController {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		// The request carries only additionalFields, as the edit dialog sends it.
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'additionalFields' ? 'present' : $default)
		);

		return new SecretController(
			request: $request,
			secretService: $this->service,
			userSession: $session,
		);
	}//end controllerFor()

	/**
	 * A login carrying a seed in its encrypted additional fields.
	 *
	 * @param string $ownerType user or application
	 * @param string $ownerId   The owner
	 *
	 * @return Secret
	 */
	private function loginWithSeed(string $ownerType, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId('s-seed');
		$secret->setName('example.com');
		$secret->setKey('RSA-CIPHERTEXT-PASSWORD');
		$secret->setAdditionalFields(self::SEED_BLOB);
		$secret->setEncryptionSuiteId('suite-1');
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);
		return $secret;
	}//end loginWithSeed()

	/**
	 * Nothing may be written, stamped or recorded.
	 *
	 * @return void
	 */
	private function expectNoWrite(): void {
		$this->mapper->expects($this->never())->method('update');
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('delete');
		$this->mapper->expects($this->never())->method('markUsed');
	}//end expectNoWrite()

	/**
	 * The three refused cases answer one and the same 404 on read.
	 *
	 * @return void
	 */
	public function testReadingAnotherUsersOrAnApplicationsOrAMissingSeedIsTheSame404(): void {
		$responses = [];
		foreach ([['user', 'bob'], ['application', 'app-1'], null] as $owner) {
			$this->buildService('active');
			if ($owner === null) {
				$this->mapper->method('findById')->willThrowException(new DoesNotExistException('none'));
			} else {
				$this->mapper->method('findById')->willReturn($this->loginWithSeed($owner[0], $owner[1]));
			}

			$this->expectNoWrite();
			$response = $this->controllerFor('alice')->show('s-seed');
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertStringNotContainsString(self::SEED_BLOB, (string)json_encode($response->getData()));
			$this->assertSame([], $this->events, 'a refused read records no read event');
			$responses[] = $response->getData();
		}

		$this->assertSame($responses[0], $responses[1], 'another user and an application answer alike');
		$this->assertSame($responses[0], $responses[2], 'a refused secret answers like a missing one');
	}//end testReadingAnotherUsersOrAnApplicationsOrAMissingSeedIsTheSame404()

	/**
	 * The three refused cases answer one and the same 404 on write, and write nothing.
	 *
	 * @return void
	 */
	public function testWritingASeedToAnotherUsersOrAnApplicationsOrAMissingLoginIsTheSame404(): void {
		$responses = [];
		foreach ([['user', 'bob'], ['application', 'app-1'], null] as $owner) {
			$this->buildService('active');
			$stored = null;
			if ($owner === null) {
				$this->mapper->method('findById')->willThrowException(new DoesNotExistException('none'));
			} else {
				$stored = $this->loginWithSeed($owner[0], $owner[1]);
				$this->mapper->method('findById')->willReturn($stored);
			}

			$this->expectNoWrite();
			$response = $this->controllerFor('alice')->update(id: 's-seed', additionalFields: 'ATTACKER-SEED-BLOB');
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame([], $this->events, 'a refused write records nothing');
			if ($stored !== null) {
				$this->assertSame(self::SEED_BLOB, $stored->getAdditionalFields(), 'the stored seed is untouched');
			}

			$responses[] = $response->getData();
		}

		$this->assertSame($responses[0], $responses[1]);
		$this->assertSame($responses[0], $responses[2]);
	}//end testWritingASeedToAnotherUsersOrAnApplicationsOrAMissingLoginIsTheSame404()

	/**
	 * Signed out: 401 on list, read and write, and the mapper is never asked.
	 *
	 * @return void
	 */
	public function testSignedOutAnswers401(): void {
		$this->mapper->expects($this->never())->method('findById');
		$this->mapper->expects($this->never())->method('findByOwner');
		$this->expectNoWrite();

		$controller = $this->controllerFor(null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show('s-seed')->getStatus());
		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$controller->update(id: 's-seed', additionalFields: 'ATTACKER-SEED-BLOB')->getStatus()
		);
	}//end testSignedOutAnswers401()

	/**
	 * A blocked suite withholds the seed exactly where it withholds the password.
	 *
	 * @return void
	 */
	public function testABlockedSuiteWithholdsTheSeedWithThePassword(): void {
		$this->buildService('revoked');
		$this->mapper->method('findByOwner')->willReturn([$this->loginWithSeed('user', 'alice')]);
		$this->mapper->method('countByOwner')->willReturn(1);

		$row = $this->controllerFor('alice')->index()->getData()['items'][0];
		$this->assertArrayNotHasKey('key', $row);
		$this->assertArrayNotHasKey('additionalFields', $row);
	}//end testABlockedSuiteWithholdsTheSeedWithThePassword()

	/**
	 * The owner's own write stores the blob as given and never logs it.
	 *
	 * @return void
	 */
	public function testTheOwnersSeedWriteIsStoredAsGivenAndNeverLogged(): void {
		$stored = $this->loginWithSeed('user', 'alice');
		$this->mapper->method('findById')->willReturn($stored);
		$this->mapper->expects($this->once())->method('update');

		$response = $this->controllerFor('alice')->update(id: 's-seed', additionalFields: 'NEW-SEED-CIPHERTEXT');

		$this->assertSame(Http::STATUS_OK, $response->getStatus(), (string)json_encode($response->getData()));
		$this->assertSame('NEW-SEED-CIPHERTEXT', $stored->getAdditionalFields());
		foreach ($this->logLines as $line) {
			$this->assertStringNotContainsString('NEW-SEED-CIPHERTEXT', $line);
			$this->assertStringNotContainsString(self::SEED_BLOB, $line);
		}

		foreach ($this->events as $event) {
			$this->assertStringNotContainsString('NEW-SEED-CIPHERTEXT', serialize($event));
		}
	}//end testTheOwnersSeedWriteIsStoredAsGivenAndNeverLogged()
}//end class
