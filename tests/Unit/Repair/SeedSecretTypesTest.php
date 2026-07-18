<?php

/**
 * Unit tests for the SeedSecretTypes repair step.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Repair
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

namespace OCA\Doriath\Tests\Unit\Repair;

use OCA\Doriath\Db\SecretType;
use OCA\Doriath\Db\SecretTypeMapper;
use OCA\Doriath\Repair\SeedSecretTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SeedSecretTypes.
 */
class SeedSecretTypesTest extends TestCase
{

    /**
     * @var SecretTypeMapper
     */
    private $mapper;

    /**
     * @var SeedSecretTypes
     */
    private SeedSecretTypes $step;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper = $this->createMock(SecretTypeMapper::class);
        $logger       = $this->createMock(LoggerInterface::class);
        $this->step   = new SeedSecretTypes(mapper: $this->mapper, logger: $logger);
    }//end setUp()

    /**
     * On a fresh install every system type is inserted (one per
     * SYSTEM_TYPES entry — currently 10).
     *
     * @return void
     */
    public function testCreatesEightTypesOnFirstRun(): void
    {
        $this->mapper->method('findByName')->willThrowException(new DoesNotExistException('none'));
        $this->mapper->expects($this->exactly(count(SeedSecretTypes::SYSTEM_TYPES)))->method('insert');

        $this->step->run($this->createMock(IOutput::class));
    }//end testCreatesEightTypesOnFirstRun()

    /**
     * A re-run with all types present inserts nothing (idempotent).
     *
     * @return void
     */
    public function testIdempotentOnReRun(): void
    {
        $existing = new SecretType();
        $existing->setName('login');
        $this->mapper->method('findByName')->willReturn($existing);
        $this->mapper->expects($this->never())->method('insert');

        $this->step->run($this->createMock(IOutput::class));
    }//end testIdempotentOnReRun()

    /**
     * Deterministic IDs are stable and unique per name.
     *
     * @return void
     */
    public function testDeterministicIds(): void
    {
        $first  = SeedSecretTypes::deterministicId(name: 'login');
        $second = SeedSecretTypes::deterministicId(name: 'login');
        $other  = SeedSecretTypes::deterministicId(name: 'api_key');

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $other);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $first
        );
    }//end testDeterministicIds()

    /**
     * The ten canonical system type names are defined, in seed order.
     *
     * @return void
     */
    public function testSystemTypeNames(): void
    {
        $names = array_keys(SeedSecretTypes::SYSTEM_TYPES);
        $this->assertSame(
            ['login', 'api_key', 'ssh_key', 'certificate', 'note', 'database', 'totp', 'passkey', 'card', 'identity'],
            $names
        );
    }//end testSystemTypeNames()

    /**
     * The `totp` system type is seeded with a stable deterministic UUID and the
     * "Authenticator (TOTP)" label.
     *
     * @return void
     */
    public function testTotpTypeSeededDeterministically(): void
    {
        $this->assertArrayHasKey('totp', SeedSecretTypes::SYSTEM_TYPES);
        $this->assertSame('Authenticator (TOTP)', SeedSecretTypes::SYSTEM_TYPES['totp']);

        $id  = SeedSecretTypes::deterministicId(name: 'totp');
        $id2 = SeedSecretTypes::deterministicId(name: 'totp');
        $this->assertSame($id, $id2);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $id
        );
    }//end testTotpTypeSeededDeterministically()

    /**
     * The `passkey` system type is seeded with a stable deterministic UUID and
     * the "Passkey" label; no schema/migration change accompanies it — the
     * credential rides in the existing encrypted `key` field.
     *
     * @return void
     *
     * @spec openspec/changes/passkey-item-type/specs/passkey-item-type/spec.md#requirement-passkey-system-secret-type
     */
    public function testPasskeyTypeSeededDeterministically(): void
    {
        $this->assertArrayHasKey('passkey', SeedSecretTypes::SYSTEM_TYPES);
        $this->assertSame('Passkey', SeedSecretTypes::SYSTEM_TYPES['passkey']);

        $id  = SeedSecretTypes::deterministicId(name: 'passkey');
        $id2 = SeedSecretTypes::deterministicId(name: 'passkey');
        $this->assertSame($id, $id2);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $id
        );
    }//end testPasskeyTypeSeededDeterministically()

    /**
     * The `card` and `identity` system types are seeded with stable
     * deterministic UUIDs and their labels; no schema/migration change
     * accompanies them — the composite payloads ride in the existing
     * encrypted `key` field (card-identity-items D1/D2).
     *
     * @return void
     *
     * @spec openspec/changes/card-identity-items/specs/card-identity-items/spec.md#requirement-system-secret-types
     */
    public function testCardAndIdentityTypesSeededDeterministically(): void
    {
        $this->assertArrayHasKey('card', SeedSecretTypes::SYSTEM_TYPES);
        $this->assertSame('Payment Card', SeedSecretTypes::SYSTEM_TYPES['card']);
        $this->assertArrayHasKey('identity', SeedSecretTypes::SYSTEM_TYPES);
        $this->assertSame('Identity', SeedSecretTypes::SYSTEM_TYPES['identity']);

        foreach (['card', 'identity'] as $name) {
            $id  = SeedSecretTypes::deterministicId(name: $name);
            $id2 = SeedSecretTypes::deterministicId(name: $name);
            $this->assertSame($id, $id2);
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                $id
            );
        }

        $this->assertNotSame(
            SeedSecretTypes::deterministicId(name: 'card'),
            SeedSecretTypes::deterministicId(name: 'identity')
        );
    }//end testCardAndIdentityTypesSeededDeterministically()
}//end class
