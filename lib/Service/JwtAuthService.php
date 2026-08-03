<?php

/**
 * Doriath JWT Authentication Service
 *
 * Exchanges a JWT bearer assertion (RS256, signed by an application's
 * registered private key) for a short-lived opaque access token usable
 * on `/api/v1/app/*` routes. Verification uses the application's
 * EncryptionSuite certificate public key. Replay protection is enforced
 * via a distributed jti cache.
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

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use OCA\Doriath\Db\Application;
use OCA\Doriath\Db\ApplicationMapper;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Implements the JWT-Bearer "assertion -> access_token" exchange that
 * powers the `/api/v1/token` endpoint plus the access-token validation
 * helper consumed by JwtAuthMiddleware.
 *
 * Algorithm priority is RS256 (primary, mandatory for production); ES256
 * is supported as a fallback when the application's certificate carries
 * an EC public key.
 */
class JwtAuthService
{
    /**
     * Distributed cache namespace for jti replay protection.
     *
     * @var string
     */
    public const JTI_CACHE_NS = 'doriath_jwt_jti';

    /**
     * Distributed cache namespace for opaque access tokens.
     *
     * @var string
     */
    public const TOKEN_CACHE_NS = 'doriath_jwt_token';

    /**
     * Lifetime of issued access tokens in seconds (5 minutes per spec
     * §4.3).
     *
     * @var int
     */
    public const ACCESS_TOKEN_TTL = 300;

    /**
     * Allowed clock skew between issuer (`iat`) and verifier in seconds.
     *
     * @var int
     */
    public const CLOCK_SKEW_SECONDS = 60;

    /**
     * The expected audience claim ("aud") for assertions targeted at
     * this Doriath instance.
     *
     * @var string
     */
    public const EXPECTED_AUDIENCE = 'doriath';

    /**
     * Constructor for JwtAuthService.
     *
     * @param ApplicationMapper     $applicationMapper The application mapper
     * @param EncryptionSuiteMapper $suiteMapper       The encryption-suite mapper
     * @param ICacheFactory         $cacheFactory      The cache factory
     * @param LoggerInterface       $logger            The logger
     * @param AuditTrail|null       $auditTrail        The audit trail
     *
     * @return void
     */
    public function __construct(
        private ApplicationMapper $applicationMapper,
        private EncryptionSuiteMapper $suiteMapper,
        private ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
        private ?AuditTrail $auditTrail=null,
    ) {
    }//end __construct()

    /**
     * Exchange a JWT bearer assertion for a short-lived opaque access
     * token.
     *
     * The assertion MUST be a Compact-Serialized JWS signed RS256
     * (preferred) or ES256 with the application's registered private
     * key. Required claims: iss (application id), aud="doriath",
     * exp (>now), iat (<=now+CLOCK_SKEW), jti (unique within TTL).
     *
     * @param string $assertion The JWS compact serialization
     *
     * @return array{access_token:string,token_type:string,expires_in:int}
     *
     * @throws RuntimeException When the assertion is malformed, has
     *                          invalid claims, fails signature
     *                          verification, or replays a known jti.
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
     */
    public function exchangeAssertion(string $assertion): array
    {
        if ($assertion === '') {
            throw new RuntimeException(message: 'Assertion is empty');
        }

        $serializerManager = new JWSSerializerManager([new CompactSerializer()]);
        try {
            $jws = $serializerManager->unserialize($assertion);
        } catch (Throwable $e) {
            $this->logger->warning(
                'JwtAuthService: failed to deserialize assertion ('.$e->getMessage().')',
                ['app' => 'doriath']
            );
            throw new RuntimeException(message: 'Invalid assertion format');
        }

        $payloadRaw = $jws->getPayload();
        if ($payloadRaw === null) {
            throw new RuntimeException(message: 'Assertion has no payload');
        }

        $claims = json_decode($payloadRaw, true);
        if (is_array($claims) === false) {
            throw new RuntimeException(message: 'Assertion payload is not a JSON object');
        }

        // Required claims.
        foreach (['iss', 'aud', 'exp', 'iat', 'jti'] as $required) {
            if (array_key_exists($required, $claims) === false) {
                throw new RuntimeException(message: 'Missing required claim: '.$required);
            }
        }

        $issuer = (string) $claims['iss'];
        $now    = time();

        if ((string) $claims['aud'] !== self::EXPECTED_AUDIENCE) {
            throw new RuntimeException(message: 'Wrong audience');
        }

        if ((int) $claims['exp'] <= $now) {
            throw new RuntimeException(message: 'Assertion expired');
        }

        if ((int) $claims['iat'] > ($now + self::CLOCK_SKEW_SECONDS)) {
            throw new RuntimeException(message: 'Assertion iat in future');
        }

        // Bound the assertion lifetime to the documented maximum so a
        // consumer cannot mint a long-lived signed assertion that would
        // sit replayable in the jti window for hours (secret-store-api D7).
        if (((int) $claims['exp'] - (int) $claims['iat']) > self::ACCESS_TOKEN_TTL) {
            throw new RuntimeException(
                message: 'Assertion lifetime exceeds the maximum of '
                .self::ACCESS_TOKEN_TTL.' seconds'
            );
        }

        $jtiCache = $this->cacheFactory->createDistributed(self::JTI_CACHE_NS);
        $jti      = (string) $claims['jti'];
        if ($jtiCache->hasKey($jti) === true) {
            throw new RuntimeException(message: 'Assertion jti replayed');
        }

        // Look up the application.
        try {
            $application = $this->applicationMapper->findById($issuer);
        } catch (DoesNotExistException) {
            throw new RuntimeException(message: 'Unknown issuer');
        }

        if ($application->isActive() === false) {
            throw new RuntimeException(message: 'Issuer application is not active');
        }

        try {
            $suite = $this->suiteMapper->findActiveByOwner(
                ownerType: 'application',
                ownerId: $application->getId()
            );
        } catch (DoesNotExistException) {
            throw new RuntimeException(message: 'No active encryption suite for application');
        }

        $certificate = $suite->getCertificate();
        if ($certificate === null || $certificate === '') {
            throw new RuntimeException(message: 'Application has no certificate');
        }

        $jwk = $this->buildJwkFromCertificate(pemCertificate: $certificate);

        // RS256 primary, ES256 fallback.
        $algorithmManager = new AlgorithmManager([new RS256(), new ES256()]);
        $verifier         = new JWSVerifier($algorithmManager);

        if ($verifier->verifyWithKey($jws, $jwk, 0) === false) {
            throw new RuntimeException(message: 'Assertion signature verification failed');
        }

        // Store jti to prevent replay during max assertion lifetime.
        $jtiCache->set($jti, true, self::ACCESS_TOKEN_TTL);

        // Issue opaque access token bound to the application id.
        $accessToken = bin2hex(random_bytes(32));
        $tokenCache  = $this->cacheFactory->createDistributed(self::TOKEN_CACHE_NS);
        $tokenCache->set($accessToken, $application->getId(), self::ACCESS_TOKEN_TTL);

        $this->auditTrail?->forApplication(
            actorId: $application->getId(),
            eventType: AuditEventTypes::APPLICATION_TOKEN_ISSUED,
            objectType: 'application',
            objectId: $application->getId(),
            objectName: $application->getName(),
        );

        return [
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => self::ACCESS_TOKEN_TTL,
        ];
    }//end exchangeAssertion()

    /**
     * Validate an opaque access token and resolve the bound application.
     *
     * @param string $accessToken The opaque access token from the
     *                            `Authorization: Bearer <token>` header.
     *
     * @return Application|null The bound application, or null when the
     *                          token is unknown, expired, or the
     *                          application is no longer active.
     */
    public function validateAccessToken(string $accessToken): ?Application
    {
        if ($accessToken === '') {
            return null;
        }

        $tokenCache    = $this->cacheFactory->createDistributed(self::TOKEN_CACHE_NS);
        $applicationId = $tokenCache->get($accessToken);

        if (is_string($applicationId) === false || $applicationId === '') {
            return null;
        }

        try {
            $application = $this->applicationMapper->findById($applicationId);
        } catch (DoesNotExistException) {
            return null;
        }

        if ($application->isActive() === false) {
            return null;
        }

        return $application;
    }//end validateAccessToken()

    /**
     * Build a JWK from a PEM-encoded X.509 certificate. Supports both
     * RSA (RS256) and EC (ES256) public keys.
     *
     * @param string $pemCertificate The PEM-encoded certificate
     *
     * @return JWK
     *
     * @throws RuntimeException When the certificate cannot be parsed or
     *                          the public key is unsupported.
     */
    private function buildJwkFromCertificate(string $pemCertificate): JWK
    {
        $resource = openssl_pkey_get_public($pemCertificate);
        if ($resource === false) {
            throw new RuntimeException(message: 'Unable to extract public key from certificate');
        }

        $details = openssl_pkey_get_details($resource);
        if (is_array($details) === false || array_key_exists('key', $details) === false) {
            throw new RuntimeException(message: 'Unable to read public-key details');
        }

        $publicKeyPem = (string) $details['key'];

        try {
            return JWKFactory::createFromKey($publicKeyPem);
        } catch (Throwable $e) {
            throw new RuntimeException(message: 'Unsupported public key: '.$e->getMessage());
        }
    }//end buildJwkFromCertificate()
}//end class
