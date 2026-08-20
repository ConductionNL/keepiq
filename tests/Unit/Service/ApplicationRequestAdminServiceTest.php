<?php

/**
 * Doriath - ApplicationRequestAdminService tests
 *
 * The administrator-scoped path over an application's secret requests. Every test
 * here is about authority or scope, because those are the only things that can go
 * wrong in a way nobody notices: a listing that returns another application's
 * rows, a revoke reachable by a non-administrator, or a cleanup that reaches into
 * a vault it was not asked about.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
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

namespace OCA\Doriath\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Db\SecretRequestMapper;
use OCA\Doriath\Service\ApplicationRequestAdminService;
use OCA\Doriath\Service\SecretPlaceholderCleaner;
use OCA\Doriath\Service\SecretRequestOutbox;
use OCA\Doriath\Service\SecretService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the admin-scoped application-request service.
 */
class ApplicationRequestAdminServiceTest extends TestCase {
	/**
	 * The request mapper mock.
	 *
	 * @var SecretRequestMapper&MockObject
	 */
	private SecretRequestMapper&MockObject $mapper;

	/**
	 * The Secret mapper mock.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $secretMapper;

	/**
	 * The Secret service mock, resolved through the container.
	 *
	 * @var SecretService&MockObject
	 */
	private SecretService&MockObject $secretService;

	/**
	 * The service under test.
	 *
	 * @var ApplicationRequestAdminService
	 */
	private ApplicationRequestAdminService $admin;

	/**
	 * Wire the service with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(SecretRequestMapper::class);
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->secretService = $this->createMock(SecretService::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->secretService);

		$this->admin = new ApplicationRequestAdminService(
			mapper: $this->mapper,
			outbox: new SecretRequestOutbox(),
			logger: $this->createMock(LoggerInterface::class),
			// A REAL cleaner over the same mocks, not a stub: the placeholder
			// decisions asserted below (empty vs filled, whose vault) live in it, and
			// stubbing it would let those assertions pass without exercising anything.
			placeholderCleaner: new SecretPlaceholderCleaner(
				secretMapper: $this->secretMapper,
				logger: $this->createMock(LoggerInterface::class),
				container: $container,
			),
		);
	}//end setUp()

	/**
	 * Build a pending request created by a USER, for the scope tests.
	 *
	 * @param string $id The request id
	 * @param string $secretId The target secret
	 *
	 * @return SecretRequest
	 */
	private function makePending(string $id, string $secretId): SecretRequest {
		$entity = new SecretRequest();
		$entity->setId($id);
		$entity->setSecretId($secretId);
		$entity->setToken('tok-' . $id);
		$entity->setStatus(SecretRequest::STATUS_PENDING);

		return $entity;
	}//end makePending()

	/**
	 * Build an application-owned Secret.
	 *
	 * @param string $id The secret id
	 * @param string $applicationId The owning application
	 *
	 * @return Secret
	 */
	private function appSecret(string $id, string $applicationId): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setKey('');
		$secret->setOwnerType('application');
		$secret->setOwnerId($applicationId);

		return $secret;
	}//end appSecret()

	/**
	 * Build a pending request created BY an application.
	 *
	 * @param string $id The request id
	 * @param string $secretId The target secret
	 * @param string $applicationId The creating application
	 *
	 * @return SecretRequest
	 */
	private function appRequest(string $id, string $secretId, string $applicationId): SecretRequest {
		$entity = new SecretRequest();
		$entity->setId($id);
		$entity->setSecretId($secretId);
		$entity->setToken('tok-' . $id);
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setCreatedBy(SecretRequest::actorForApplication($applicationId));

		return $entity;
	}//end appRequest()

	/**
	 * A non-administrator cannot list an application's requests.
	 *
	 * Refused before any query runs, and refused regardless of who registered the
	 * application: registration is a historical act, not continuing responsibility.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testListForApplicationRefusesANonAdmin(): void {
		$this->mapper->expects($this->never())->method('findByApplication');

		try {
			$this->admin->listForApplication(applicationId: 'app-1', isAdmin: false);
			$this->fail('a non-admin must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(403, $e->getCode());
		}
	}//end testListForApplicationRefusesANonAdmin()

	/**
	 * The admin listing queries on the application, newest first.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testListForApplicationReturnsThatApplicationsRequestsNewestFirst(): void {
		$older = $this->appRequest('req-old', 'sec-1', 'app-1');
		$older->setCreatedAt(new DateTime('-2 days'));
		$newer = $this->appRequest('req-new', 'sec-2', 'app-1');
		$newer->setCreatedAt(new DateTime('-1 hour'));

		$this->mapper->expects($this->once())
			->method('findByApplication')
			->with('app-1')
			->willReturn([$older, $newer]);

		$rows = $this->admin->listForApplication(applicationId: 'app-1', isAdmin: true);

		$this->assertSame(['req-new', 'req-old'], array_map(
			static fn (SecretRequest $r): string => $r->getId(),
			$rows
		));
	}//end testListForApplicationReturnsThatApplicationsRequestsNewestFirst()

	/**
	 * A non-administrator cannot revoke an application's request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testRevokeForApplicationRefusesANonAdmin(): void {
		$this->mapper->expects($this->never())->method('update');

		try {
			$this->admin->revokeForApplication(
				requestId: 'req-1',
				applicationId: 'app-1',
				adminUserId: 'bob',
				isAdmin: false,
			);
			$this->fail('a non-admin must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(403, $e->getCode());
		}
	}//end testRevokeForApplicationRefusesANonAdmin()

	/**
	 * An admin cannot revoke another application's request through this path.
	 *
	 * The endpoint is addressed by request id, so without this check an
	 * administrator could revoke ANY request — including a user's own — by naming
	 * it under an application route. Answers 404 rather than 403 so the wrong
	 * application does not learn the id exists elsewhere.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testRevokeForApplicationRefusesARequestOfAnotherActor(): void {
		$foreign = $this->makePending('req-user', 'sec-user');
		$foreign->setCreatedBy('alice');
		$this->mapper->method('findById')->willReturn($foreign);
		$this->mapper->expects($this->never())->method('update');
		$this->secretService->expects($this->never())->method('deleteByApplication');

		try {
			$this->admin->revokeForApplication(
				requestId: 'req-user',
				applicationId: 'app-1',
				adminUserId: 'admin',
				isAdmin: true,
			);
			$this->fail('a request of another actor must not be revocable here');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(404, $e->getCode());
		}
	}//end testRevokeForApplicationRefusesARequestOfAnotherActor()

	/**
	 * An admin revoke ends the request and removes its empty placeholder.
	 *
	 * The point of the whole change: a circulating fill link gets an off switch
	 * that does not need the application's cooperation. The placeholder goes with
	 * it, through the application-owned delete path — `deletePlaceholderIfUnfilled()`
	 * deliberately refuses to cross an ownership boundary.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testAdminRevokeDeletesTheUnfilledApplicationPlaceholder(): void {
		$entity = $this->appRequest('req-1', 'sec-empty', 'app-1');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->method('transitionIfPending')->willReturn(true);
		$this->secretMapper->method('findById')->willReturn($this->appSecret('sec-empty', 'app-1'));

		$this->secretService->expects($this->once())
			->method('deleteByApplication')
			->with('sec-empty', 'app-1');

		$revoked = $this->admin->revokeForApplication(
			requestId: 'req-1',
			applicationId: 'app-1',
			adminUserId: 'admin',
			isAdmin: true,
		);

		$this->assertSame(SecretRequest::STATUS_DECLINED, $revoked->getStatus());
	}//end testAdminRevokeDeletesTheUnfilledApplicationPlaceholder()

	/**
	 * An admin revoke never deletes a Secret that holds a value.
	 *
	 * Shares the emptiness predicate with the user-side revoke, so the same
	 * credential-destroying defect cannot reappear on this path — and a login-only
	 * Secret is used deliberately, since a `key`-only test is what missed it the
	 * first time.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testAdminRevokeNeverDeletesAFilledApplicationSecret(): void {
		$entity = $this->appRequest('req-2', 'sec-filled', 'app-1');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->method('transitionIfPending')->willReturn(true);

		$filled = $this->appSecret('sec-filled', 'app-1');
		$filled->setLogin('CIPHERTEXT-LOGIN');
		$this->secretMapper->method('findById')->willReturn($filled);

		$this->secretService->expects($this->never())->method('deleteByApplication');

		$this->assertSame(
			SecretRequest::STATUS_DECLINED,
			$this->admin->revokeForApplication(
				requestId: 'req-2',
				applicationId: 'app-1',
				adminUserId: 'admin',
				isAdmin: true,
			)->getStatus()
		);
	}//end testAdminRevokeNeverDeletesAFilledApplicationSecret()

	/**
	 * An admin revoke does not reach into another application's vault.
	 *
	 * Ownership is re-checked at the delete, not inferred from the request: two
	 * checks against different data (the request's `created_by`, the Secret's
	 * `owner_id`) so a mismatched pair cannot destroy a third party's Secret.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testAdminRevokeWillNotDeleteAnotherApplicationsSecret(): void {
		$entity = $this->appRequest('req-3', 'sec-elsewhere', 'app-1');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->method('transitionIfPending')->willReturn(true);
		$this->secretMapper->method('findById')
			->willReturn($this->appSecret('sec-elsewhere', 'app-OTHER'));

		$this->secretService->expects($this->never())->method('deleteByApplication');

		$this->admin->revokeForApplication(
			requestId: 'req-3',
			applicationId: 'app-1',
			adminUserId: 'admin',
			isAdmin: true,
		);
	}//end testAdminRevokeWillNotDeleteAnotherApplicationsSecret()
	/**
	 * An empty applicationId is refused before any query runs.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testListForApplicationRefusesAnEmptyApplicationId(): void {
		$this->mapper->expects($this->never())->method('findByApplication');

		try {
			$this->admin->listForApplication(applicationId: '', isAdmin: true);
			$this->fail('an empty applicationId must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(400, $e->getCode());
		}
	}//end testListForApplicationRefusesAnEmptyApplicationId()

	/**
	 * A request that is not pending cannot be revoked.
	 *
	 * 400 rather than a silent success: an administrator clicking revoke on a row
	 * that something else already ended should learn that, not be told it worked.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testRevokeRefusesARequestThatIsNotPending(): void {
		$entity = $this->appRequest('req-done', 'sec-1', 'app-1');
		$entity->setStatus(SecretRequest::STATUS_FULFILLED);
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->expects($this->never())->method('update');

		try {
			$this->admin->revokeForApplication(
				requestId: 'req-done',
				applicationId: 'app-1',
				adminUserId: 'admin',
				isAdmin: true,
			);
			$this->fail('a fulfilled request must not be revocable');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(400, $e->getCode());
		}
	}//end testRevokeRefusesARequestThatIsNotPending()

	/**
	 * A request filled during the revoke is not overwritten, and nothing follows.
	 *
	 * Raised in review on PR #286. The pending check reads the row, then
	 * `QBMapper::update()` writes it back with `WHERE id = ?` and no status guard —
	 * so a recipient filling the request between the two had `fulfilled` replaced by
	 * `declined`, and the administrator got an audit event recording a revoke of a
	 * request that had in fact been answered.
	 *
	 * The entity says `pending` here, exactly as it would after the SELECT. Only the
	 * conditional write can tell the difference, so this asserts on what happens when
	 * it reports zero rows: the refusal the caller would have got a moment earlier,
	 * and NOTHING downstream — no placeholder delete, no revoke recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testARequestFilledDuringTheRevokeIsNotOverwritten(): void {
		$entity = $this->appRequest('req-race', 'sec-race', 'app-1');
		$this->mapper->method('findById')->willReturn($entity);

		// The row stopped being pending after we read it.
		$this->mapper->method('transitionIfPending')->willReturn(false);

		// The unguarded write must not be reached at all.
		$this->mapper->expects($this->never())->method('update');
		$this->secretService->expects($this->never())->method('deleteByApplication');
		$this->secretService->expects($this->never())->method('delete');

		try {
			$this->admin->revokeForApplication(
				requestId: 'req-race',
				applicationId: 'app-1',
				adminUserId: 'admin',
				isAdmin: true,
			);
			$this->fail('a request that stopped being pending must not be revocable');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(400, $e->getCode());
		}
	}//end testARequestFilledDuringTheRevokeIsNotOverwritten()

	/**
	 * A revoke survives the linked Secret having already gone.
	 *
	 * The request is still revoked; there is simply nothing left to clean up.
	 * Throwing here would turn a successful revoke into a failure the administrator
	 * has to retry against a Secret that no longer exists.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testRevokeSurvivesAMissingSecret(): void {
		$entity = $this->appRequest('req-gone', 'sec-vanished', 'app-1');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->method('transitionIfPending')->willReturn(true);
		$this->secretMapper->method('findById')
			->willThrowException(new DoesNotExistException('gone'));

		$this->secretService->expects($this->never())->method('deleteByApplication');

		$this->assertSame(
			SecretRequest::STATUS_DECLINED,
			$this->admin->revokeForApplication(
				requestId: 'req-gone',
				applicationId: 'app-1',
				adminUserId: 'admin',
				isAdmin: true,
			)->getStatus()
		);
	}//end testRevokeSurvivesAMissingSecret()

	/**
	 * A failing placeholder delete does not fail the revoke.
	 *
	 * An orphan empty Secret is untidy; a revoke the administrator believes did not
	 * work is worse, because the fill link is already dead and they will go looking
	 * for another way to kill it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function testRevokeSurvivesAFailingPlaceholderDelete(): void {
		$entity = $this->appRequest('req-stubborn', 'sec-empty', 'app-1');
		$this->mapper->method('findById')->willReturn($entity);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->method('transitionIfPending')->willReturn(true);
		$this->secretMapper->method('findById')->willReturn($this->appSecret('sec-empty', 'app-1'));
		$this->secretService->method('deleteByApplication')
			->willThrowException(new RuntimeException('locked'));

		$this->assertSame(
			SecretRequest::STATUS_DECLINED,
			$this->admin->revokeForApplication(
				requestId: 'req-stubborn',
				applicationId: 'app-1',
				adminUserId: 'admin',
				isAdmin: true,
			)->getStatus()
		);
	}//end testRevokeSurvivesAFailingPlaceholderDelete()

}//end class
