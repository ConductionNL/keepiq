<?php

/**
 * Unit tests for VersionMove.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Service\VersionMove;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A version move is a lock only when it moves the row forward, and the
 * versions it carries cannot be moved after it is made.
 *
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
 */
#[CoversClass(VersionMove::class)]
class VersionMoveTest extends TestCase {

	/**
	 * A move to the version it was read at is refused: two writers could
	 * both match it.
	 *
	 * @return void
	 */
	public function testAMoveToTheSameVersionIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		new VersionMove(base: new DateTime('2026-01-01T00:00:05+00:00'), savedAt: new DateTime('2026-01-01T00:00:05+00:00'));
	}//end testAMoveToTheSameVersionIsRefused()

	/**
	 * A move within the same second is refused: the version column counts
	 * whole seconds, so it would not move the row.
	 *
	 * @return void
	 */
	public function testAMoveWithinTheSameSecondIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		new VersionMove(base: new DateTime('2026-01-01T00:00:05.200+00:00'), savedAt: new DateTime('2026-01-01T00:00:05.800+00:00'));
	}//end testAMoveWithinTheSameSecondIsRefused()

	/**
	 * A move back is refused.
	 *
	 * @return void
	 */
	public function testAMoveBackIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		new VersionMove(base: new DateTime('2026-01-01T00:00:05+00:00'), savedAt: new DateTime('2026-01-01T00:00:04+00:00'));
	}//end testAMoveBackIsRefused()

	/**
	 * A move one second forward carries both versions.
	 *
	 * @return void
	 */
	public function testAMoveForwardCarriesBothVersions(): void {
		$move = new VersionMove(base: new DateTime('2026-01-01T00:00:05+00:00'), savedAt: new DateTime('2026-01-01T00:00:06+00:00'));

		$this->assertSame('2026-01-01T00:00:05+00:00', $move->base()->format('c'));
		$this->assertSame('2026-01-01T00:00:06+00:00', $move->savedAt()->format('c'));
	}//end testAMoveForwardCarriesBothVersions()

	/**
	 * Neither the moments passed in nor the ones handed out move the
	 * versions the move carries.
	 *
	 * @return void
	 */
	public function testTheVersionsCannotBeMovedAfterwards(): void {
		$base = new DateTime('2026-01-01T00:00:05+00:00');
		$savedAt = new DateTime('2026-01-01T00:00:06+00:00');
		$move = new VersionMove(base: $base, savedAt: $savedAt);

		$base->modify('+1 hour');
		$savedAt->modify('-1 hour');
		$move->base()->modify('+1 day');
		$move->savedAt()->modify('-1 day');

		$this->assertSame('2026-01-01T00:00:05+00:00', $move->base()->format('c'));
		$this->assertSame('2026-01-01T00:00:06+00:00', $move->savedAt()->format('c'));
	}//end testTheVersionsCannotBeMovedAfterwards()
}//end class
