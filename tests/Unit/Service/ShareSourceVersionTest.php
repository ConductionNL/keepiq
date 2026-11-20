<?php

/**
 * Unit tests for ShareSourceVersion.
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
use OCA\Keepiq\Db\SecretVersionLock;
use OCA\Keepiq\Service\ShareSourceVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Reading, locking and comparing the version a share row names.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
 */
#[CoversClass(ShareSourceVersion::class)]
class ShareSourceVersionTest extends TestCase {
	/**
	 * The rows locked, in order.
	 *
	 * @var list<string>
	 */
	private array $locked = [];

	/**
	 * The check over a lock answering `$versions` (id => ISO moment); an id not listed is gone.
	 *
	 * @param array<string,string> $versions The versions
	 *
	 * @return ShareSourceVersion
	 */
	private function check(array $versions = []): ShareSourceVersion {
		$locks = $this->createMock(originalClassName: SecretVersionLock::class);
		$locks->method('lock')->willReturnCallback(
			function (string $id) use ($versions): ?DateTime {
				$this->locked[] = $id;
				if (isset($versions[$id]) === false) {
					return null;
				}

				return new DateTime($versions[$id]);
			}
		);

		return new ShareSourceVersion(locks: $locks);
	}//end check()

	/**
	 * A row names a version, none, or one that is not a moment.
	 *
	 * @return void
	 */
	public function testSent(): void {
		$check = $this->check();

		$this->assertSame(expected: '2026-10-01T10:00:00Z', actual: $check->sent(row: ['sourceUpdatedAt' => '2026-10-01T10:00:00Z']));
		$this->assertNull(actual: $check->sent(row: []));
		$this->assertSame(expected: '', actual: $check->sent(row: ['sourceUpdatedAt' => 'soon']));
		$this->assertSame(expected: '', actual: $check->sent(row: ['sourceUpdatedAt' => 42]));
	}//end testSent()

	/**
	 * The comparison is in whole seconds across zones, and a gone row is never current.
	 *
	 * @return void
	 */
	public function testIsCurrent(): void {
		$check = $this->check();
		$stored = new DateTime('2026-10-01T10:00:00+00:00');

		$this->assertTrue(condition: $check->isCurrent(sent: '2026-10-01T12:00:00+02:00', current: $stored));
		$this->assertFalse(condition: $check->isCurrent(sent: '2026-10-01T10:00:01+00:00', current: $stored));
		$this->assertFalse(condition: $check->isCurrent(sent: '2026-10-01T10:00:00+00:00', current: null));
	}//end testIsCurrent()

	/**
	 * A share from a copy locks the source first and answers the copy's version.
	 *
	 * @return void
	 */
	public function testLockedVersionOfACopyLocksTheSourceFirst(): void {
		$check = $this->check(versions: ['src' => '2026-10-01T10:00:09+00:00', 'copy' => '2026-10-01T10:00:00+00:00']);

		$version = $check->lockedVersion(sourceId: 'src', readId: 'copy');

		$this->assertSame(expected: ['src', 'copy'], actual: $this->locked);
		$this->assertSame(expected: '2026-10-01T10:00:00+00:00', actual: $version?->format(DATE_ATOM));
		$this->assertSame(
			expected: '2026-10-01T10:00:09+00:00',
			actual: $check->lockedVersion(sourceId: 'src', readId: 'src')?->format(DATE_ATOM)
		);
	}//end testLockedVersionOfACopyLocksTheSourceFirst()

	/**
	 * Only the allowed sources a row names a version of are locked, once each and in id order.
	 *
	 * @return void
	 */
	public function testLockNamedLocksAllowedSourcesInIdOrder(): void {
		$check = $this->check(versions: ['a' => '2026-10-01T10:00:00+00:00', 'b' => '2026-10-01T10:00:00+00:00']);

		$versions = $check->lockNamed(
			rows: [
				['sourceSecretId' => 'b', 'sourceUpdatedAt' => '2026-10-01T10:00:00Z'],
				['sourceSecretId' => 'a', 'sourceUpdatedAt' => '2026-10-01T10:00:00Z'],
				['sourceSecretId' => 'b', 'sourceUpdatedAt' => '2026-10-01T10:00:00Z'],
				['sourceSecretId' => 'c'],
				['sourceSecretId' => 'x', 'sourceUpdatedAt' => '2026-10-01T10:00:00Z'],
			],
			allowedIds: ['a' => true, 'b' => true, 'c' => true]
		);

		$this->assertSame(expected: ['a', 'b'], actual: $this->locked);
		$this->assertSame(expected: ['a', 'b'], actual: array_keys($versions));
	}//end testLockNamedLocksAllowedSourcesInIdOrder()

	/**
	 * A row is stale with the current version, invalid, or may go on.
	 *
	 * @return void
	 */
	public function testRefusal(): void {
		$check = $this->check();
		$versions = ['a' => new DateTime('2026-10-01T10:00:05+00:00'), 'gone' => null];

		$this->assertSame(
			expected: ['reason' => 'stale_source', 'extra' => ['sourceUpdatedAt' => '2026-10-01T10:00:05+00:00']],
			actual: $check->refusal(row: ['sourceSecretId' => 'a', 'sourceUpdatedAt' => '2026-10-01T10:00:00Z'], versions: $versions)
		);
		$this->assertSame(
			expected: ['reason' => 'stale_source', 'extra' => ['sourceUpdatedAt' => null]],
			actual: $check->refusal(row: ['sourceSecretId' => 'gone', 'sourceUpdatedAt' => '2026-10-01T10:00:00Z'], versions: $versions)
		);
		$this->assertSame(
			expected: ['reason' => 'invalid', 'extra' => []],
			actual: $check->refusal(row: ['sourceSecretId' => 'a', 'sourceUpdatedAt' => 'soon'], versions: $versions)
		);
		$this->assertNull(actual: $check->refusal(row: ['sourceSecretId' => 'a', 'sourceUpdatedAt' => '2026-10-01T10:00:05Z'], versions: $versions));
		$this->assertNull(actual: $check->refusal(row: ['sourceSecretId' => 'a'], versions: $versions));
		$this->assertNull(actual: $check->refusal(row: ['sourceSecretId' => 'x', 'sourceUpdatedAt' => '2026-10-01T10:00:00Z'], versions: $versions));
	}//end testRefusal()
}//end class
