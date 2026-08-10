<?php

/**
 * Doriath Application JWK Resolver
 *
 * Resolves the verification key of a registered application: its active
 * EncryptionSuite's certificate, converted to a JWK. Supports both RSA
 * (RS256) and EC (ES256) public keys. This is the one place that walks
 * application -> active suite -> certificate -> public key, so neither
 * the assertion verifier nor the token service has to know that chain.
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

use Jose\Component\Core\JWK;
use OCA\Doriath\Db\Application;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Support\JwkFactoryAdapter;
use OCP\AppFramework\Db\DoesNotExistException;
use RuntimeException;
use Throwable;

/**
 * Resolves an application's JWS verification key.
 */
class ApplicationJwkResolver
{
    /**
     * Constructor for ApplicationJwkResolver.
     *
     * @param EncryptionSuiteMapper $suiteMapper The encryption-suite mapper
     * @param JwkFactoryAdapter     $jwkFactory  The JWK factory
     *
     * @return void
     *
     * @spec exclude Constructor wiring only; no behaviour.
     */
    public function __construct(
        private EncryptionSuiteMapper $suiteMapper,
        private JwkFactoryAdapter $jwkFactory=new JwkFactoryAdapter(),
    ) {
    }//end __construct()

    /**
     * The verification key of an application, taken from the certificate of
     * its active encryption suite.
     *
     * @param Application $application The issuing application
     *
     * @return JWK
     *
     * @throws RuntimeException When the application has no active suite or
     *                          no usable certificate.
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
     */
    public function forApplication(Application $application): JWK
    {
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

        return $this->buildJwkFromCertificate(pemCertificate: $certificate);
    }//end forApplication()

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
            return $this->jwkFactory->createFromKey($publicKeyPem);
        } catch (Throwable $e) {
            throw new RuntimeException(message: 'Unsupported public key: '.$e->getMessage());
        }
    }//end buildJwkFromCertificate()
}//end class
