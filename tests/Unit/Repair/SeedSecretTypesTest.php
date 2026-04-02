<?php

/**
 * Unit tests for SeedSecretTypes repair step.
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
 * Tests for SeedSecretTypes repair step.
 */
class SeedSecretTypesTest extends TestCase
{

    /**
     * The repair step under test.
     *
     * @var SeedSecretTypes
     */
    private SeedSecretTypes $repairStep;

    /**
     * The mocked secret type mapper.
     *
     * @var SecretTypeMapper
     */
    private SecretTypeMapper $mapper;

    /**
     * The mocked migration output.
     *
     * @var IOutput
     */
    private IOutput $output;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper = $this->createMock(originalClassName: SecretTypeMapper::class);
        $logger       = $this->createMock(originalClassName: LoggerInterface::class);
        $this->output = $this->createMock(originalClassName: IOutput::class);

        $this->repairStep = new SeedSecretTypes(
            secretTypeMapper: $this->mapper,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test that run inserts all 6 system types when none exist yet.
     *
     * @return void
     */
    public function testSeedsAllSixTypes(): void
    {
        $this->mapper->method('findByName')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->mapper->expects($this->exactly(6))->method('insert');

        $this->repairStep->run($this->output);
    }//end testSeedsAllSixTypes()

    /**
     * Test that run skips all types when they already exist (idempotent).
     *
     * @return void
     */
    public function testIdempotent(): void
    {
        $existing = new SecretType();
        $existing->setScope('system');

        $this->mapper->method('findByName')->willReturn($existing);

        $this->mapper->expects($this->never())->method('insert');

        $this->repairStep->run($this->output);
    }//end testIdempotent()

    /**
     * Test that the same name always produces the same UUID (deterministic).
     *
     * @return void
     */
    public function testDeterministicUuids(): void
    {
        $insertedIds = [];

        $this->mapper->method('findByName')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->mapper->method('insert')
            ->willReturnCallback(function (SecretType $type) use (&$insertedIds): SecretType {
                $insertedIds[$type->getName()] = $type->getId();
                return $type;
            });

        // First run — collect UUIDs.
        $this->repairStep->run($this->output);
        $firstRunIds = $insertedIds;

        // Reset collected IDs for second run.
        $insertedIds = [];

        // Second run — collect UUIDs again.
        $this->repairStep->run($this->output);
        $secondRunIds = $insertedIds;

        // Each name must produce the same UUID in both runs.
        foreach ($firstRunIds as $name => $uuid) {
            $this->assertEquals(
                expected: $uuid,
                actual: $secondRunIds[$name],
                message: "UUID for type '{$name}' should be deterministic"
            );
        }

        // All 6 expected names must be present.
        $this->assertCount(expectedCount: 6, haystack: $firstRunIds);
    }//end testDeterministicUuids()
}//end class
