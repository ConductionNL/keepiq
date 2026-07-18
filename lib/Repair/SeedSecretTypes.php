<?php

/**
 * Doriath Seed Secret Types Repair Step
 *
 * Idempotently seeds the 7 immutable system SecretTypes on install and
 * upgrade, using deterministic (UUID v5) identifiers so the IDs are stable
 * across instances and re-runs. The `totp` type marks a secret whose encrypted
 * `key` field holds a TOTP seed (an `otpauth://totp` URI or bare base32 secret)
 * — a UI hint that drives a client-side RFC 6238 code generator; the seed rides
 * in the existing `key` ciphertext blob, so no schema column is added and the
 * server cannot distinguish a `totp` secret from any other.
 *
 * @category Repair
 * @package  OCA\Doriath\Repair
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

namespace OCA\Doriath\Repair;

use DateTime;
use OCA\Doriath\Db\SecretType;
use OCA\Doriath\Db\SecretTypeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Seeds the 7 immutable system SecretTypes.
 *
 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-secret-types
 */
class SeedSecretTypes implements IRepairStep
{
    /**
     * The fixed namespace UUID for deterministic system-type IDs.
     *
     * @var string
     */
    public const TYPE_NAMESPACE = '6f9619ff-8b86-d011-b42d-00c04fc964ff';

    /**
     * The 7 system types as name => label pairs.
     *
     * The `totp` type is a UI hint (like every other type): its encrypted `key`
     * field holds a TOTP seed and the client renders an RFC 6238 code generator.
     * No schema column is introduced — the seed is ciphertext in `key`.
     *
     * @var array<string,string>
     */
    public const SYSTEM_TYPES = [
        'login'       => 'Login',
        'api_key'     => 'API Key',
        'ssh_key'     => 'SSH Key',
        'certificate' => 'Certificate',
        'note'        => 'Secure Note',
        'database'    => 'Database',
        'totp'        => 'Authenticator (TOTP)',
        // The `passkey` type is a UI hint like `totp`: its encrypted `key`
        // field holds the canonical CXF-aligned credential JSON and the
        // client renders the passkey presentation. No schema column — the
        // credential is ciphertext in `key` (passkey-item-type D1/D2).
        'passkey'     => 'Passkey',
        // `card` / `identity` are UI hints like `passkey`: the composite
        // payload is a JSON object riding the encrypted `key` field. Card
        // brand + last-4 are derived in-browser and never stored; BSN is
        // ciphertext (card-identity-items D1/D2, no schema change).
        'card'        => 'Payment Card',
        'identity'    => 'Identity',
    ];

    /**
     * Constructor for SeedSecretTypes.
     *
     * @param SecretTypeMapper $mapper The secret type mapper
     * @param LoggerInterface  $logger The logger interface
     *
     * @return void
     */
    public function __construct(
        private SecretTypeMapper $mapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Seed Doriath system secret types';
    }//end getName()

    /**
     * Compute the deterministic UUID v5 for a system type name.
     *
     * @param string $name The system type name
     *
     * @return string The deterministic UUID
     */
    public static function deterministicId(string $name): string
    {
        return Uuid::uuid5(self::TYPE_NAMESPACE, 'doriath:secret-type:'.$name)->toString();
    }//end deterministicId()

    /**
     * Run the repair step, upserting each system type idempotently.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $created = 0;

        foreach (self::SYSTEM_TYPES as $name => $label) {
            try {
                $this->mapper->findByName($name);
                // Already exists — leave it untouched (immutable system type).
                continue;
            } catch (DoesNotExistException) {
                // Not present yet — create it.
            }

            $type = new SecretType();
            $type->setId(self::deterministicId(name: $name));
            $type->setName($name);
            $type->setLabel($label);
            $type->setScope('system');
            $type->setOwnerId(null);
            $type->setCreatedAt(new DateTime());

            $this->mapper->insert($type);
            $created++;
        }

        $output->info('Doriath: seeded '.$created.' system secret types ('.count(self::SYSTEM_TYPES).' total)');
        $this->logger->info('Doriath: SeedSecretTypes created '.$created.' new system types');
    }//end run()
}//end class
