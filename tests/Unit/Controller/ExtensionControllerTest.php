<?php

/**
 * Unit tests for ExtensionController.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Controller;

use OCA\Doriath\Controller\ExtensionController;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Doriath\Controller\ExtensionController
 */
class ExtensionControllerTest extends TestCase
{
    /**
     * Build the controller + collaborators.
     *
     * @param string|null $userId The session user
     *
     * @return array{0:ExtensionController,1:SecretMapper}
     */
    private function build(?string $userId='alice'): array
    {
        $request = $this->createMock(IRequest::class);
        $session = $this->createMock(IUserSession::class);
        $mapper  = $this->createMock(SecretMapper::class);

        if ($userId !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($userId);
            $session->method('getUser')->willReturn($user);
        } else {
            $session->method('getUser')->willReturn(null);
        }

        return [new ExtensionController($request, $mapper, $session), $mapper];
    }//end build()

    /**
     * Pairing without a session is unauthorized.
     *
     * @return void
     */
    public function testPairRequiresAuth(): void
    {
        [$controller] = $this->build(null);
        $response = $controller->pair();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testPairRequiresAuth()

    /**
     * A paired session gets ok + capabilities.
     *
     * @return void
     */
    public function testPairSucceeds(): void
    {
        [$controller] = $this->build('alice');
        $response = $controller->pair();
        $data     = $response->getData();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($data['ok']);
        $this->assertSame('alice', $data['user']);
        $this->assertContains('passkey-provider', $data['capabilities']);
    }//end testPairSucceeds()

    /**
     * Match without a session is unauthorized.
     *
     * @return void
     */
    public function testMatchRequiresAuth(): void
    {
        [$controller] = $this->build(null);
        $response = $controller->match('example.com');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testMatchRequiresAuth()

    /**
     * An empty host is a 400.
     *
     * @return void
     */
    public function testMatchRejectsEmptyHost(): void
    {
        [$controller] = $this->build('alice');
        $response = $controller->match('');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testMatchRejectsEmptyHost()

    /**
     * Match returns blob rows for the registrable domain, never plaintext.
     *
     * @return void
     */
    public function testMatchReturnsBlobRowsForRegistrableDomain(): void
    {
        [$controller, $mapper] = $this->build('alice');

        $secret = $this->createMock(Secret::class);
        $secret->method('jsonSerialize')->willReturn(
            [
                'id'    => 's1',
                'name'  => 'Example',
                'url'   => 'https://login.example.com',
                'key'   => 'CIPHERTEXT_BLOB',
                'login' => 'CIPHERTEXT_LOGIN',
            ]
        );

        // A subdomain host must be searched by its registrable domain term.
        $mapper->expects($this->once())
            ->method('searchByNameOrUrl')
            ->with('user', 'alice', 'example.com', 200)
            ->willReturn([$secret]);

        $response = $controller->match('login.example.com');
        $data     = $response->getData();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('example.com', $data['term']);
        $this->assertCount(1, $data['items']);
        // Only ciphertext is present; no decrypted value leaks.
        $this->assertSame('CIPHERTEXT_BLOB', $data['items'][0]['key']);
    }//end testMatchReturnsBlobRowsForRegistrableDomain()

    /**
     * A full origin (scheme + path) is reduced to the host before matching.
     *
     * @return void
     */
    public function testMatchStripsSchemeAndPath(): void
    {
        [$controller, $mapper] = $this->build('alice');
        $mapper->expects($this->once())
            ->method('searchByNameOrUrl')
            ->with('user', 'alice', 'example.com', 200)
            ->willReturn([]);

        $response = $controller->match('https://www.example.com/login?x=1');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testMatchStripsSchemeAndPath()
}//end class
