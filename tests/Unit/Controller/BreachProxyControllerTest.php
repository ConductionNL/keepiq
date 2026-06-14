<?php

/**
 * Unit tests for BreachProxyController.
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

use OCA\Doriath\Controller\BreachProxyController;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for BreachProxyController — the HIBP k-anonymity prefix proxy.
 */
class BreachProxyControllerTest extends TestCase
{
    /** @var IRequest&MockObject */
    private IRequest&MockObject $request;

    /** @var IAppConfig&MockObject */
    private IAppConfig&MockObject $appConfig;

    /** @var IClientService&MockObject */
    private IClientService&MockObject $clientService;

    /** @var ICacheFactory&MockObject */
    private ICacheFactory&MockObject $cacheFactory;

    /** @var ICache&MockObject */
    private ICache&MockObject $cache;

    /** @var IUserSession&MockObject */
    private IUserSession&MockObject $userSession;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request       = $this->createMock(IRequest::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->cacheFactory  = $this->createMock(ICacheFactory::class);
        $this->cache         = $this->createMock(ICache::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
    }//end setUp()

    /**
     * Build the controller with the current mocks.
     *
     * @return BreachProxyController
     */
    private function controller(): BreachProxyController
    {
        return new BreachProxyController(
            $this->request,
            $this->appConfig,
            $this->clientService,
            $this->cacheFactory,
            $this->userSession,
            $this->logger,
        );
    }//end controller()

    /**
     * When the admin gate is off, every request is forbidden.
     *
     * @return void
     */
    public function testForbiddenWhenAdminGateOff(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(false);
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller()->range('ABCDE');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testForbiddenWhenAdminGateOff()

    /**
     * A non-5-hex prefix is rejected with 400 (never forwarded).
     *
     * @return void
     */
    public function testRejectsNonFiveHexPrefix(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->clientService->expects($this->never())->method('newClient');

        foreach (['ABC', 'ABCDEF', 'GHIJK', 'AB!DE', ''] as $bad) {
            $response = $this->controller()->range($bad);
            $this->assertSame(
                Http::STATUS_BAD_REQUEST,
                $response->getStatus(),
                "prefix '{$bad}' should be rejected"
            );
        }
    }//end testRejectsNonFiveHexPrefix()

    /**
     * A valid prefix is forwarded and the suffix list returned verbatim.
     *
     * @return void
     */
    public function testForwardsAndReturnsVerbatim(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->cache->method('get')->willReturn(null);

        $body = "0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n00D4F6E8FA6EECAD2A3AA415EEC418D38EC:2";
        $httpResponse = $this->createMock(IResponse::class);
        $httpResponse->method('getBody')->willReturn($body);
        $client = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('get')
            ->with(
                $this->stringContains('/range/ABCDE'),
                $this->callback(function ($opts) {
                    return isset($opts['headers']['Add-Padding'])
                        && $opts['headers']['Add-Padding'] === 'true';
                })
            )
            ->willReturn($httpResponse);
        $this->clientService->method('newClient')->willReturn($client);
        $this->cache->expects($this->once())->method('set')->with('ABCDE', $body, $this->anything());

        $response = $this->controller()->range('abcde');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['suffixes' => $body], $response->getData());
    }//end testForwardsAndReturnsVerbatim()

    /**
     * A cache hit returns the cached body without an upstream call.
     *
     * @return void
     */
    public function testCacheHitSkipsUpstream(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->cache->method('get')->willReturn('CAFE:5');
        $this->clientService->expects($this->never())->method('newClient');

        $response = $this->controller()->range('ABCDE');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['suffixes' => 'CAFE:5'], $response->getData());
    }//end testCacheHitSkipsUpstream()

    /**
     * Upstream failure soft-degrades to 503; the log line never combines the
     * prefix with a user id (privacy — k-anonymity is undermined by logging
     * who looked up which prefix).
     *
     * @return void
     */
    public function testUpstreamFailureDegradesAndDoesNotLogPrefixWithUser(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->cache->method('get')->willReturn(null);

        $client = $this->createMock(IClient::class);
        $client->method('get')->willThrowException(new \RuntimeException('boom'));
        $this->clientService->method('newClient')->willReturn($client);

        $logged = [];
        $this->logger->method('warning')->willReturnCallback(
            function (string $message) use (&$logged): void {
                $logged[] = $message;
            }
        );

        $response = $this->controller()->range('ABCDE');

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
        foreach ($logged as $line) {
            $this->assertFalse(
                str_contains($line, 'ABCDE') && str_contains($line, 'alice'),
                'A log line must never combine the prefix with the user id.'
            );
        }
    }//end testUpstreamFailureDegradesAndDoesNotLogPrefixWithUser()
}//end class
