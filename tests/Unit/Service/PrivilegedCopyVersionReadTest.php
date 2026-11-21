<?php

/**
 * A stored version of a recipient's privileged copy is gated like its head.
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

use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\PrivilegedAccessRequiredException;
use OCA\Keepiq\Service\PrivilegedAccess\PrivilegedCopyGate;
use OCA\Keepiq\Service\SecretVersionAccessGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
#[CoversClass(SecretVersionAccessGuard::class)]
#[UsesClass(Secret::class)]
#[UsesClass(SecretVersion::class)]
#[UsesClass(ForbiddenException::class)]
#[UsesClass(PrivilegedAccessRequiredException::class)]
class PrivilegedCopyVersionReadTest extends TestCase {

	/**
	 * Bob's copy of a privileged login.
	 *
	 * @var Secret
	 */
	private Secret $copy;

	/**
	 * A stored version of that copy.
	 *
	 * @var SecretVersion
	 */
	private SecretVersion $version;

	/**
	 * The secrets.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper $secrets;

	/**
	 * The versions.
	 *
	 * @var SecretVersionMapper&MockObject
	 */
	private SecretVersionMapper $versions;

	/**
	 * The privileged copy gate.
	 *
	 * @var PrivilegedCopyGate&MockObject
	 */
	private PrivilegedCopyGate $gate;

	/**
	 * Bob owns a copy with one stored version.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->copy = new Secret();
		$this->copy->setId('copy');
		$this->copy->setOwnerType('user');
		$this->copy->setOwnerId('bob');
		$this->copy->setUseOnly(false);

		$this->version = new SecretVersion();
		$this->version->setSecretId('copy');

		$this->secrets = $this->createMock(SecretMapper::class);
		$this->secrets->method('findById')->with('copy')->willReturn($this->copy);
		$this->versions = $this->createMock(SecretVersionMapper::class);
		$this->versions->method('findById')->with('v-1')->willReturn($this->version);
		$this->gate = $this->createMock(PrivilegedCopyGate::class);
	}

	/**
	 * The guard under test, with or without the gate.
	 *
	 * @param bool $withGate Whether the privileged copy gate is wired
	 *
	 * @return SecretVersionAccessGuard
	 */
	private function guard(bool $withGate = true): SecretVersionAccessGuard {
		return new SecretVersionAccessGuard(
			mapper: $this->versions,
			secretMapper: $this->secrets,
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			privilegedGate: $withGate === true ? $this->gate : null,
		);
	}

	/**
	 * Outside an approved window the version's ciphertext is refused, with the
	 * code the client reads to offer an access request.
	 *
	 * @return void
	 */
	public function testAVersionOutsideTheWindowIsRefused(): void {
		$this->gate->expects($this->once())
			->method('assertServable')
			->with($this->copy, 'bob', 'web')
			->willThrowException(new PrivilegedAccessRequiredException(message: PrivilegedCopyGate::REASON));

		try {
			$this->guard()->requireReadableVersion('v-1', 'bob');
			$this->fail('The version of a privileged copy outside its window was served');
		} catch (PrivilegedAccessRequiredException $exception) {
			$this->assertSame(PrivilegedCopyGate::REASON, $exception->getMessage());
			$this->assertSame(PrivilegedAccessRequiredException::CODE, $exception->policyCode());
		}
	}

	/**
	 * Inside an approved window the version is served.
	 *
	 * @return void
	 */
	public function testAVersionInsideTheWindowIsServed(): void {
		$this->gate->expects($this->once())
			->method('assertServable')
			->with($this->copy, 'bob', 'web');

		$this->assertSame($this->version, $this->guard()->requireReadableVersion('v-1', 'bob'));
	}

	/**
	 * A use-only copy is refused before the gate is asked, so no use is audited.
	 *
	 * @return void
	 */
	public function testAUseOnlyCopyIsRefusedBeforeTheGate(): void {
		$this->copy->setUseOnly(true);
		$this->gate->expects($this->never())->method('assertServable');

		$this->expectExceptionMessage('Versions of a use-only copy are not available');
		$this->guard()->requireReadableVersion('v-1', 'bob');
	}

	/**
	 * Without the gate wired the version read behaves as before.
	 *
	 * @return void
	 */
	public function testWithoutTheGateTheVersionIsServed(): void {
		$this->assertSame($this->version, $this->guard(withGate: false)->requireReadableVersion('v-1', 'bob'));
	}
}//end class
