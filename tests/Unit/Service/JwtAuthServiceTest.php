<?php

/**
 * Unit tests for JwtAuthService.
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

use InvalidArgumentException;
use OCA\Doriath\Db\Application;
use OCA\Doriath\Db\ApplicationMapper;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Service\JwtAuthService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for JwtAuthService.
 */
class JwtAuthServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var JwtAuthService
     */
    private JwtAuthService $service;

    /**
     * The mocked application mapper.
     *
     * @var ApplicationMapper
     */
    private ApplicationMapper $appMapper;

    /**
     * The mocked encryption suite mapper.
     *
     * @var EncryptionSuiteMapper
     */
    private EncryptionSuiteMapper $suiteMapper;

    /**
     * An in-memory cache stand-in.
     *
     * @var array<string,mixed>
     */
    private array $cacheStore = [];

    /**
     * The application's RSA private key (PEM) used to sign assertions.
     *
     * @var string
     */
    private string $privateKeyPem;

    /**
     * The application's certificate (PEM) holding the public key.
     *
     * @var string
     */
    private string $certificatePem;

    /**
     * The seeded application ID.
     *
     * @var string
     */
    private string $appId = 'app-123';

    /**
     * Set up test fixtures, including a real RSA key pair and self-signed cert.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $key = openssl_pkey_new(
            [
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );
        $privateKeyPem = '';
        openssl_pkey_export($key, $privateKeyPem);
        $this->privateKeyPem = $privateKeyPem;

        $csr  = openssl_csr_new(['commonName' => 'app:'.$this->appId], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        $certificatePem = '';
        openssl_x509_export($cert, $certificatePem);
        $this->certificatePem = $certificatePem;

        $this->appMapper   = $this->createMock(originalClassName: ApplicationMapper::class);
        $this->suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);

        $cache = $this->createMock(originalClassName: ICache::class);
        $cache->method('get')->willReturnCallback(fn ($k) => ($this->cacheStore[$k] ?? null));
        $cache->method('set')->willReturnCallback(
            function ($k, $v) {
                $this->cacheStore[$k] = $v;
                return true;
            }
        );

        $cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $logger = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new JwtAuthService(
            applicationMapper: $this->appMapper,
            suiteMapper: $this->suiteMapper,
            cacheFactory: $cacheFactory,
            logger: $logger
        );
    }

    /**
     * Seed an active application + active suite for the happy path.
     *
     * @return void
     */
    private function seedActiveApplication(): void
    {
        $app = new Application();
        $app->setId($this->appId);
        $app->setName('App');
        $app->setStatus('active');
        $this->appMapper->method('findById')->willReturn($app);

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setOwnerType('application');
        $suite->setOwnerId($this->appId);
        $suite->setCertificate($this->certificatePem);
        $suite->setStatus('active');
        $this->suiteMapper->method('findActiveByOwner')->willReturn($suite);
    }

    /**
     * A validly signed assertion produces an opaque access token.
     *
     * @return void
     */
    public function testValidAssertionProducesAccessToken(): void
    {
        $this->seedActiveApplication();
        $jwt = $this->makeJwt();

        $result = $this->service->exchangeAssertion($jwt);

        $this->assertArrayHasKey('access_token', $result);
        $this->assertSame('Bearer', $result['token_type']);
        $this->assertSame(JwtAuthService::TOKEN_TTL, $result['expires_in']);
    }

    /**
     * A tampered signature is rejected.
     *
     * @return void
     */
    public function testInvalidSignatureRejected(): void
    {
        $this->seedActiveApplication();
        $jwt = $this->makeJwt();
        // Corrupt the signature segment.
        $parts    = explode('.', $jwt);
        $parts[2] = rtrim(strtr(base64_encode('garbage-signature'), '+/', '-_'), '=');
        $tampered = implode('.', $parts);

        $this->expectException(InvalidArgumentException::class);
        $this->service->exchangeAssertion($tampered);
    }

    /**
     * An expired assertion is rejected.
     *
     * @return void
     */
    public function testExpiredAssertionRejected(): void
    {
        $this->seedActiveApplication();
        $jwt = $this->makeJwt(['exp' => (time() - 10)]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->exchangeAssertion($jwt);
    }

    /**
     * A future-dated iat is rejected.
     *
     * @return void
     */
    public function testFutureIatRejected(): void
    {
        $this->seedActiveApplication();
        $jwt = $this->makeJwt(['iat' => (time() + 600)]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->exchangeAssertion($jwt);
    }

    /**
     * A wrong audience is rejected.
     *
     * @return void
     */
    public function testWrongAudienceRejected(): void
    {
        $this->seedActiveApplication();
        $jwt = $this->makeJwt(['aud' => 'someone-else']);

        $this->expectException(InvalidArgumentException::class);
        $this->service->exchangeAssertion($jwt);
    }

    /**
     * A replayed jti is rejected on the second exchange.
     *
     * @return void
     */
    public function testReplayedJtiRejected(): void
    {
        $this->seedActiveApplication();
        $jwt = $this->makeJwt(['jti' => 'fixed-jti']);

        $this->service->exchangeAssertion($jwt);

        $this->expectException(InvalidArgumentException::class);
        $this->service->exchangeAssertion($jwt);
    }

    /**
     * An assertion for an inactive application is rejected.
     *
     * @return void
     */
    public function testInactiveApplicationRejected(): void
    {
        $app = new Application();
        $app->setId($this->appId);
        $app->setStatus('pending');
        $this->appMapper->method('findById')->willReturn($app);

        $jwt = $this->makeJwt();
        $this->expectException(InvalidArgumentException::class);
        $this->service->exchangeAssertion($jwt);
    }

    /**
     * A freshly issued access token validates back to its application.
     *
     * @return void
     */
    public function testAccessTokenValidationSucceeds(): void
    {
        $this->seedActiveApplication();
        $result = $this->service->exchangeAssertion($this->makeJwt());

        $application = $this->service->validateAccessToken($result['access_token']);
        $this->assertNotNull($application);
        $this->assertSame($this->appId, $application->getId());
    }

    /**
     * An unknown access token validates to null.
     *
     * @return void
     */
    public function testUnknownAccessTokenReturnsNull(): void
    {
        $this->assertNull($this->service->validateAccessToken('does-not-exist'));
    }

    /**
     * Build a compact RS256 JWT signed with the application's private key.
     *
     * @param array<string,mixed> $claimOverrides Claim overrides
     *
     * @return string The compact JWT
     */
    private function makeJwt(array $claimOverrides=[]): string
    {
        $now    = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = array_merge(
            [
                'iss' => $this->appId,
                'sub' => $this->appId,
                'aud' => JwtAuthService::AUDIENCE,
                'iat' => $now,
                'exp' => ($now + 60),
                'jti' => bin2hex(random_bytes(8)),
            ],
            $claimOverrides
        );

        $segments     = [
            $this->b64(json_encode($header)),
            $this->b64(json_encode($claims)),
        ];
        $signingInput = implode('.', $segments);

        openssl_sign($signingInput, $signature, $this->privateKeyPem, OPENSSL_ALGO_SHA256);
        $segments[] = $this->b64($signature);

        return implode('.', $segments);
    }

    /**
     * Base64url-encode a string.
     *
     * @param string $data The raw bytes
     *
     * @return string The base64url string
     */
    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
