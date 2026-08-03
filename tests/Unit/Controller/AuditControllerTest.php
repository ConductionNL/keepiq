<?php

/**
 * Unit tests for AuditController.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Controller
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

namespace OCA\Doriath\Tests\Unit\Controller;

use OCA\Doriath\Controller\AuditController;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Service\AuditService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the read-only AuditController — scope + no-existence-oracle.
 */
class AuditControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var AuditController
     */
    private AuditController $controller;

    /**
     * The mocked audit service.
     *
     * @var AuditService&MockObject
     */
    private AuditService $auditService;

    /**
     * The mocked secret mapper.
     *
     * @var SecretMapper&MockObject
     */
    private SecretMapper $secretMapper;

    /**
     * Set up test fixtures with a logged-in user "alice".
     *
     * @return void
     */
    protected function setUp(): void
    {
        $request            = $this->createMock(IRequest::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->secretMapper = $this->createMock(SecretMapper::class);

        $userSession = $this->createMock(IUserSession::class);
        $user        = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession->method('getUser')->willReturn($user);

        $this->controller = new AuditController(
            $request,
            $this->auditService,
            $this->secretMapper,
            $userSession,
        );
    }//end setUp()

    /**
     * Owner sees their secret's activity.
     *
     * @return void
     */
    public function testSecretActivityForOwner(): void
    {
        $secret = new Secret();
        $secret->setOwnerType('user');
        $secret->setOwnerId('alice');
        $this->secretMapper->method('findById')->with('sec-1')->willReturn($secret);

        $this->auditService->expects($this->once())
            ->method('listForObject')
            ->with('secret', 'sec-1')
            ->willReturn([]);

        $response = $this->controller->secret('sec-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testSecretActivityForOwner()

    /**
     * A non-owned secret returns 404 — and never queries the audit service.
     *
     * @return void
     */
    public function testSecretActivityNonOwnerReturns404(): void
    {
        $secret = new Secret();
        $secret->setOwnerType('user');
        $secret->setOwnerId('mallory');
        $this->secretMapper->method('findById')->with('sec-1')->willReturn($secret);

        $this->auditService->expects($this->never())->method('listForObject');

        $response = $this->controller->secret('sec-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testSecretActivityNonOwnerReturns404()

    /**
     * A nonexistent secret returns the SAME 404 — no existence oracle.
     *
     * @return void
     */
    public function testSecretActivityNonexistentReturnsSame404(): void
    {
        $this->secretMapper->method('findById')->willThrowException(new DoesNotExistException('nope'));

        $response = $this->controller->secret('sec-missing');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testSecretActivityNonexistentReturnsSame404()

    /**
     * /audit/me is strictly scoped to the session user as actor.
     *
     * @return void
     */
    public function testPersonalActivityScopedToSessionUser(): void
    {
        $this->auditService->expects($this->once())
            ->method('listForActor')
            ->with('alice')
            ->willReturn([]);

        $response = $this->controller->mine();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testPersonalActivityScopedToSessionUser()

    /**
     * The admin index passes filters + pagination through to the service.
     *
     * @return void
     */
    public function testAdminIndexPassesFilters(): void
    {
        $captured = null;
        $this->auditService->method('adminQuery')->willReturnCallback(
            function (array $filters, int $page, int $limit) use (&$captured): array {
                $captured = [$filters, $page, $limit];
                return ['entries' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
            }
        );

        $response = $this->controller->index(eventType: 'secret.read', actor: 'bob', page: 3, limit: 25);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('secret.read', $captured[0]['eventType']);
        $this->assertSame('bob', $captured[0]['actor']);
        $this->assertSame(3, $captured[1]);
        $this->assertSame(25, $captured[2]);
    }//end testAdminIndexPassesFilters()
}//end class
