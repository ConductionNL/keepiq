<?php

/**
 * OfflineEditGuard: an edit made on an offline copy is only written when the
 * stored secret has not changed since that copy was made.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Service\OfflineEditGuard;
use PHPUnit\Framework\TestCase;

/**
 * The offline edit guard.
 */
class OfflineEditGuardTest extends TestCase {
	/**
	 * A secret last updated at the given moment, or never.
	 *
	 * @param string|null $updatedAt The moment.
	 *
	 * @return Secret
	 */
	private function secret(?string $updatedAt): Secret {
		$secret = new Secret();
		if ($updatedAt !== null) {
			$secret->setUpdatedAt(new DateTime($updatedAt));
		}

		return $secret;
	}//end secret()

	/**
	 * An edit without a base moment is an ordinary edit and passes as is.
	 *
	 * @return void
	 */
	public function testAnEditWithoutABaseMomentPasses(): void {
		$guard = new OfflineEditGuard();
		$this->assertSame(expected: ['title' => 'Bank'], actual: $guard->checkedUpdate(secret: $this->secret(updatedAt: null), data: ['title' => 'Bank']));
		$this->assertSame(
			expected: ['title' => 'Bank'],
			actual: $guard->checkedUpdate(secret: $this->secret(updatedAt: null), data: ['title' => 'Bank', 'baseUpdatedAt' => ''])
		);
	}//end testAnEditWithoutABaseMomentPasses()

	/**
	 * An edit on an up-to-date copy passes, without its base moment.
	 *
	 * @return void
	 */
	public function testAnUpToDateCopyPasses(): void {
		$data = (new OfflineEditGuard())->checkedUpdate(
			secret: $this->secret(updatedAt: '2026-11-14T10:00:00+00:00'),
			data: ['title' => 'Bank', 'baseUpdatedAt' => '2026-11-14T11:00:00+01:00']
		);
		$this->assertSame(expected: ['title' => 'Bank'], actual: $data);
	}//end testAnUpToDateCopyPasses()

	/**
	 * An edit on a copy older or newer than the stored secret is refused with the current secret.
	 *
	 * @return void
	 */
	public function testAStaleCopyIsRefused(): void {
		$secret = $this->secret(updatedAt: '2026-11-14T10:00:05+00:00');
		foreach (['2026-11-14T10:00:00+00:00', '2026-11-14T10:00:06+00:00'] as $base) {
			try {
				(new OfflineEditGuard())->checkedUpdate(secret: $secret, data: ['baseUpdatedAt' => $base]);
				$this->fail(message: 'A stale copy was written: '.$base);
			} catch (StaleWriteException $e) {
				$this->assertSame(expected: $secret, actual: $e->getCurrent());
			}
		}
	}//end testAStaleCopyIsRefused()

	/**
	 * A secret that never recorded an update cannot be proven unchanged, so a based edit is refused.
	 *
	 * @return void
	 */
	public function testASecretWithoutAnUpdateMomentRefusesABasedEdit(): void {
		$this->expectException(exception: StaleWriteException::class);
		(new OfflineEditGuard())->assertUnchangedSince(secret: $this->secret(updatedAt: null), baseUpdatedAt: '2026-11-14T10:00:00+00:00');
	}//end testASecretWithoutAnUpdateMomentRefusesABasedEdit()

	/**
	 * A base moment that is not a date is a bad request, not a conflict.
	 *
	 * @return void
	 */
	public function testABaseThatIsNotADateIsInvalid(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'baseUpdatedAt must be a date');
		(new OfflineEditGuard())->assertUnchangedSince(secret: $this->secret(updatedAt: '2026-11-14T10:00:00+00:00'), baseUpdatedAt: 'yesterday-ish');
	}//end testABaseThatIsNotADateIsInvalid()
}//end class
