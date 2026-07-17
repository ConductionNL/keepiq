<?php

/**
 * Unit tests asserting that every #[PublicPage] controller method carries
 * the documented #[AnonRateLimit] attribute with the configured limit and
 * period.
 *
 * These tests are attribute-reflection based rather than end-to-end 429
 * assertions: exercising Nextcloud's `RateLimitingMiddleware` for a real
 * 429 response requires a running Nextcloud instance with its cache
 * backend wired (the middleware is dispatched by the app framework, not
 * invoked by the controller itself), which is out of scope for an
 * isolated PHPUnit run. This suite instead guards against the attribute
 * being silently dropped or its limit/period being loosened without a
 * conscious edit, per the documented rationale in
 * docs/ARCHITECTURE.md#41-public-endpoint-rate-limits.
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
 * @spec openspec/changes/public-endpoint-rate-limits/tasks.md#task-8.1
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Controller;

use OCA\Doriath\Controller\ApplicationController;
use OCA\Doriath\Controller\ApplicationSecretsController;
use OCA\Doriath\Controller\ApplicationTokenController;
use OCA\Doriath\Controller\LinkShareAccessController;
use OCA\Doriath\Controller\SecretRequestFillController;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for #[AnonRateLimit] coverage on public endpoints.
 */
class RateLimitAttributesTest extends TestCase
{
    /**
     * Data provider mapping each public endpoint to its documented limit.
     *
     * @return array<string, array{0: class-string, 1: string, 2: int, 3: int}>
     */
    public static function publicEndpointsProvider(): array
    {
        return [
            'token exchange'            => [ApplicationTokenController::class, 'exchange', 10, 60],
            'link share show'           => [LinkShareAccessController::class, 'show', 15, 60],
            'link share confirm'        => [LinkShareAccessController::class, 'confirm', 15, 60],
            'secret request show'       => [SecretRequestFillController::class, 'show', 20, 60],
            'secret request fill'       => [SecretRequestFillController::class, 'fill', 20, 60],
            'application secrets index' => [ApplicationSecretsController::class, 'index', 30, 60],
            'application secrets show'  => [ApplicationSecretsController::class, 'show', 30, 60],
            'application create'        => [ApplicationController::class, 'create', 10, 60],
        ];
    }//end publicEndpointsProvider()

    /**
     * Every documented public endpoint carries #[AnonRateLimit] with the
     * expected limit and period.
     *
     * @param class-string $controllerClass The controller class
     * @param string       $method          The method name
     * @param int          $expectedLimit   The expected `limit` value
     * @param int          $expectedPeriod  The expected `period` value
     *
     * @return void
     *
     * @dataProvider publicEndpointsProvider
     */
    public function testEndpointCarriesAnonRateLimit(
        string $controllerClass,
        string $method,
        int $expectedLimit,
        int $expectedPeriod,
    ): void {
        $reflection = new ReflectionMethod($controllerClass, $method);
        $attributes = $reflection->getAttributes(AnonRateLimit::class);

        $this->assertNotEmpty(
            $attributes,
            sprintf(
                '%s::%s must carry #[AnonRateLimit] — see docs/ARCHITECTURE.md#41-public-endpoint-rate-limits',
                $controllerClass,
                $method
            )
        );

        $instance = $attributes[0]->newInstance();

        $this->assertSame(
            $expectedLimit,
            $instance->getLimit(),
            sprintf('%s::%s AnonRateLimit limit changed from the documented value', $controllerClass, $method)
        );
        $this->assertSame(
            $expectedPeriod,
            $instance->getPeriod(),
            sprintf('%s::%s AnonRateLimit period changed from the documented value', $controllerClass, $method)
        );
    }//end testEndpointCarriesAnonRateLimit()
}//end class
