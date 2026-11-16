<?php

/**
 * Keepiq - KnownShareRecipientExemption tests
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

use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\KnownShareRecipientExemption;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Sharing again with a known recipient needs no fresh vault-key proof.
 */
#[CoversClass(KnownShareRecipientExemption::class)]
class KnownShareRecipientExemptionTest extends TestCase {
	/**
	 * A request naming the given target.
	 *
	 * @param string $target The targetUserId parameter.
	 *
	 * @return IRequest
	 */
	private function requestFor(string $target): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('targetUserId', '')->willReturn($target);
		return $request;
	}//end requestFor()

	/**
	 * A recipient the user already shared with directly is exempt.
	 *
	 * @return void
	 */
	public function testAKnownRecipientIsExempt(): void {
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->expects($this->once())->method('hasDirectShareBetween')->with('alice', 'bob')->willReturn(true);

		$this->assertTrue((new KnownShareRecipientExemption(shareTargets: $targets))->exempts(request: $this->requestFor(target: 'bob'), userId: 'alice'));
	}//end testAKnownRecipientIsExempt()

	/**
	 * A recipient without an earlier direct share is not exempt.
	 *
	 * @return void
	 */
	public function testANewRecipientIsNotExempt(): void {
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('hasDirectShareBetween')->willReturn(false);

		$this->assertFalse((new KnownShareRecipientExemption(shareTargets: $targets))->exempts(request: $this->requestFor(target: 'carol'), userId: 'alice'));
	}//end testANewRecipientIsNotExempt()

	/**
	 * No target, or the user as target, is not exempt and the mapper is not asked.
	 *
	 * @return void
	 */
	public function testNoTargetOrSelfIsNotExempt(): void {
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->expects($this->never())->method('hasDirectShareBetween');
		$exemption = new KnownShareRecipientExemption(shareTargets: $targets);

		$this->assertFalse($exemption->exempts(request: $this->requestFor(target: ''), userId: 'alice'));
		$this->assertFalse($exemption->exempts(request: $this->requestFor(target: 'alice'), userId: 'alice'));
	}//end testNoTargetOrSelfIsNotExempt()

	/**
	 * A failing lookup fails closed: no exemption.
	 *
	 * @return void
	 */
	public function testALookupFailureIsNotExempt(): void {
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('hasDirectShareBetween')->willThrowException(new RuntimeException('db down'));

		$this->assertFalse((new KnownShareRecipientExemption(shareTargets: $targets))->exempts(request: $this->requestFor(target: 'bob'), userId: 'alice'));
	}//end testALookupFailureIsNotExempt()
}//end class
