<?php

/**
 * Unit tests for the AttachmentController blob download endpoint
 * (GET /api/v1/attachments/{id}/blob).
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Doriath\Controller\AttachmentController;
use OCA\Doriath\Service\AttachmentService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for `attachment#download`.
 *
 * The grant check lives in AttachmentService (hydra-gate-no-admin-idor), so the
 * controller's contract is exactly three things: it must pass BOTH the route's
 * attachment id AND the session's own uid into downloadBlob() — passing a
 * caller-supplied uid would be the IDOR — it must base64-encode the raw
 * ciphertext it gets back, and it must turn the service's refusal into a 403.
 *
 * @covers \OCA\Doriath\Controller\AttachmentController::download
 */
class AttachmentControllerDownloadTest extends TestCase
{

    /**
     * The mocked attachment service.
     *
     * @var AttachmentService&MockObject
     */
    private AttachmentService&MockObject $attachmentService;

    /**
     * The mocked user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;


    /**
     * Set up the mocks shared by every test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->attachmentService = $this->createMock(AttachmentService::class);
        $this->userSession       = $this->createMock(IUserSession::class);
    }//end setUp()


    /**
     * Build the controller under test with its collaborators mocked.
     *
     * @param string|null $userId The session uid, or null for an anonymous caller.
     *
     * @return AttachmentController The controller under test.
     */
    private function controller(?string $userId='alice'): AttachmentController
    {
        if ($userId === null) {
            $this->userSession->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($userId);
            $this->userSession->method('getUser')->willReturn($user);
        }

        return new AttachmentController(
            request: $this->createMock(IRequest::class),
            attachmentService: $this->attachmentService,
            userSession: $this->userSession
        );
    }//end controller()


    /**
     * The happy path: the route id and the SESSION uid reach the service, and
     * the raw ciphertext comes back base64-encoded.
     *
     * @return void
     */
    public function testDownloadForwardsTheRouteIdAndTheSessionUidAndBase64EncodesTheCiphertext(): void
    {
        $attachmentId = '9f8e7d6c-5b4a-4392-8171-0a1b2c3d4e5f';
        // Deliberately non-UTF-8 bytes: only a real base64 encode survives this.
        $ciphertext = "\x00\x01\xff\xfeSEALED-BYTES\x7f";

        // The ITEM: the id comes from the URL, the uid comes from the SESSION.
        $this->attachmentService->expects($this->once())
            ->method('downloadBlob')
            ->with($attachmentId, 'alice')
            ->willReturn($ciphertext);

        $response = $this->controller(userId: 'alice')->download($attachmentId);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['blob' => base64_encode($ciphertext)], $response->getData());
        $this->assertSame($ciphertext, base64_decode($response->getData()['blob'], true));
    }//end testDownloadForwardsTheRouteIdAndTheSessionUidAndBase64EncodesTheCiphertext()


    /**
     * The session uid is the one that is authorized — a second session must be
     * checked against its OWN uid, never a shared or hard-coded one.
     *
     * @return void
     */
    public function testDownloadAuthorizesAgainstTheCallersOwnSessionUid(): void
    {
        $attachmentId = '9f8e7d6c-5b4a-4392-8171-0a1b2c3d4e5f';

        $this->attachmentService->expects($this->once())
            ->method('downloadBlob')
            ->with($attachmentId, 'bob')
            ->willReturn('BYTES');

        $response = $this->controller(userId: 'bob')->download($attachmentId);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['blob' => base64_encode('BYTES')], $response->getData());
    }//end testDownloadAuthorizesAgainstTheCallersOwnSessionUid()


    /**
     * An anonymous caller is rejected with 401 before the service is reached.
     *
     * @return void
     */
    public function testDownloadRejectsAnAnonymousCallerWithoutTouchingTheService(): void
    {
        $this->attachmentService->expects($this->never())->method('downloadBlob');

        $response = $this->controller(userId: null)->download('9f8e7d6c-5b4a-4392-8171-0a1b2c3d4e5f');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Unauthorized'], $response->getData());
    }//end testDownloadRejectsAnAnonymousCallerWithoutTouchingTheService()


    /**
     * A caller without a grant (or an unknown attachment) is refused by the
     * service; the endpoint reports 403 with the service's own message and
     * leaks no ciphertext.
     *
     * @return void
     */
    public function testDownloadTurnsAServiceRefusalIntoForbiddenWithoutLeakingBytes(): void
    {
        $this->attachmentService->expects($this->once())
            ->method('downloadBlob')
            ->with('9f8e7d6c-5b4a-4392-8171-0a1b2c3d4e5f', 'mallory')
            ->willThrowException(new InvalidArgumentException(message: 'No grant for this attachment'));

        $response = $this->controller(userId: 'mallory')->download('9f8e7d6c-5b4a-4392-8171-0a1b2c3d4e5f');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame(['message' => 'No grant for this attachment'], $response->getData());
        $this->assertArrayNotHasKey('blob', $response->getData());
    }//end testDownloadTurnsAServiceRefusalIntoForbiddenWithoutLeakingBytes()


}//end class
