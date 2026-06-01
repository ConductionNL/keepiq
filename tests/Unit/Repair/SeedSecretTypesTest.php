<?php

/**
 * Doriath SeedSecretTypes repair-step unit tests.
 *
 * @category Tests
 * @package  OCA\Doriath\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Repair;

use OCA\Doriath\Db\SecretType;
use OCA\Doriath\Db\SecretTypeMapper;
use OCA\Doriath\Repair\SeedSecretTypes;
use OCA\Doriath\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SeedSecretTypesTest extends TestCase
{
    private SecretTypeMapper $mapper;
    private SeedSecretTypes $step;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(SecretTypeMapper::class);
        $logger = $this->createMock(LoggerInterface::class);
        $this->step = new SeedSecretTypes($this->mapper, $logger);
    }

    public function testSeedsAllSixSystemTypesOnFirstRun(): void
    {
        $this->mapper->method('findById')
            ->willThrowException(new DoesNotExistException('none'));
        $this->mapper->expects($this->exactly(6))->method('insert');

        $this->step->run($this->createMock(IOutput::class));
    }

    public function testIdempotentWhenTypesExist(): void
    {
        $this->mapper->method('findById')->willReturn(new SecretType());
        $this->mapper->expects($this->never())->method('insert');

        $this->step->run($this->createMock(IOutput::class));
    }

    public function testDeterministicLoginId(): void
    {
        $expected = SecretTypeService::systemTypeId('login');
        $this->assertSame($expected, SecretTypeService::systemTypeId('login'));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $expected
        );
    }
}
