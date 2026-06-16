<?php

/**
 * Unit tests for KeyGeneratorController.
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

use OCA\Doriath\Controller\KeyGeneratorController;
use OCA\Doriath\Service\KeyGeneratorService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for KeyGeneratorController.
 */
class KeyGeneratorControllerTest extends TestCase
{
    /**
     * The mocked request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * The mocked user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request     = $this->createMock(originalClassName: IRequest::class);
        $this->userSession = $this->createMock(originalClassName: IUserSession::class);
    }//end setUp()

    /**
     * Build a controller with a real service and an authenticated session.
     *
     * @return KeyGeneratorController
     */
    private function authenticatedController(): KeyGeneratorController
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        return new KeyGeneratorController(
            request: $this->request,
            keyGenerator: new KeyGeneratorService(),
            userSession: $this->userSession
        );
    }//end authenticatedController()

    /**
     * Authenticated default request returns a 16-character key.
     *
     * @return void
     */
    public function testAuthenticatedDefaultReturnsKey(): void
    {
        $response = $this->authenticatedController()->generate();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('generatedKey', $data);
        $this->assertSame(16, strlen($data['generatedKey']));
    }//end testAuthenticatedDefaultReturnsKey()

    /**
     * Custom length is honoured by the endpoint.
     *
     * @return void
     */
    public function testCustomLength(): void
    {
        $response = $this->authenticatedController()->generate(length: 24);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(24, strlen($response->getData()['generatedKey']));
    }//end testCustomLength()

    /**
     * Length below the minimum returns a 400 with a message.
     *
     * @return void
     */
    public function testShortLengthReturns400(): void
    {
        $response = $this->authenticatedController()->generate(length: 6);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertArrayHasKey('message', $response->getData());
    }//end testShortLengthReturns400()

    /**
     * A valid regex returns a matching key.
     *
     * @return void
     */
    public function testValidRegexReturnsMatch(): void
    {
        $response = $this->authenticatedController()->generate(regex: '^[a-z]{20}$');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $key = $response->getData()['generatedKey'];
        $this->assertSame(20, strlen($key));
        $this->assertMatchesRegularExpression('/^[a-z]{20}$/', $key);
    }//end testValidRegexReturnsMatch()

    /**
     * A regex with no quantifier returns a 400.
     *
     * @return void
     */
    public function testRegexWithoutQuantifierReturns400(): void
    {
        $response = $this->authenticatedController()->generate(regex: '^[a-zA-Z0-9]$');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testRegexWithoutQuantifierReturns400()

    /**
     * An exhausted character set returns a 400.
     *
     * @return void
     */
    public function testExhaustedCharsetReturns400(): void
    {
        $response = $this->authenticatedController()->generate(
            length: 16,
            includeSpecialCharacters: false,
            excludedCharacters: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
        );
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testExhaustedCharsetReturns400()

    /**
     * An unauthenticated request returns a 401.
     *
     * @return void
     */
    public function testUnauthenticatedReturns401(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $controller = new KeyGeneratorController(
            request: $this->request,
            keyGenerator: new KeyGeneratorService(),
            userSession: $this->userSession
        );

        $response = $controller->generate();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testUnauthenticatedReturns401()
}//end class
