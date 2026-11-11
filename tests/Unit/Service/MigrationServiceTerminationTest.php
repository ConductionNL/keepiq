<?php

/**
 * The side effects of completing and aborting a migration, pinned in order.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Exception\MigrationAbortRefusedException;
use OCA\Keepiq\Exception\MigrationIncompleteException;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\MigrationWorkService;
use OCA\Keepiq\Service\PasskeyService;
use OCA\Keepiq\Service\WriteLockService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every call completeMigration and abortMigration make, in order.
 */
class MigrationServiceTerminationTest extends TestCase {

	/**
	 * The calls recorded so far.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The migration under test.
	 *
	 * @var SuiteMigration
	 */
	private SuiteMigration $migration;

	/**
	 * Whether the old suite resolves to an owner.
	 *
	 * @var bool
	 */
	private bool $ownerKnown = true;

	/**
	 * Whether the successor suite still exists.
	 *
	 * @var bool
	 */
	private bool $successorExists = true;

	/**
	 * What countCommitted answers.
	 *
	 * @var int
	 */
	private int $committed = 0;

	/**
	 * Set up a fresh in-progress migration.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->calls = [];
		$this->migration = new SuiteMigration();
		$this->migration->setId('migration-1');
		$this->migration->setOldSuiteId('old-suite');
		$this->migration->setNewSuiteId('new-suite');
		$this->migration->setStatus('in_progress');
	}//end setUp()

	/**
	 * Build the service over recording collaborators.
	 *
	 * @return MigrationService
	 */
	private function service(): MigrationService {
		$mapper = $this->recording(class: SuiteMigrationMapper::class, methods: ['update']);
		$mapper->method('findById')->willReturnCallback(fn (string $id): SuiteMigration => $this->record(call: ['migrations.findById', $id], answer: $this->migration));

		$suites = $this->recording(class: EncryptionSuiteMapper::class, methods: ['delete']);
		$suites->method('findById')->willReturnCallback(fn (string $id): EncryptionSuite => $this->suite(id: $id));

		$work = $this->recording(
			class: MigrationWorkService::class,
			methods: ['clearFailureAccounting']
		);
		$work->method('countCommitted')->willReturnCallback(fn (...$args): int => $this->record(call: ['work.countCommitted', $args[1] ?? null], answer: $this->committed));
		$work->method('dropVersionsBeyondWindow')->willReturnCallback(fn (...$args): int => $this->record(call: ['work.dropVersionsBeyondWindow', $args[1] ?? null], answer: 2));
		$work->method('countOutstanding')->willReturnCallback(fn (...$args): array => $this->record(call: ['work.countOutstanding'], answer: ['unaccountedTotal' => 0, 'failedTotal' => 0]));
		$work->method('listUnrecoverable')->willReturnCallback(fn (...$args): array => $this->record(call: ['work.listUnrecoverable'], answer: []));
		$work->method('countUnrecoverable')->willReturnCallback(fn (...$args): int => $this->record(call: ['work.countUnrecoverable'], answer: 0));

		$suiteService = $this->recording(class: EncryptionSuiteService::class, methods: ['markCompromised', 'revokeSuite']);
		$links = $this->recording(class: LinkShareService::class, methods: ['deleteMadeBy']);
		$passkeys = $this->recording(class: PasskeyService::class, methods: ['deleteAllOnRotation']);
		$logger = $this->recording(class: LoggerInterface::class, methods: ['info', 'warning', 'error']);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$described = [$event::class];
			if ($event instanceof AuditEvent) {
				$described[] = [
					'actorType' => $event->getActorType(),
					'actorId' => $event->getActorId(),
					'eventType' => $event->getEventType(),
					'objectType' => $event->getObjectType(),
					'objectId' => $event->getObjectId(),
					'metadata' => $event->getMetadata(),
				];
			} else {
				$described[] = [
					'oldSuiteId' => $event->getOldSuiteId(),
					'newSuiteId' => $event->getNewSuiteId(),
					'migrationId' => $event->getMigrationId(),
				];
				if (method_exists($event, 'hasErrors') === true) {
					$described[1]['hasErrors'] = $event->hasErrors();
				}
			}

			$this->calls[] = array_merge(['dispatch'], $described);
		});

		return new MigrationService(
			mapper: $mapper,
			suiteMapper: $suites,
			suiteService: $suiteService,
			linkShareService: $links,
			workService: $work,
			writeLockService: $this->createMock(WriteLockService::class),
			logger: $logger,
			eventDispatcher: $dispatcher,
			passkeyService: $passkeys,
		);
	}//end service()

	/**
	 * A mock whose listed methods record their calls.
	 *
	 * @param class-string $class The class to mock
	 * @param string[] $methods The methods to record
	 *
	 * @return MockObject
	 */
	private function recording(string $class, array $methods): MockObject {
		$mock = $this->createMock($class);
		$short = substr($class, strrpos($class, '\\') + 1);
		foreach ($methods as $method) {
			$mock->method($method)->willReturnCallback(function (...$args) use ($short, $method) {
				$this->calls[] = array_merge([$short . '.' . $method], array_map(fn ($a) => $this->describe(value: $a), $args));
				if (in_array($method, ['update', 'delete'], true) === true) {
					return $args[0];
				}

				if ($method === 'markCompromised') {
					return new EncryptionSuite();
				}

				return null;
			});
		}

		return $mock;
	}//end recording()

	/**
	 * Record a call and answer with a value.
	 *
	 * @param array<int,mixed> $call The call
	 * @param mixed $answer The answer
	 *
	 * @return mixed
	 */
	private function record(array $call, mixed $answer): mixed {
		$this->calls[] = array_map(fn ($a) => $this->describe(value: $a), $call);
		return $answer;
	}//end record()

	/**
	 * A suite by id, or DoesNotExistException as arranged.
	 *
	 * @param string $id The suite id
	 *
	 * @return EncryptionSuite
	 */
	private function suite(string $id): EncryptionSuite {
		$this->calls[] = ['suites.findById', $id];
		if (($id === 'old-suite' && $this->ownerKnown === false) || ($id === 'new-suite' && $this->successorExists === false)) {
			throw new DoesNotExistException('missing');
		}

		$suite = new EncryptionSuite();
		$suite->setId($id);
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');

		return $suite;
	}//end suite()

	/**
	 * Describe a value for the trace.
	 *
	 * @param mixed $value The value
	 *
	 * @return mixed
	 */
	private function describe(mixed $value): mixed {
		if ($value instanceof SuiteMigration) {
			return ['migration', $value->getId(), $value->getStatus(), $value->getCompletedAt() !== null];
		}

		if ($value instanceof EncryptionSuite) {
			return ['suite', $value->getId()];
		}

		if (is_object($value) === true) {
			return get_class($value);
		}

		return $value;
	}//end describe()

	/**
	 * Run one call and answer its outcome and trace.
	 *
	 * @param callable $call The service call
	 *
	 * @return array<string,mixed>
	 */
	private function trace(callable $call): array {
		try {
			$outcome = $call($this->service());
			unset($outcome['startedAt'], $outcome['completedAt']);
		} catch (Throwable $e) {
			$outcome = [get_class($e), $e->getMessage()];
		}

		return ['outcome' => $outcome, 'calls' => $this->calls];
	}//end trace()

	/**
	 * Completing runs the terminal steps in this exact order.
	 *
	 * @return void
	 */
	public function testCompleteCallsInOrder(): void {
		$trace = $this->trace(fn (MigrationService $s) => $s->completeMigration('migration-1', true, 0));

		$this->assertSame(
			[
				['migrations.findById', 'migration-1'],
				['suites.findById', 'old-suite'],
				['work.dropVersionsBeyondWindow', 'alice'],
				['work.countOutstanding'],
				['work.listUnrecoverable'],
				['work.countUnrecoverable'],
				['SuiteMigrationMapper.update', ['migration', 'migration-1', 'completed_with_errors', true]],
				['EncryptionSuiteService.markCompromised', 'old-suite', 'alice'],
				['LinkShareService.deleteMadeBy', 'alice'],
				['PasskeyService.deleteAllOnRotation', 'alice'],
				['MigrationWorkService.clearFailureAccounting', ['migration', 'migration-1', 'completed_with_errors', true]],
				['LoggerInterface.info', 'Keepiq: Compromise recovery completed for migration migration-1', ['hasErrors' => true]],
			],
			array_values(array_filter($trace['calls'], static fn (array $call): bool => $call[0] !== 'dispatch'))
		);
		$this->assertSame('completed_with_errors', $trace['outcome']['status']);
		$this->assertSame(2, $trace['outcome']['droppedVersions']);
		$this->assertSame([], $trace['outcome']['unrecoverable']);
		$this->assertSame(
			['dispatch', SuiteMigrationCompletedEvent::class, ['oldSuiteId' => 'old-suite', 'newSuiteId' => 'new-suite', 'migrationId' => 'migration-1', 'hasErrors' => true]],
			end($trace['calls'])
		);
	}//end testCompleteCallsInOrder()

	/**
	 * A completion without errors is plainly completed.
	 *
	 * @return void
	 */
	public function testCompleteWithoutErrorsIsCompleted(): void {
		$trace = $this->trace(fn (MigrationService $s) => $s->completeMigration('migration-1'));

		$this->assertSame('completed', $trace['outcome']['status']);
		$this->assertContains(['LoggerInterface.info', 'Keepiq: Compromise recovery completed for migration migration-1', ['hasErrors' => false]], $trace['calls']);
	}//end testCompleteWithoutErrorsIsCompleted()

	/**
	 * Completing an already terminated migration logs and answers the no-op.
	 *
	 * @return void
	 */
	public function testCompleteOfTerminatedMigrationIsIgnored(): void {
		$this->migration->setStatus('aborted');

		$this->assertSame(
			[
				'outcome' => ['status' => 'aborted', 'droppedVersions' => 0, 'unrecoverable' => [], 'alreadyCompleted' => true],
				'calls' => [
					['migrations.findById', 'migration-1'],
					['LoggerInterface.info', 'Keepiq: completeMigration called on an already-terminated migration; ignoring', ['migrationId' => 'migration-1', 'status' => 'aborted']],
				],
			],
			$this->trace(fn (MigrationService $s) => $s->completeMigration('migration-1'))
		);
	}//end testCompleteOfTerminatedMigrationIsIgnored()

	/**
	 * Completing with an unresolvable owner logs twice and refuses.
	 *
	 * @return void
	 */
	public function testCompleteWithUnknownOwnerRefuses(): void {
		$this->ownerKnown = false;
		$trace = $this->trace(fn (MigrationService $s) => $s->completeMigration('migration-1'));

		$this->assertSame(MigrationIncompleteException::class, $trace['outcome'][0]);
		$this->assertSame(
			[
				['migrations.findById', 'migration-1'],
				['suites.findById', 'old-suite'],
				['LoggerInterface.error', 'Keepiq: cannot resolve migration owner — suite missing', ['suiteId' => 'old-suite']],
				['LoggerInterface.error', 'Keepiq: refusing to terminate a migration whose owner cannot be resolved', ['migrationId' => 'migration-1', 'oldSuiteId' => 'old-suite']],
			],
			$trace['calls']
		);
	}//end testCompleteWithUnknownOwnerRefuses()

	/**
	 * Aborting runs the restorative steps in this exact order.
	 *
	 * @return void
	 */
	public function testAbortCallsInOrder(): void {
		$trace = $this->trace(fn (MigrationService $s) => $s->abortMigration('migration-1', 'bob'));

		$this->assertTrue($trace['outcome']['aborted']);
		$this->assertSame('aborted', $trace['outcome']['status']);
		$calls = $trace['calls'];
		$this->assertSame(['migrations.findById', 'migration-1'], $calls[0]);
		$this->assertSame(['suites.findById', 'old-suite'], $calls[1]);
		$this->assertSame(['work.countCommitted', 'alice'], $calls[2]);
		$this->assertSame(['SuiteMigrationMapper.update', ['migration', 'migration-1', 'aborted', true]], $calls[3]);
		$this->assertSame(['suites.findById', 'new-suite'], $calls[4]);
		$this->assertSame(['EncryptionSuiteMapper.delete', ['suite', 'new-suite']], $calls[5]);
		$this->assertSame(['MigrationWorkService.clearFailureAccounting', ['migration', 'migration-1', 'aborted', true]], $calls[6]);
		$this->assertSame('dispatch', $calls[7][0]);
		$this->assertSame(['oldSuiteId' => 'old-suite', 'newSuiteId' => 'new-suite', 'migrationId' => 'migration-1'], array_intersect_key($calls[7][2], ['oldSuiteId' => 1, 'newSuiteId' => 1, 'migrationId' => 1]));
		$this->assertSame(
			['LoggerInterface.info', 'Keepiq: Compromise recovery aborted for migration migration-1; vault returned to the old suite', ['oldSuiteId' => 'old-suite']],
			$calls[8]
		);
		$this->assertSame(AuditEvent::class, $calls[9][1]);
		$this->assertSame('bob', $calls[9][2]['actorId']);
		$this->assertCount(10, $calls);
	}//end testAbortCallsInOrder()

	/**
	 * Without an actor the abort is audited as the owner.
	 *
	 * @return void
	 */
	public function testAbortWithoutActorIsAuditedAsTheOwner(): void {
		$trace = $this->trace(fn (MigrationService $s) => $s->abortMigration('migration-1'));

		$this->assertSame('alice', end($trace['calls'])[2]['actorId']);
	}//end testAbortWithoutActorIsAuditedAsTheOwner()

	/**
	 * An absent successor is logged and the abort still completes.
	 *
	 * @return void
	 */
	public function testAbortWithAbsentSuccessorWarns(): void {
		$this->successorExists = false;
		$trace = $this->trace(fn (MigrationService $s) => $s->abortMigration('migration-1'));

		$this->assertTrue($trace['outcome']['aborted']);
		$this->assertSame(
			['LoggerInterface.warning', 'Keepiq: successor suite already absent during abort', ['migrationId' => 'migration-1', 'newSuiteId' => 'new-suite']],
			$trace['calls'][5]
		);
		$this->assertSame('MigrationWorkService.clearFailureAccounting', $trace['calls'][6][0]);
	}//end testAbortWithAbsentSuccessorWarns()

	/**
	 * With a committed record the abort is refused before any write.
	 *
	 * @return void
	 */
	public function testAbortRefusedWithCommittedRecords(): void {
		$this->committed = 1;

		$this->assertSame(
			[
				'outcome' => [
					MigrationAbortRefusedException::class,
					'1 record(s) have already been re-encrypted to the new suite, so this migration can no longer be aborted '
					. 'without losing data. Resume it to finish, or complete it.',
				],
				'calls' => [
					['migrations.findById', 'migration-1'],
					['suites.findById', 'old-suite'],
					['work.countCommitted', 'alice'],
				],
			],
			$this->trace(fn (MigrationService $s) => $s->abortMigration('migration-1'))
		);
	}//end testAbortRefusedWithCommittedRecords()

	/**
	 * With an unresolvable owner nothing counts as committed.
	 *
	 * @return void
	 */
	public function testAbortWithUnknownOwnerSkipsTheCount(): void {
		$this->ownerKnown = false;
		$this->committed = 5;
		$trace = $this->trace(fn (MigrationService $s) => $s->abortMigration('migration-1'));

		$this->assertTrue($trace['outcome']['aborted']);
		$this->assertNotContains('work.countCommitted', array_column($trace['calls'], 0));
		$this->assertNull(end($trace['calls'])[2]['actorId']);
	}//end testAbortWithUnknownOwnerSkipsTheCount()

	/**
	 * Aborting an already terminated migration logs and answers the no-op.
	 *
	 * @return void
	 */
	public function testAbortOfTerminatedMigrationIsIgnored(): void {
		$this->migration->setStatus('completed');

		$this->assertSame(
			[
				'outcome' => ['status' => 'completed', 'aborted' => false, 'alreadyTerminated' => true],
				'calls' => [
					['migrations.findById', 'migration-1'],
					['LoggerInterface.info', 'Keepiq: abortMigration called on an already-terminated migration; ignoring', ['migrationId' => 'migration-1', 'status' => 'completed']],
				],
			],
			$this->trace(fn (MigrationService $s) => $s->abortMigration('migration-1'))
		);
	}//end testAbortOfTerminatedMigrationIsIgnored()
}//end class
