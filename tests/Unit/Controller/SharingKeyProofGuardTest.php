<?php

/**
 * Refusal tests for the keepiq#818 sharing proofs, through the controllers.
 *
 * Runs the REAL VaultKeyProofMiddleware and the REAL VaultKeyProofService in
 * front of the REAL ShareController and DelegationController, the way the
 * framework dispatches a request: beforeController, then the method, and
 * afterException on a refusal. Without a proof, a share to a new recipient, a
 * batch registration and a delegation are refused with 403 and the service is
 * never reached. A share to a recipient the caller already shares with directly
 * goes through without a proof.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\DelegationController;
use OCA\Keepiq\Controller\ShareController;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Db\UsedProofNonceMapper;
use OCA\Keepiq\Exception\KeyProofRequiredException;
use OCA\Keepiq\Middleware\VaultKeyProofMiddleware;
use OCA\Keepiq\Service\DelegationService;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\KnownShareRecipientExemption;
use OCA\Keepiq\Service\ShareService;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the sharing and delegation vault-key proofs (keepiq#818).
 */
class SharingKeyProofGuardTest extends TestCase {
	private IRequest $request;
	private IUserSession $userSession;
	private ShareTargetMapper $shareTargets;
	private ShareService $shareService;
	private DelegationService $delegationService;
	private VaultKeyProofMiddleware $middleware;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		// No proof headers: what a stolen session sends.
		$this->request->method('getHeader')->willReturn('');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->shareTargets = $this->createMock(ShareTargetMapper::class);
		$this->shareService = $this->createMock(ShareService::class);
		$this->delegationService = $this->createMock(DelegationService::class);

		$exemption = new KnownShareRecipientExemption(shareTargets: $this->shareTargets);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[KnownShareRecipientExemption::class, $exemption]]);

		$suiteService = $this->createMock(EncryptionSuiteService::class);
		$suite = new \OCA\Keepiq\Db\EncryptionSuite();
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$suite->setCertificate('CERT-PEM');
		$suiteService->method('getActiveSuite')->willReturn($suite);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');

		$this->middleware = new VaultKeyProofMiddleware(
			request: $this->request,
			userSession: $this->userSession,
			suiteService: $suiteService,
			proofService: new VaultKeyProofService(
				config: $config,
				secureRandom: $this->createMock(ISecureRandom::class),
				timeFactory: $this->createMock(ITimeFactory::class),
				usedNonces: $this->createMock(UsedProofNonceMapper::class),
				logger: $this->createMock(LoggerInterface::class),
			),
			migrationMapper: $this->createMock(SuiteMigrationMapper::class),
			logger: $this->createMock(LoggerInterface::class),
			container: $container,
		);
	}//end setUp()

	/**
	 * Run one request the way the framework does.
	 *
	 * @param Controller $controller The controller
	 * @param string     $method     The method
	 * @param array      $args       The named arguments
	 *
	 * @return JSONResponse
	 */
	private function dispatch(Controller $controller, string $method, array $args): JSONResponse {
		try {
			$this->middleware->beforeController($controller, $method);
		} catch (KeyProofRequiredException $e) {
			return $this->middleware->afterException($controller, $method, $e);
		}

		return $controller->$method(...$args);
	}//end dispatch()

	/**
	 * @return ShareController
	 */
	private function shareController(): ShareController {
		return new ShareController(
			request: $this->request,
			shareService: $this->shareService,
			userSession: $this->userSession,
		);
	}//end shareController()

	/**
	 * @return DelegationController
	 */
	private function delegationController(): DelegationController {
		return new DelegationController(
			request: $this->request,
			delegationService: $this->delegationService,
			userSession: $this->userSession,
		);
	}//end delegationController()

	/**
	 * Stub the request parameters.
	 *
	 * @param array<string,string> $params Name => value
	 *
	 * @return void
	 */
	private function params(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $name, $default = null) => ($params[$name] ?? $default)
		);
	}//end params()

	/**
	 * A direct share to a recipient alice has never shared with is refused
	 * without a proof, and nothing is shared.
	 *
	 * @return void
	 */
	public function testAShareToANewRecipientWithoutAProofIsRefused(): void {
		$this->params(['secretId' => 'sec-1', 'targetUserId' => 'mallory']);
		$this->shareTargets->method('hasDirectShareBetween')->with('alice', 'mallory')->willReturn(false);
		$this->shareService->expects($this->never())->method('createShare');

		$response = $this->dispatch(
			$this->shareController(),
			'create',
			['secretId' => 'sec-1', 'targetUserId' => 'mallory', 'recipientSecretId' => 'copy-1']
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('key_proof_required', $response->getData()['error']);
	}//end testAShareToANewRecipientWithoutAProofIsRefused()

	/**
	 * A direct share to a recipient alice already shares with directly needs
	 * no proof: ordinary sharing keeps working on a session.
	 *
	 * @return void
	 */
	public function testAShareToAKnownRecipientNeedsNoProof(): void {
		$this->params(['secretId' => 'sec-2', 'targetUserId' => 'bob']);
		$this->shareTargets->method('hasDirectShareBetween')->with('alice', 'bob')->willReturn(true);
		$row = new ShareTarget();
		$this->shareService->expects($this->once())->method('createShare')->willReturn($row);

		$response = $this->dispatch(
			$this->shareController(),
			'create',
			['secretId' => 'sec-2', 'targetUserId' => 'bob', 'recipientSecretId' => 'copy-2']
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testAShareToAKnownRecipientNeedsNoProof()

	/**
	 * A failing recipient lookup does not waive the proof.
	 *
	 * @return void
	 */
	public function testAFailingRecipientLookupStillNeedsAProof(): void {
		$this->params(['secretId' => 'sec-1', 'targetUserId' => 'bob']);
		$this->shareTargets->method('hasDirectShareBetween')->willThrowException(new \RuntimeException('db down'));
		$this->shareService->expects($this->never())->method('createShare');

		$response = $this->dispatch(
			$this->shareController(),
			'create',
			['secretId' => 'sec-1', 'targetUserId' => 'bob', 'recipientSecretId' => 'copy-1']
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAFailingRecipientLookupStillNeedsAProof()

	/**
	 * A batch registration is refused without a proof, even to known recipients.
	 *
	 * @return void
	 */
	public function testABatchRegistrationWithoutAProofIsRefused(): void {
		$this->params([]);
		$this->shareTargets->method('hasDirectShareBetween')->willReturn(true);
		$this->shareService->expects($this->never())->method('registerDirectShares');

		$response = $this->dispatch(
			$this->shareController(),
			'registerBatch',
			['shares' => [['sourceSecretId' => 'sec-1', 'targetUserId' => 'bob', 'encryptedKey' => 'x']]]
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testABatchRegistrationWithoutAProofIsRefused()

	/**
	 * Creating a delegation is refused without a proof.
	 *
	 * @return void
	 */
	public function testADelegationWithoutAProofIsRefused(): void {
		$this->params(['secretId' => 'sec-1', 'delegatedTo' => 'bob']);
		$this->delegationService->expects($this->never())->method('createDelegation');

		$response = $this->dispatch(
			$this->delegationController(),
			'create',
			['secretId' => 'sec-1', 'delegatedTo' => 'bob']
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testADelegationWithoutAProofIsRefused()

	/**
	 * The admin handover creates a delegation too, and is refused without a proof.
	 *
	 * @return void
	 */
	public function testAnAdminHandoverWithoutAProofIsRefused(): void {
		$this->params(['secretId' => 'sec-1']);
		$this->delegationService->expects($this->never())->method('createAdminHandover');

		$response = $this->dispatch(
			$this->delegationController(),
			'handover',
			['secretId' => 'sec-1']
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAnAdminHandoverWithoutAProofIsRefused()
}//end class
