<?php

/**
 * SecretUpdateRun and FanOutRun: the state one secret update carries, and
 * which team-folder shares a fan-out covers and the share target it writes.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Service\FanOutRun;
use OCA\Keepiq\Service\FanOutShareRow;
use OCA\Keepiq\Service\SecretUpdateRun;
use PHPUnit\Framework\TestCase;

/**
 * The update and fan-out runs.
 */
class UpdateAndFanOutRunsTest extends TestCase {
	/**
	 * A secret last updated at a fixed moment.
	 *
	 * @return Secret
	 */
	private function secret(): Secret {
		$secret = new Secret();
		$secret->setId('s1');
		$secret->setName('Bank');
		$secret->setUpdatedAt(new DateTime('2026-11-14T10:00:00+00:00'));
		return $secret;
	}//end secret()

	/**
	 * The run keeps a copy of the secret as it was, untouched by later changes.
	 *
	 * @return void
	 */
	public function testTheRunKeepsTheSecretAsItWas(): void {
		$secret = $this->secret();
		$run    = new SecretUpdateRun(secret: $secret, data: ['name' => 'Bank 2'], userId: 'anne', savedAt: null, restoring: false);
		$secret->setName('Bank 2');
		$this->assertSame(expected: 'Bank', actual: $run->preUpdate->getName());
		$this->assertSame(expected: 'Bank 2', actual: $run->secret->getName());
		$this->assertNotSame(expected: $run->secret, actual: $run->preUpdate);
	}//end testTheRunKeepsTheSecretAsItWas()

	/**
	 * Without a save moment the stored update moment is the base; with one there is none.
	 *
	 * @return void
	 */
	public function testTheBaseIsTheStoredMomentUnlessASaveMomentIsGiven(): void {
		$online = new SecretUpdateRun(secret: $this->secret(), data: [], userId: 'anne', savedAt: null, restoring: false);
		$this->assertSame(expected: '2026-11-14T10:00:00+00:00', actual: $online->base?->format('c'));
		$offline = new SecretUpdateRun(secret: $this->secret(), data: [], userId: 'anne', savedAt: new DateTime('2026-11-13'), restoring: true);
		$this->assertNull(actual: $offline->base);
		$this->assertTrue(condition: $offline->restoring);
	}//end testTheBaseIsTheStoredMomentUnlessASaveMomentIsGiven()

	/**
	 * A field is sent when its key is present, even with a null value.
	 *
	 * @return void
	 */
	public function testAFieldIsSentWhenItsKeyIsPresent(): void {
		$run = new SecretUpdateRun(secret: $this->secret(), data: ['totp' => null, 'name' => 'x'], userId: 'anne', savedAt: null, restoring: false);
		$this->assertTrue(condition: $run->sends(field: 'totp'));
		$this->assertTrue(condition: $run->sends(field: 'name'));
		$this->assertFalse(condition: $run->sends(field: 'passkeys'));
	}//end testAFieldIsSentWhenItsKeyIsPresent()

	/**
	 * A fan-out run over a team folder owned by anne, holding secrets s1 and s2.
	 *
	 * @return FanOutRun
	 */
	private function fanOut(): FanOutRun {
		$folder = new TeamFolder();
		$folder->setId('tf-1');
		$folder->setOwnerId('anne');
		return new FanOutRun(teamFolder: $folder, subtreeSecretIds: ['s1' => true, 's2' => true], userId: 'anne');
	}//end fanOut()

	/**
	 * A share is covered when its secret is in the folder and its target is not the owner.
	 *
	 * @return void
	 */
	public function testAShareIsCoveredInsideTheFolderForOthers(): void {
		$run = $this->fanOut();
		$share = static fn (string $secret, string $target): FanOutShareRow => new FanOutShareRow(
			row: ['sourceSecretId' => $secret, 'targetUserId' => $target, 'encryptedKey' => 'k']
		);
		$this->assertTrue(condition: $run->covers(share: $share('s1', 'bob')));
		$this->assertFalse(condition: $run->covers(share: $share('s9', 'bob')));
		$this->assertFalse(condition: $run->covers(share: $share('s2', 'anne')));
	}//end testAShareIsCoveredInsideTheFolderForOthers()

	/**
	 * The share target ties the copy to its source, recipient and team folder.
	 *
	 * @return void
	 */
	public function testTheShareTargetTiesTheCopyToItsFolder(): void {
		$target = $this->fanOut()->shareTarget(
			share: new FanOutShareRow(row: ['sourceSecretId' => 's1', 'targetUserId' => 'bob', 'encryptedKey' => 'k']),
			copyId: 'copy-1'
		);
		$this->assertMatchesRegularExpression(pattern: '/^[0-9a-f-]{36}$/', string: (string)$target->getId());
		$this->assertSame(
			expected: ['s1', 'bob', 'copy-1', 'tf-1', 'anne'],
			actual: [$target->getSourceSecretId(), $target->getTargetUserId(), $target->getSecretId(), $target->getTeamFolderId(), $target->getCreatedBy()]
		);
		$this->assertLessThanOrEqual(expected: time(), actual: $target->getCreatedAt()->getTimestamp());
	}//end testTheShareTargetTiesTheCopyToItsFolder()
}//end class
