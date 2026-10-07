<?php

/**
 * Unit tests for VaultKeyProofMiddleware.
 *
 * Covers attribute dispatch (absent -> pass-through, present -> verify),
 * subject resolution (active vs routeParam, foreign suite refused), and the
 * afterException mapping (guard exception -> 428 key_proof_required; foreign
 * exception re-thrown). The signature crypto itself lives in
 * VaultKeyProofServiceTest; here the service is mocked.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Middleware;

use OCA\Keepiq\Attribute\VaultKeyProofRequired;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\KeyProofRequiredException;
use OCA\Keepiq\Middleware\VaultKeyProofMiddleware;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A controller fixture exposing guarded and unguarded methods.
 */
class GuardFixtureController extends Controller {
	#[VaultKeyProofRequired(binds: ['encryptedPrivateKey'], subject: 'active', purpose: 'compromise-recovery')]
	public function guardedActive(): void {
	}

	#[VaultKeyProofRequired(subject: 'routeParam:id', purpose: 'update-private-key')]
	public function guardedRouteParam(): void {
	}

	#[VaultKeyProofRequired(binds: ['id'], subject: 'migrationOldSuite', purpose: 'complete-migration')]
	public function guardedMigrationOldSuite(): void {
	}

	#[VaultKeyProofRequired(binds: ['id'], subject: 'migrationNewSuite', purpose: 'emergency-access-re-envelope')]
	public function guardedMigrationNewSuite(): void {
	}

	#[VaultKeyProofRequired(purpose: 'share-new-recipient', exemption: \OCA\Keepiq\Service\KnownShareRecipientExemption::class)]
	public function guardedExemptable(): void {
	}

	public function unguarded(): void {
	}
}//end class

/**
 * Tests for VaultKeyProofMiddleware.
 */
class VaultKeyProofMiddlewareTest extends TestCase {
	private IRequest $request;
	private IUserSession $userSession;
	private EncryptionSuiteService $suiteService;
	private VaultKeyProofService $proofService;
	private SuiteMigrationMapper $migrationMapper;
	private VaultKeyProofMiddleware $middleware;
	private GuardFixtureController $controller;
	private LoggerInterface $logger;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->suiteService = $this->createMock(EncryptionSuiteService::class);
		$this->proofService = $this->createMock(VaultKeyProofService::class);
		$this->migrationMapper = $this->createMock(SuiteMigrationMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->middleware = new VaultKeyProofMiddleware(
			request: $this->request,
			userSession: $this->userSession,
			suiteService: $this->suiteService,
			proofService: $this->proofService,
			migrationMapper: $this->migrationMapper,
			logger: $this->logger,
		);

		$this->controller = new GuardFixtureController('keepiq', $this->request);
	}//end setUp()

	public function testAnUnguardedMethodIsPassedThrough(): void {
		$this->proofService->expects($this->never())->method('verify');
		$this->middleware->beforeController($this->controller, 'unguarded');
		$this->addToAssertionCount(1);
	}//end testAnUnguardedMethodIsPassedThrough()

	public function testActiveSubjectResolvesAndTheProofIsVerifiedWithTheBoundValues(): void {
		$this->suiteService->method('getActiveSuite')
			->with('user', 'alice')
			->willReturn($this->suiteWithCertificate('CERT-PEM'));

		$this->request->method('getHeader')->willReturnMap([
			['X-Keepiq-Key-Proof-Nonce', 'the-nonce'],
			['X-Keepiq-Key-Proof', 'the-sig'],
		]);
		$this->request->method('getParam')->willReturnMap([
			['encryptedPrivateKey', '', 'ENVELOPE'],
		]);

		$this->proofService->expects($this->once())->method('verify')
			->with(
				'the-nonce',
				'the-sig',
				'CERT-PEM',
				'alice',
				'compromise-recovery',
				['ENVELOPE'],
			);

		$this->middleware->beforeController($this->controller, 'guardedActive');
	}//end testActiveSubjectResolvesAndTheProofIsVerifiedWithTheBoundValues()

	public function testMigrationOldSuiteSubjectVerifiesAgainstTheOldSuiteKey(): void {
		$migration = new SuiteMigration();
		$migration->setOldSuiteId('old-suite');
		$this->migrationMapper->method('findById')->with('migr-1')->willReturn($migration);
		$this->suiteService->method('getSuite')
			->with('old-suite')
			->willReturn($this->suiteWithCertificate('OLD-CERT'));

		$this->request->method('getHeader')->willReturnMap([
			['X-Keepiq-Key-Proof-Nonce', 'the-nonce'],
			['X-Keepiq-Key-Proof', 'the-sig'],
		]);
		$this->request->method('getParam')->willReturnMap([['id', '', 'migr-1']]);

		$this->proofService->expects($this->once())->method('verify')
			->with('the-nonce', 'the-sig', 'OLD-CERT', 'alice', 'complete-migration', ['migr-1']);

		$this->middleware->beforeController($this->controller, 'guardedMigrationOldSuite');
	}//end testMigrationOldSuiteSubjectVerifiesAgainstTheOldSuiteKey()

	/**
	 * The new end of a migration can be the proof subject (#804 review): during
	 * a compromise recovery the OLD password may be the leaked one, so the
	 * re-envelope route proves the NEW key, which only the owner holds.
	 *
	 * @return void
	 */
	public function testMigrationNewSuiteSubjectVerifiesAgainstTheNewSuiteKey(): void {
		$migration = new SuiteMigration();
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$this->migrationMapper->method('findById')->with('migr-1')->willReturn($migration);
		$this->suiteService->method('getSuite')
			->with('new-suite')
			->willReturn($this->suiteWithCertificate('NEW-CERT'));

		$this->request->method('getHeader')->willReturnMap([
			['X-Keepiq-Key-Proof-Nonce', 'the-nonce'],
			['X-Keepiq-Key-Proof', 'the-sig'],
		]);
		$this->request->method('getParam')->willReturnMap([['id', '', 'migr-1']]);

		$this->proofService->expects($this->once())->method('verify')
			->with('the-nonce', 'the-sig', 'NEW-CERT', 'alice', 'emergency-access-re-envelope', ['migr-1']);

		$this->middleware->beforeController($this->controller, 'guardedMigrationNewSuite');
	}//end testMigrationNewSuiteSubjectVerifiesAgainstTheNewSuiteKey()

	public function testRouteParamSubjectRefusesAForeignSuite(): void {
		$foreign = $this->suiteWithCertificate('CERT-PEM');
		$foreign->setOwnerType('user');
		$foreign->setOwnerId('bob');
		$this->suiteService->method('getSuite')->willReturn($foreign);
		$this->request->method('getParam')->willReturnMap([['id', '', 'suite-x']]);

		$this->proofService->expects($this->never())->method('verify');
		$this->expectException(KeyProofRequiredException::class);
		$this->middleware->beforeController($this->controller, 'guardedRouteParam');
	}//end testRouteParamSubjectRefusesAForeignSuite()

	public function testAFailedProofPropagatesAsTheGuardException(): void {
		$this->suiteService->method('getActiveSuite')->willReturn($this->suiteWithCertificate('CERT-PEM'));
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('getParam')->willReturn('');
		$this->proofService->method('verify')
			->willThrowException(new KeyProofRequiredException('nope'));

		$this->expectException(KeyProofRequiredException::class);
		$this->middleware->beforeController($this->controller, 'guardedActive');
	}//end testAFailedProofPropagatesAsTheGuardException()

	/**
	 * The refusal is 428, not 403: Nextcloud's OCSMiddleware rewrites a 403 of
	 * an OCSController into an HTTP 200 OCS envelope without the `error`
	 * (measured live, 4 Oct 2026), and most guarded routes are on one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-harden-vault-key-material-guards/tasks.md#task-6.5
	 */
	public function testAfterExceptionMapsTheGuardExceptionTo428(): void {
		$response = $this->middleware->afterException(
			$this->controller,
			'guardedActive',
			new KeyProofRequiredException('need a proof')
		);

		$this->assertSame(Http::STATUS_PRECONDITION_REQUIRED, $response->getStatus());
		$this->assertSame(428, $response->getStatus());
		$this->assertSame('key_proof_required', $response->getData()['error']);
	}//end testAfterExceptionMapsTheGuardExceptionTo428()

	/**
	 * A refused proof leaves a log line (#804 review): the session-only attacker
	 * the guard exists for is exactly the caller that produces refusals, and a
	 * 403 alone is invisible in the app's own records.
	 *
	 * @return void
	 */
	public function testAfterExceptionLogsTheRefusal(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('key proof refused'),
				$this->callback(static fn (array $ctx): bool => ($ctx['userId'] ?? null) === 'alice'
					&& ($ctx['purpose'] ?? null) === 'compromise-recovery'
					&& str_ends_with((string)($ctx['route'] ?? ''), 'GuardFixtureController::guardedActive')
					&& ($ctx['reason'] ?? null) === 'need a proof')
			);

		$this->middleware->afterException($this->controller, 'guardedActive', new KeyProofRequiredException('need a proof'));
	}//end testAfterExceptionLogsTheRefusal()

	/**
	 * A refused proof also reaches the audit trail as key_proof.refused, so
	 * the SIEM export sees a session thief probing guarded routes
	 * (keepiq#870). The proof itself is never in the record.
	 *
	 * @return void
	 */
	public function testAfterExceptionRecordsAKeyProofRefusedAuditEvent(): void {
		$dispatched = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$dispatched): void {
				$dispatched[] = $event;
			}
		);
		$middleware = new VaultKeyProofMiddleware(
			request: $this->request,
			userSession: $this->userSession,
			suiteService: $this->suiteService,
			proofService: $this->proofService,
			migrationMapper: $this->migrationMapper,
			logger: $this->logger,
			eventDispatcher: $dispatcher,
		);

		$middleware->afterException($this->controller, 'guardedActive', new KeyProofRequiredException('Proof does not verify'));

		$this->assertCount(1, $dispatched);
		$event = $dispatched[0];
		$this->assertInstanceOf(AuditEvent::class, $event);
		$this->assertSame(AuditEventTypes::KEY_PROOF_REFUSED, $event->getEventType());
		$this->assertSame('alice', $event->getActorId());
		$metadata = $event->getMetadata();
		$this->assertSame('compromise-recovery', $metadata['purpose']);
		$this->assertSame('Proof does not verify', $metadata['reason']);
		$this->assertStringEndsWith('GuardFixtureController::guardedActive', $metadata['route']);
		$this->assertSame(
			['route', 'purpose', 'reason'],
			AuditEventTypes::WHITELIST[AuditEventTypes::KEY_PROOF_REFUSED]
		);
	}//end testAfterExceptionRecordsAKeyProofRefusedAuditEvent()

	/**
	 * Without a container the declared exemption cannot be asked, so the proof
	 * is still required (keepiq#818 fails closed).
	 *
	 * @return void
	 */
	public function testAnExemptionWithoutAContainerStillRequiresTheProof(): void {
		$this->suiteService->method('getActiveSuite')->willReturn($this->suiteWithCertificate('CERT-PEM'));
		$this->request->method('getHeader')->willReturn('');
		$this->proofService->expects($this->once())->method('verify')
			->willThrowException(new KeyProofRequiredException('No challenge presented'));

		$this->expectException(KeyProofRequiredException::class);
		$this->middleware->beforeController($this->controller, 'guardedExemptable');
	}//end testAnExemptionWithoutAContainerStillRequiresTheProof()

	/**
	 * A container entry that is not a VaultKeyProofExemption waives nothing.
	 *
	 * @return void
	 */
	public function testAnExemptionOfTheWrongTypeStillRequiresTheProof(): void {
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturn(new \stdClass());
		$middleware = new VaultKeyProofMiddleware(
			request: $this->request,
			userSession: $this->userSession,
			suiteService: $this->suiteService,
			proofService: $this->proofService,
			migrationMapper: $this->migrationMapper,
			logger: $this->logger,
			container: $container,
		);
		$this->suiteService->method('getActiveSuite')->willReturn($this->suiteWithCertificate('CERT-PEM'));
		$this->request->method('getHeader')->willReturn('');
		$this->proofService->expects($this->once())->method('verify');

		$middleware->beforeController($this->controller, 'guardedExemptable');
	}//end testAnExemptionOfTheWrongTypeStillRequiresTheProof()

	public function testAfterExceptionRethrowsAForeignException(): void {
		$this->expectException(RuntimeException::class);
		$this->middleware->afterException(
			$this->controller,
			'guardedActive',
			new RuntimeException('something else')
		);
	}//end testAfterExceptionRethrowsAForeignException()

	/**
	 * A user-owned suite carrying the given certificate.
	 *
	 * @param string $certificate The certificate PEM
	 *
	 * @return EncryptionSuite
	 */
	private function suiteWithCertificate(string $certificate): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId('suite-alice');
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$suite->setCertificate($certificate);
		return $suite;
	}//end suiteWithCertificate()
}//end class
