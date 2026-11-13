<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden outcomes of recording a use of a use-only copy: who may record one,
 * and what the record names.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/use-only-shares/spec.md#requirement-each-use-is-recorded
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareRowVerdict;
use OCA\Keepiq\Service\UseOnlyUseRecorder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Use-only use recording outcomes.
 */
class UseOnlyUseRecorderTest extends TestCase {

	/**
	 * Secrets by id.
	 *
	 * @var array<string,Secret>
	 */
	private array $secrets = [];

	/**
	 * The uses marked.
	 *
	 * @var list<array{0:string,1:string}>
	 */
	private array $marked = [];

	/**
	 * The audit events.
	 *
	 * @var list<AuditEvent>
	 */
	private array $events = [];

	/**
	 * The verdict the judge gives.
	 *
	 * @var ShareRowVerdict
	 */
	private ShareRowVerdict $verdict;

	/**
	 * A recorder over the in-memory secrets.
	 *
	 * @return UseOnlyUseRecorder
	 */
	private function recorder(): UseOnlyUseRecorder {
		/** @var SecretMapper&MockObject $mapper */
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->method('findById')->willReturnCallback(
			fn (string $id): Secret => $this->secrets[$id] ?? throw new DoesNotExistException('gone')
		);
		$mapper->method('markUsed')->willReturnCallback(function (string $copyId, string $userId): int {
			$this->marked[] = [$copyId, $userId];
			return 1;
		});
		$judge = $this->createMock(ShareRowJudge::class);
		$judge->method('forCopy')->willReturnCallback(fn (): ShareRowVerdict => $this->verdict);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$this->events[] = $event;
		});
		return new UseOnlyUseRecorder(secretMapper: $mapper, rowJudge: $judge, eventDispatcher: $dispatcher);
	}//end recorder()

	/**
	 * Bob's use-only copy V of alice's S.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$copy = new Secret();
		$copy->setId('V');
		$copy->setName('Copy name');
		$copy->setOwnerType('user');
		$copy->setOwnerId('bob');
		$copy->setUseOnly(true);
		$source = new Secret();
		$source->setId('S');
		$source->setName('Source name');
		$this->secrets = ['V' => $copy, 'S' => $source];
		$row = new ShareTarget();
		$row->setSourceSecretId('S');
		$this->verdict = new ShareRowVerdict(state: ShareRowVerdict::TRUSTED, row: $row);
	}//end setUp()

	/**
	 * The holder's use is marked on the copy and audited on the source, by name.
	 *
	 * @return void
	 */
	public function testTheHoldersUseIsMarkedAndAuditedOnTheSource(): void {
		$this->recorder()->recordUse(copyId: 'V', userId: 'bob');

		$this->assertSame([['V', 'bob']], $this->marked);
		$this->assertCount(1, $this->events);
		$event = $this->events[0];
		$this->assertSame(
			[AuditEventTypes::SECRET_USED, 'bob', 'secret', 'S', 'Source name', ['copyId' => 'V']],
			[$event->getEventType(), $event->getActorId(), $event->getObjectType(), $event->getObjectId(), $event->getObjectName(), $event->getMetadata()]
		);
	}//end testTheHoldersUseIsMarkedAndAuditedOnTheSource()

	/**
	 * Without its source the use is audited under the copy's own name.
	 *
	 * @return void
	 */
	public function testAUseWhoseSourceIsGoneCarriesTheCopysName(): void {
		unset($this->secrets['S']);
		$this->recorder()->recordUse(copyId: 'V', userId: 'bob');

		$this->assertSame(['S', 'Copy name'], [$this->events[0]->getObjectId(), $this->events[0]->getObjectName()]);
	}//end testAUseWhoseSourceIsGoneCarriesTheCopysName()

	/**
	 * Every refusal answers as an unknown id and records nothing.
	 *
	 * @return void
	 */
	public function testEveryRefusalAnswersAsAnUnknownIdAndRecordsNothing(): void {
		$cases = [
			'unknown id' => static fn (Secret $copy): string => 'nope',
			'another user' => static fn (Secret $copy): string => 'V',
			'an org copy' => static function (Secret $copy): string {
				$copy->setOwnerType('organisation');
				return 'V';
			},
			'not use-only' => static function (Secret $copy): string {
				$copy->setUseOnly(false);
				return 'V';
			},
			'access ended' => static function (Secret $copy): string {
				$copy->setAccessExpiresAt(new DateTime('-1 minute'));
				return 'V';
			},
		];
		foreach ($cases as $name => $arrange) {
			$this->setUp();
			$copyId = $arrange($this->secrets['V']);
			$userId = $name === 'another user' ? 'mallory' : 'bob';
			try {
				$this->recorder()->recordUse(copyId: $copyId, userId: $userId);
				$this->fail($name . ' was recorded');
			} catch (NotFoundException $exception) {
				$this->assertSame('Secret not found', $exception->getMessage(), $name);
			}
		}

		$this->assertSame([], $this->marked);
		$this->assertSame([], $this->events);
	}//end testEveryRefusalAnswersAsAnUnknownIdAndRecordsNothing()

	/**
	 * A copy whose access ends later is still recorded; one without a share row is not.
	 *
	 * @return void
	 */
	public function testALiveEndDateIsRecordedAndAnUnnamedCopyIsNot(): void {
		$this->secrets['V']->setAccessExpiresAt(new DateTime('+1 day'));
		$this->recorder()->recordUse(copyId: 'V', userId: 'bob');
		$this->assertCount(1, $this->marked);

		$this->verdict = new ShareRowVerdict(state: ShareRowVerdict::NONE);
		$this->expectException(NotFoundException::class);
		$this->recorder()->recordUse(copyId: 'V', userId: 'bob');
	}//end testALiveEndDateIsRecordedAndAnUnnamedCopyIsNot()
}//end class
