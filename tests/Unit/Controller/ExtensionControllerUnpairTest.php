<?php

/**
 * Unit tests for the ExtensionController unpair endpoint
 * (POST /api/v1/extension/unpair).
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

use OCA\Doriath\Controller\ExtensionController;
use OCA\Doriath\Db\SecretMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `extension#unpair` is the counterpart of `extension#pair`. Pairing IS the
 * Nextcloud app-password, so unpairing mints and destroys nothing server-side;
 * the endpoint's whole contract is (a) it still requires the pairing
 * credential — an anonymous caller must not receive an acknowledgement, (b) it
 * acknowledges with the instruction the extension shows the user, and (c) it
 * touches NO secret storage. (c) is the load-bearing one: an unpair that
 * quietly reached the secret store would be a destructive operation hiding
 * behind a no-op docblock.
 *
 * The existing ExtensionControllerTest covers pair() and match(); this file
 * adds the unpair() contract without touching it.
 *
 * @covers \OCA\Doriath\Controller\ExtensionController
 */
class ExtensionControllerUnpairTest extends TestCase
{

    /**
     * The mocked secret mapper — the controller's only storage collaborator.
     *
     * @var SecretMapper&MockObject
     */
    private SecretMapper&MockObject $secretMapper;

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

        $this->secretMapper = $this->createMock(SecretMapper::class);
        $this->userSession  = $this->createMock(IUserSession::class);
    }//end setUp()


    /**
     * Log a paired user into the mocked session, or leave it anonymous.
     *
     * @param string|null $uid The paired user id, or null for anonymous.
     *
     * @return void
     */
    private function signIn(?string $uid): void
    {
        if ($uid === null) {
            $this->userSession->method('getUser')->willReturn(null);
            return;
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end signIn()


    /**
     * Build the controller under test with its collaborators mocked.
     *
     * @return ExtensionController The controller under test.
     */
    private function controller(): ExtensionController
    {
        return new ExtensionController(
            $this->createMock(IRequest::class),
            $this->secretMapper,
            $this->userSession
        );
    }//end controller()


    /**
     * A paired caller gets the acknowledgement AND the revocation instruction,
     * and no secret storage is consulted or mutated.
     *
     * @return void
     */
    public function testUnpairAcknowledgesAndTouchesNoSecretStorage(): void
    {
        $this->signIn('alice');

        // The ITEM: unpairing is a local-state acknowledgement — the secret
        // store must not be read from or written to on this path.
        $this->secretMapper->expects($this->never())->method($this->anything());

        $response = $this->controller()->unpair();
        $data     = $response->getData();

        $this->assertSame(
            Http::STATUS_OK,
            $response->getStatus(),
            'a paired caller may unpair and receives 200'
        );
        $this->assertTrue(
            $data['ok'],
            'the extension keys its local teardown off ok:true'
        );
        $this->assertSame(
            'Revoke the app-password in Nextcloud security settings to fully unpair.',
            $data['note'],
            'the caller must be told that revoking the app-password is what actually unpairs'
        );
    }//end testUnpairAcknowledgesAndTouchesNoSecretStorage()


    /**
     * An unauthenticated caller is refused — the acknowledgement is not public.
     *
     * @return void
     */
    public function testUnpairWithoutThePairingCredentialIs401(): void
    {
        $this->signIn(null);

        $this->secretMapper->expects($this->never())->method($this->anything());

        $response = $this->controller()->unpair();

        $this->assertSame(
            Http::STATUS_UNAUTHORIZED,
            $response->getStatus(),
            'unpair() must not answer an anonymous caller'
        );
        $this->assertSame(
            ['error' => 'unauthorized'],
            $response->getData(),
            'the refusal shape must match the rest of the extension surface'
        );
    }//end testUnpairWithoutThePairingCredentialIs401()


    /**
     * The endpoint must stay reachable by ordinary (non-admin) users.
     *
     * SecurityMiddleware evaluates the dispatched method's own attributes;
     * without `#[NoAdminRequired]` every non-admin extension user would be
     * rejected before unpair() ever ran.
     *
     * @return void
     */
    public function testUnpairIsReachableByNonAdminExtensionUsers(): void
    {
        $attributes = (new ReflectionMethod(ExtensionController::class, 'unpair'))
            ->getAttributes(NoAdminRequired::class);

        $this->assertCount(
            1,
            $attributes,
            'unpair() must declare #[NoAdminRequired] itself — attributes are not inherited from pair()'
        );
    }//end testUnpairIsReachableByNonAdminExtensionUsers()


}//end class
