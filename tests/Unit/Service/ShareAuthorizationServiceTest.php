<?php

/**
 * Unit tests for ShareAuthorizationService's fallbacks: without a share-target
 * mapper a re-share is refused and the named secret is its own share source,
 * and a copy that resolves to more than one share row is refused as not found.
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

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;

/**
 * The share-target fallbacks of ShareAuthorizationService.
 *
 * @spec openspec/specs/link-sharing/spec.md#requirement-who-may-create-a-link-share
 */
class ShareAuthorizationServiceTest extends TestCase {

	/**
	 * A secret held by bob, as a copy or a source.
	 *
	 * @return Secret
	 */
	private function secret(): Secret {
		$secret = new Secret();
		$secret->setId('s1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('bob');

		return $secret;
	}//end secret()

	/**
	 * Build the service, with or without a share-target mapper.
	 *
	 * @param ShareTargetMapper|null $targets The share-target mapper, or null for none
	 *
	 * @return ShareAuthorizationService
	 */
	private function service(?ShareTargetMapper $targets): ShareAuthorizationService {
		$secrets = $this->createMock(originalClassName: SecretMapper::class);
		$secrets->method('findById')->willReturn($this->secret());

		return new ShareAuthorizationService(
			secretMapper: $secrets,
			delegationMapper: $this->createMock(originalClassName: SecretDelegationMapper::class),
			shareTargetMapper: $targets,
		);
	}//end service()

	/**
	 * Without a share-target mapper no copy can be judged, so a re-share is refused.
	 *
	 * @return void
	 */
	public function testReshareIsRefusedWithoutAShareTargetMapper(): void {
		$this->expectException(exception: DoesNotExistException::class);

		$this->service(targets: null)->assertMayReshare(secretId: 's1', userId: 'bob');
	}//end testReshareIsRefusedWithoutAShareTargetMapper()

	/**
	 * Without a share-target mapper the named secret is its own share source.
	 *
	 * @return void
	 */
	public function testTheNamedSecretIsTheSourceWithoutAShareTargetMapper(): void {
		$secret = $this->secret();

		$this->assertSame(expected: $secret, actual: $this->service(targets: null)->resolveShareSource(secret: $secret, userId: 'bob'));
	}//end testTheNamedSecretIsTheSourceWithoutAShareTargetMapper()

	/**
	 * A copy that resolves to two share rows has no single grant to judge: refused as not found.
	 *
	 * @return void
	 */
	public function testACopyWithTwoShareRowsIsRefusedAsNotFound(): void {
		$targets = $this->createMock(originalClassName: ShareTargetMapper::class);
		$targets->method('findByRecipientSecret')->willThrowException(new MultipleObjectsReturnedException('two rows'));

		$this->expectException(exception: DoesNotExistException::class);
		$this->expectExceptionMessage(message: 'Secret not found');

		$this->service(targets: $targets)->assertMayReshare(secretId: 's1', userId: 'bob');
	}//end testACopyWithTwoShareRowsIsRefusedAsNotFound()
}//end class
