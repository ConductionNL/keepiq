<?php

/**
 * Unit tests for VaultKeyProofMiddleware (refusal edges).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Middleware
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

namespace OCA\Keepiq\Tests\Unit\Middleware;

use OCA\Keepiq\Attribute\VaultKeyProofRequired;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Exception\KeyProofRequiredException;
use OCA\Keepiq\Middleware\OcsRefusalMiddleware;
use OCA\Keepiq\Middleware\VaultKeyProofMiddleware;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\VaultKeyProofExemption;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Controller;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A controller fixture for the refusal edges.
 */
class ProofEdgeFixtureController extends Controller {
	#[VaultKeyProofRequired(binds: ['a', 'b'], subject: 'active', purpose: 'edge-active')]
	public function guardedActive(): void {
	}

	#[VaultKeyProofRequired(subject: 'routeParam:suiteId', purpose: 'edge-param')]
	public function guardedRouteParam(): void {
	}

	#[VaultKeyProofRequired(purpose: 'edge-exempt', exemption: 'edge.exemption')]
	public function guardedExemptable(): void {
	}
}//end class

/**
 * The paths of VaultKeyProofMiddleware that end in a refusal or a waiver
 * rather than a verified proof: no session, an exemption that grants or
 * fails, a subject that cannot be resolved or has no certificate.
 */
#[CoversClass(VaultKeyProofMiddleware::class)]
#[UsesClass(VaultKeyProofRequired::class)]
#[UsesClass(EncryptionSuite::class)]
#[UsesClass(KeyProofRequiredException::class)]
#[UsesClass(AuditEventFactory::class)]
class VaultKeyProofMiddlewareEdgeTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private EncryptionSuiteService&MockObject $suiteService;
	private VaultKeyProofService&MockObject $proofService;
	private LoggerInterface&MockObject $logger;
	private IEventDispatcher&MockObject $dispatcher;
	private ContainerInterface&MockObject $container;
	private ProofEdgeFixtureController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->suiteService = $this->createMock(EncryptionSuiteService::class);
		$this->proofService = $this->createMock(VaultKeyProofService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->controller = new ProofEdgeFixtureController('keepiq', $this->request);
	}

	private function middleware(): VaultKeyProofMiddleware {
		return new VaultKeyProofMiddleware(
			request: $this->request,
			userSession: $this->userSession,
			suiteService: $this->suiteService,
			proofService: $this->proofService,
			migrationMapper: $this->createMock(SuiteMigrationMapper::class),
			logger: $this->logger,
			eventDispatcher: $this->dispatcher,
			container: $this->container,
		);
	}

	private function signedIn(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private static function suite(?string $certificate, string $owner = 'alice'): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId('s-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId($owner);
		$suite->setCertificate($certificate);

		return $suite;
	}

	/**
	 * Run beforeController and return the refusal message.
	 */
	private function refusal(string $method): string {
		try {
			$this->middleware()->beforeController($this->controller, $method);
		} catch (KeyProofRequiredException $e) {
			return $e->getMessage();
		}

		$this->fail('The request was not refused');
	}

	public function testAGuardedRouteWithoutASessionIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->proofService->expects($this->never())->method('verify');

		$this->assertSame('Not authenticated', $this->refusal('guardedActive'));
	}

	public function testAGrantingExemptionWaivesTheProof(): void {
		$this->signedIn();
		$exemption = $this->createMock(VaultKeyProofExemption::class);
		$exemption->expects($this->once())->method('exempts')->with($this->request, 'alice')->willReturn(true);
		$this->container->expects($this->once())->method('get')->with('edge.exemption')->willReturn($exemption);
		$this->suiteService->expects($this->never())->method('getActiveSuite');
		$this->proofService->expects($this->never())->method('verify');

		$this->middleware()->beforeController($this->controller, 'guardedExemptable');
	}

	public function testADecliningExemptionStillRequiresTheProof(): void {
		$this->signedIn();
		$exemption = $this->createMock(VaultKeyProofExemption::class);
		$exemption->method('exempts')->willReturn(false);
		$this->container->method('get')->willReturn($exemption);
		$this->suiteService->method('getActiveSuite')->willReturn(self::suite('CERT'));
		$this->proofService->expects($this->once())->method('verify');

		$this->middleware()->beforeController($this->controller, 'guardedExemptable');
	}

	public function testAFailingExemptionIsLoggedAndRequiresTheProof(): void {
		$this->signedIn();
		$this->container->method('get')->willThrowException(new RuntimeException('not wired'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Keepiq: vault key proof exemption {class} failed; requiring a proof',
			$this->callback(static fn (array $context): bool => $context['class'] === 'edge.exemption' && $context['app'] === 'keepiq')
		);
		$this->suiteService->method('getActiveSuite')->willReturn(self::suite('CERT'));
		$this->proofService->expects($this->once())->method('verify');

		$this->middleware()->beforeController($this->controller, 'guardedExemptable');
	}

	public function testAnUnresolvableSubjectIsRefusedWithoutLeakingTheCause(): void {
		$this->signedIn();
		$this->suiteService->method('getActiveSuite')->willThrowException(new RuntimeException('db: no row'));
		$this->proofService->expects($this->never())->method('verify');

		$this->assertSame('No subject suite to verify against', $this->refusal('guardedActive'));
	}

	public function testASuiteWithoutACertificateIsRefused(): void {
		$this->signedIn();
		$this->suiteService->method('getActiveSuite')->willReturn(self::suite(null));
		$this->proofService->expects($this->never())->method('verify');

		$this->assertSame('Subject suite has no certificate', $this->refusal('guardedActive'));
	}

	public function testASuiteWithAnEmptyCertificateIsRefused(): void {
		$this->signedIn();
		$this->suiteService->method('getActiveSuite')->willReturn(self::suite(''));

		$this->assertSame('Subject suite has no certificate', $this->refusal('guardedActive'));
	}

	public function testAForeignActiveSuiteIsRefusedAsNotYours(): void {
		$this->signedIn();
		$this->suiteService->method('getActiveSuite')->willReturn(self::suite('CERT', 'bob'));

		$this->assertSame('Subject suite is not yours', $this->refusal('guardedActive'));
	}

	public function testAnOwnedRouteParamSuiteIsVerifiedWithItsCertificate(): void {
		$this->signedIn();
		$this->request->method('getParam')->willReturnMap([['suiteId', '', 's-9']]);
		$this->request->method('getHeader')->willReturnMap([
			['X-Keepiq-Key-Proof-Nonce', 'N'],
			['X-Keepiq-Key-Proof', 'SIG'],
		]);
		$this->suiteService->expects($this->once())->method('getSuite')->with('s-9')->willReturn(self::suite('CERT-9'));
		$this->proofService->expects($this->once())->method('verify')->with(
			'N',
			'SIG',
			'CERT-9',
			'alice',
			'edge-param',
			[],
		);

		$this->middleware()->beforeController($this->controller, 'guardedRouteParam');
	}

	public function testBoundValuesAreReadInAttributeOrderAsStrings(): void {
		$this->signedIn();
		$this->request->method('getParam')->willReturnMap([['a', '', 12], ['b', '', null]]);
		$this->request->method('getHeader')->willReturn('');
		$this->suiteService->method('getActiveSuite')->willReturn(self::suite('CERT'));
		$this->proofService->expects($this->once())->method('verify')
			->with($this->anything(), $this->anything(), 'CERT', 'alice', 'edge-active', ['12', '']);

		$this->middleware()->beforeController($this->controller, 'guardedActive');
	}

	public function testARefusalWithoutASessionIsAnsweredButNotAudited(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->once())->method('warning')->with(
			'Keepiq: vault key proof refused on {route}: {reason}',
			$this->callback(static fn (array $context): bool => $context['userId'] === null
				&& $context['purpose'] === 'edge-active'
				&& $context['route'] === ProofEdgeFixtureController::class.'::guardedActive'
				&& $context['reason'] === 'Not authenticated')
		);

		$response = $this->middleware()->afterException($this->controller, 'guardedActive', new KeyProofRequiredException('Not authenticated'));

		$this->assertSame(OcsRefusalMiddleware::REFUSAL_STATUS, $response->getStatus());
		$this->assertSame(['error' => 'key_proof_required', 'message' => 'Not authenticated'], $response->getData());
	}
}
