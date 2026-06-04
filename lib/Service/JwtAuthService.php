<?php

/**
 * Doriath JWT Auth Service
 *
 * RFC 7523 JWT Bearer assertion verification for application API access.
 * Applications sign a short-lived JWT with their own RSA private key; the
 * server verifies the signature against the public certificate stored in
 * the application's EncryptionSuite, then issues an opaque short-lived
 * access token.
 *
 * Signature verification uses PHP's native OpenSSL (RS256) against the
 * certificate's public key — consistent with the rest of Doriath's
 * certificate handling and avoiding an uninstallable external dependency.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use InvalidArgumentException;
use OCA\Doriath\Db\Application;
use OCA\Doriath\Db\ApplicationMapper;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * RFC 7523 JWT Bearer token exchange and access-token validation.
 */
class JwtAuthService
{
    /**
     * The expected audience claim value.
     *
     * @var string
     */
    public const AUDIENCE = 'doriath';

    /**
     * Access token / replay-cache lifetime in seconds.
     *
     * @var int
     */
    public const TOKEN_TTL = 300;

    /**
     * Permitted clock skew for iat validation, in seconds.
     *
     * @var int
     */
    public const CLOCK_SKEW = 60;

    /**
     * Cache namespace for jti replay prevention.
     *
     * @var string
     */
    private const JTI_CACHE = 'doriath_jwt_jti';

    /**
     * Cache namespace for issued access tokens.
     *
     * @var string
     */
    private const TOKEN_CACHE = 'doriath_jwt_token';

    /**
     * Constructor for JwtAuthService.
     *
     * @param ApplicationMapper     $applicationMapper The application mapper
     * @param EncryptionSuiteMapper $suiteMapper       The encryption suite mapper
     * @param ICacheFactory         $cacheFactory      The distributed cache factory
     * @param LoggerInterface       $logger            The logger interface
     *
     * @return void
     */
    public function __construct(
        private ApplicationMapper $applicationMapper,
        private EncryptionSuiteMapper $suiteMapper,
        private ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Exchange a signed JWT assertion for an opaque access token.
     *
     * @param string $assertion The compact-serialised JWT
     *
     * @return array{access_token: string, token_type: string, expires_in: int}
     *
     * @throws InvalidArgumentException When the assertion is invalid
     */
    public function exchangeAssertion(string $assertion): array
    {
        [$header, $claims, $signingInput, $signature] = $this->decode(assertion: $assertion);

        if (($header['alg'] ?? '') !== 'RS256') {
            throw new InvalidArgumentException('Unsupported JWT algorithm');
        }

        $issuer = ($claims['iss'] ?? '');
        if ($issuer === '') {
            throw new InvalidArgumentException('Missing iss claim');
        }

        $application = $this->resolveActiveApplication(applicationId: $issuer);
        $publicKey   = $this->resolvePublicKey(applicationId: $application->getId());

        $verified = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new InvalidArgumentException('JWT signature verification failed');
        }

        $this->validateClaims(claims: $claims);
        $this->guardReplay(jti: (string) $claims["jti"]);

        $accessToken = bin2hex(random_bytes(32));
        $this->tokenCache()->set($accessToken, $application->getId(), self::TOKEN_TTL);

        $this->logger->info("Doriath: issued access token for application {$application->getId()}");

        return [
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => self::TOKEN_TTL,
        ];
    }//end exchangeAssertion()

    /**
     * Validate an opaque access token and return its application.
     *
     * @param string $token The opaque access token
     *
     * @return Application|null The application, or null when the token is invalid/expired
     */
    public function validateAccessToken(string $token): ?Application
    {
        if ($token === '') {
            return null;
        }

        $applicationId = $this->tokenCache()->get($token);
        if ($applicationId === null || is_string($applicationId) === false) {
            return null;
        }

        try {
            $application = $this->applicationMapper->findById($applicationId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            return null;
        }

        if ($application->getStatus() !== 'active') {
            return null;
        }

        return $application;
    }//end validateAccessToken()

    /**
     * Decode a compact JWT into its parts.
     *
     * @param string $assertion The compact JWT
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: string, 3: string}
     *
     * @throws InvalidArgumentException When the structure is malformed
     */
    private function decode(string $assertion): array
    {
        $parts = explode('.', $assertion);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Malformed JWT');
        }

        [$headerB64, $claimsB64, $signatureB64] = $parts;

        $header    = json_decode($this->base64UrlDecode(data: $headerB64), true);
        $claims    = json_decode($this->base64UrlDecode(data: $claimsB64), true);
        $signature = $this->base64UrlDecode(data: $signatureB64);

        if (is_array($header) === false || is_array($claims) === false || $signature === '') {
            throw new InvalidArgumentException('Malformed JWT payload');
        }

        return [$header, $claims, $headerB64.'.'.$claimsB64, $signature];
    }//end decode()

    /**
     * Validate the registered claims (aud, exp, iat, jti).
     *
     * @param array<string,mixed> $claims The decoded claim set
     *
     * @return void
     *
     * @throws InvalidArgumentException When any claim fails validation
     */
    private function validateClaims(array $claims): void
    {
        $now = time();

        if (($claims['aud'] ?? '') !== self::AUDIENCE) {
            throw new InvalidArgumentException('Invalid audience');
        }

        if (isset($claims['exp']) === false || (int) $claims['exp'] <= $now) {
            throw new InvalidArgumentException('Assertion expired');
        }

        if (isset($claims['iat']) === false || (int) $claims['iat'] > ($now + self::CLOCK_SKEW)) {
            throw new InvalidArgumentException('Invalid iat claim');
        }

        if (isset($claims['jti']) === false || (string) $claims['jti'] === '') {
            throw new InvalidArgumentException('Missing jti claim');
        }
    }//end validateClaims()

    /**
     * Reject a replayed jti and record it for the TTL window.
     *
     * @param string $jti The unique token identifier
     *
     * @return void
     *
     * @throws InvalidArgumentException When the jti was already used
     */
    private function guardReplay(string $jti): void
    {
        $cache = $this->cacheFactory->createDistributed(self::JTI_CACHE);
        if ($cache->get($jti) !== null) {
            throw new InvalidArgumentException('JWT assertion replay detected');
        }

        $cache->set($jti, 1, self::TOKEN_TTL);
    }//end guardReplay()

    /**
     * Resolve an active application by its ID.
     *
     * @param string $applicationId The application ID (= iss claim)
     *
     * @return Application
     *
     * @throws InvalidArgumentException When the application is missing or inactive
     */
    private function resolveActiveApplication(string $applicationId): Application
    {
        try {
            $application = $this->applicationMapper->findById($applicationId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            throw new InvalidArgumentException('Unknown application');
        }

        if ($application->getStatus() !== 'active') {
            throw new InvalidArgumentException('Application is not active');
        }

        return $application;
    }//end resolveActiveApplication()

    /**
     * Resolve an application's active public key for signature verification.
     *
     * @param string $applicationId The application ID
     *
     * @return \OpenSSLAsymmetricKey The public key resource
     *
     * @throws InvalidArgumentException When no usable certificate exists
     */
    private function resolvePublicKey(string $applicationId): \OpenSSLAsymmetricKey
    {
        try {
            $suite = $this->suiteMapper->findActiveByOwner(ownerType: 'application', ownerId: $applicationId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            throw new InvalidArgumentException('No active encryption suite for application');
        }

        $certificate = $suite->getCertificate();
        if ($certificate === null || $certificate === '') {
            throw new InvalidArgumentException('Application certificate missing');
        }

        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            throw new InvalidArgumentException('Unable to read application certificate');
        }

        return $publicKey;
    }//end resolvePublicKey()

    /**
     * Get the access-token cache.
     *
     * @return \OCP\ICache
     */
    private function tokenCache(): \OCP\ICache
    {
        return $this->cacheFactory->createDistributed(self::TOKEN_CACHE);
    }//end tokenCache()

    /**
     * Decode a base64url-encoded string.
     *
     * @param string $data The base64url string
     *
     * @return string The decoded bytes (empty string on failure)
     */
    private function base64UrlDecode(string $data): string
    {
        $remainder = (strlen($data) % 4);
        if ($remainder !== 0) {
            $data .= str_repeat('=', (4 - $remainder));
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            return '';
        }

        return $decoded;
    }//end base64UrlDecode()
}//end class
