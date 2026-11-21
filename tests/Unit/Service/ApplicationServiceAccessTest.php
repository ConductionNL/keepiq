<?php

/**
 * Unit tests for the admin and visibility checks of ApplicationService.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\ApplicationMapper;
use OCA\Keepiq\Service\ApplicationAuditTrail;
use OCA\Keepiq\Service\ApplicationDataCleanupService;
use OCA\Keepiq\Service\ApplicationLifecycleService;
use OCA\Keepiq\Service\ApplicationService;
use OCA\Keepiq\Service\ApplicationSuiteProvisioner;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only an administrator deletes or lists pending applications; others see their own and active ones.
 */
#[CoversClass(ApplicationService::class)]
#[UsesClass(Application::class)]
class ApplicationServiceAccessTest extends TestCase {

	/** @var ApplicationMapper&MockObject */
	private ApplicationMapper $mapper;

	/** @var ApplicationSuiteProvisioner&MockObject */
	private ApplicationSuiteProvisioner $provisioner;

	/** @var ApplicationDataCleanupService&MockObject */
	private ApplicationDataCleanupService $cleanup;

	/** @var array<string,Application> */
	private array $stored = [];

	/**
	 * Builds the collaborators; findById serves $stored.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper      = $this->createMock(ApplicationMapper::class);
		$this->provisioner = $this->createMock(ApplicationSuiteProvisioner::class);
		$this->cleanup     = $this->createMock(ApplicationDataCleanupService::class);
		$this->mapper->method('findById')->willReturnCallback(
			fn (string $id): Application => $this->stored[$id] ?? throw new DoesNotExistException('none')
		);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return ApplicationService
	 */
	private function service(): ApplicationService {
		return new ApplicationService(
			mapper: $this->mapper,
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
			lifecycle: $this->createMock(ApplicationLifecycleService::class),
			suiteProvisioner: $this->provisioner,
			auditTrail: $this->createMock(ApplicationAuditTrail::class),
			dataCleanup: $this->cleanup,
		);
	}//end service()

	/**
	 * An application, stored for findById.
	 *
	 * @param string $id           The id
	 * @param string $status       Its status
	 * @param string $registeredBy Who registered it
	 *
	 * @return Application
	 */
	private function app(string $id, string $status, string $registeredBy = 'alice'): Application {
		$app = new Application();
		$app->setId($id);
		$app->setName('App '.$id);
		$app->setStatus($status);
		$app->setRegisteredBy($registeredBy);
		$this->stored[$id] = $app;
		return $app;
	}//end app()

	/**
	 * A non-administrator cannot delete, and nothing is cleaned up.
	 *
	 * @return void
	 */
	public function testANonAdminCannotDelete(): void {
		$this->app('a1', Application::STATUS_ACTIVE);
		$this->cleanup->expects($this->never())->method('removeFor');
		$this->mapper->expects($this->never())->method('delete');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Only an administrator may delete applications');

		$this->service()->delete(applicationId: 'a1', isAdmin: false);
	}//end testANonAdminCannotDelete()

	/**
	 * An unknown application is reported as not found.
	 *
	 * @return void
	 */
	public function testAnUnknownApplicationIsNotFound(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Application not found');

		$this->service()->get(applicationId: 'missing', userId: 'alice', isAdmin: true);
	}//end testAnUnknownApplicationIsNotFound()

	/**
	 * An inactive application has no certificate to hand out.
	 *
	 * @return void
	 */
	public function testAnInactiveApplicationHasNoCertificate(): void {
		$this->app('a1', Application::STATUS_PENDING);
		$this->provisioner->expects($this->never())->method('activeCertificate');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Application is not active');

		$this->service()->getCertificate(applicationId: 'a1');
	}//end testAnInactiveApplicationHasNoCertificate()

	/**
	 * An active application's certificate comes from the suite provisioner.
	 *
	 * @return void
	 */
	public function testAnActiveApplicationsCertificateIsProvisioned(): void {
		$this->app('a1', Application::STATUS_ACTIVE);
		$this->provisioner->expects($this->once())->method('activeCertificate')->with(applicationId: 'a1')->willReturn('PEM');

		$this->assertSame('PEM', $this->service()->getCertificate(applicationId: 'a1'));
	}//end testAnActiveApplicationsCertificateIsProvisioned()

	/**
	 * Someone else's pending application is hidden; the registrant and an admin see it.
	 *
	 * @return void
	 */
	public function testAPendingApplicationIsVisibleOnlyToItsRegistrantAndAdmins(): void {
		$pending = $this->app('a1', Application::STATUS_PENDING, 'alice');

		$this->assertSame($pending, $this->service()->get(applicationId: 'a1', userId: 'alice', isAdmin: false));
		$this->assertSame($pending, $this->service()->get(applicationId: 'a1', userId: 'root', isAdmin: true));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Not authorized to view this application');
		$this->service()->get(applicationId: 'a1', userId: 'bob', isAdmin: false);
	}//end testAPendingApplicationIsVisibleOnlyToItsRegistrantAndAdmins()

	/**
	 * An active application is visible to anyone.
	 *
	 * @return void
	 */
	public function testAnActiveApplicationIsVisibleToAnyone(): void {
		$active = $this->app('a1', Application::STATUS_ACTIVE, 'alice');

		$this->assertSame($active, $this->service()->get(applicationId: 'a1', userId: 'bob', isAdmin: false));
	}//end testAnActiveApplicationIsVisibleToAnyone()

	/**
	 * An admin lists everything.
	 *
	 * @return void
	 */
	public function testAnAdminListsEveryApplication(): void {
		$all = [$this->app('a1', Application::STATUS_PENDING), $this->app('a2', Application::STATUS_ACTIVE)];
		$this->mapper->expects($this->once())->method('findAll')->with()->willReturn($all);
		$this->mapper->expects($this->never())->method('findByRegistrant');

		$this->assertSame($all, $this->service()->listForUser(userId: 'root', isAdmin: true));
	}//end testAnAdminListsEveryApplication()

	/**
	 * A user lists their own plus the active ones, each application once.
	 *
	 * @return void
	 */
	public function testAUserListsOwnAndActiveApplicationsOnce(): void {
		$ownPending = $this->app('a1', Application::STATUS_PENDING, 'bob');
		$ownActive  = $this->app('a2', Application::STATUS_ACTIVE, 'bob');
		$other      = $this->app('a3', Application::STATUS_ACTIVE, 'alice');
		$this->mapper->method('findByRegistrant')->with('bob')->willReturn([$ownPending, $ownActive]);
		$this->mapper->method('findAll')->with(status: Application::STATUS_ACTIVE)->willReturn([$ownActive, $other]);

		$this->assertSame([$ownPending, $ownActive, $other], $this->service()->listForUser(userId: 'bob', isAdmin: false));
	}//end testAUserListsOwnAndActiveApplicationsOnce()

	/**
	 * Only an admin lists the pending queue; the count needs no check.
	 *
	 * @return void
	 */
	public function testThePendingQueueIsAdminOnly(): void {
		$pending = [$this->app('a1', Application::STATUS_PENDING)];
		$this->mapper->method('findPending')->willReturn($pending);
		$this->mapper->method('countPending')->willReturn(4);

		$this->assertSame($pending, $this->service()->listPending(isAdmin: true));
		$this->assertSame(4, $this->service()->countPending());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Only an administrator may list pending applications');
		$this->service()->listPending(isAdmin: false);
	}//end testThePendingQueueIsAdminOnly()
}//end class
