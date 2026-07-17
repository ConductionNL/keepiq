<?php

/**
 * Unit tests for DiscoveryController.
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

use OCA\Doriath\Controller\DiscoveryController;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the public machine API discovery document.
 */
class DiscoveryControllerTest extends TestCase
{

    private DiscoveryController $controller;

    /**
     * Wire the controller with a URL generator that echoes route names.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $request = $this->createMock(IRequest::class);
        $url     = $this->createMock(IURLGenerator::class);
        $url->method('linkToRoute')->willReturnCallback(
            static function (string $route): string {
                return match ($route) {
                    'doriath.applicationToken.exchange' => '/apps/doriath/api/v1/token',
                    'doriath.applicationSecrets.index'  => '/apps/doriath/api/v1/app/secrets',
                    default                             => '/apps/doriath/'.$route,
                };
            }
        );
        $url->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $p) => 'https://nc.test'.$p
        );

        $this->controller = new DiscoveryController(request: $request, urlGenerator: $url);
    }//end setUp()

    /**
     * The document declares the API version, token endpoint, grant type,
     * assertion requirements, secret endpoints, and envelope formats.
     *
     * @return void
     */
    public function testDocumentShape(): void
    {
        $response = $this->controller->document();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());

        $data = $response->getData();
        $this->assertSame(1, $data['apiVersion']);
        $this->assertSame('/apps/doriath/api/v1/token', $data['tokenEndpoint']);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $data['grantType']);
        $this->assertSame('RS256', $data['assertion']['alg']);
        $this->assertSame(300, $data['assertion']['maxLifetime']);
        $this->assertArrayHasKey('byName', $data['secrets']);
        $this->assertContains('doriath-machine-secret-v1', $data['envelopeFormats']);
    }//end testDocumentShape()

    /**
     * The document contains no instance-private data (no keys, certs,
     * user ids, or secret values).
     *
     * @return void
     */
    public function testNoInstancePrivateData(): void
    {
        $flat = json_encode($this->controller->document()->getData());
        foreach (['privateKey', 'certificate', 'BEGIN', 'password', 'userId'] as $needle) {
            $this->assertStringNotContainsString($needle, $flat);
        }
    }//end testNoInstancePrivateData()
}//end class
