<?php

/**
 * Doriath - SecretRequestPolicy access-gate tests
 *
 * `requireOpenByToken()` is the enforcement point for the public fill surface:
 * every recipient request passes through it, and its refusal codes are what the
 * fill page renders. It had no direct tests, which is how the structure this
 * change fixes went unnoticed — expiry was checked INSIDE the pending branch, so
 * it was a property of one status rather than an independent gate.
 *
 * These tests pin two things that are easy to break together:
 *   - a lapsed request is refused on `expires_at` alone, before any sweeper runs
 *   - the terminal `expired` status has its own arm and does not fall through to
 *     the unknown-state 500
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
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

namespace OCA\Doriath\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Db\SecretRequestMapper;
use OCA\Doriath\Service\SecretRequestPolicy;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the public fill gate.
 */
class SecretRequestPolicyTest extends TestCase {
	/**
	 * The mapper mock.
	 *
	 * @var SecretRequestMapper&MockObject
	 */
	private SecretRequestMapper&MockObject $mapper;

	/**
	 * The policy under test.
	 *
	 * @var SecretRequestPolicy
	 */
	private SecretRequestPolicy $policy;

	/**
	 * Set up the policy with a mocked mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretRequestMapper::class);
		$this->policy = new SecretRequestPolicy(mapper: $this->mapper);
	}//end setUp()

	/**
	 * Build a request in a given status.
	 *
	 * @param string $status The status
	 * @param DateTime|null $expiresAt Optional expiry
	 *
	 * @return SecretRequest
	 */
	private function make(string $status, ?DateTime $expiresAt = null): SecretRequest {
		$entity = new SecretRequest();
		$entity->setId('req-1');
		$entity->setSecretId('sec-1');
		$entity->setToken('tok-1');
		$entity->setStatus($status);
		$entity->setExpiresAt($expiresAt);

		return $entity;
	}//end make()

	/**
	 * A pending, unexpired request passes the gate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	public function testPendingUnexpiredRequestIsAllowed(): void {
		$this->mapper->method('findByToken')->willReturn($this->make(SecretRequest::STATUS_PENDING));

		$this->assertSame('req-1', $this->policy->requireOpenByToken(token: 'tok-1')->getId());
	}//end testPendingUnexpiredRequestIsAllowed()

	/**
	 * A request with no expiry is never treated as expired.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/secret-request-expiry-lifecycle/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testARequestWithoutAnExpiryIsAllowed(): void {
		$this->mapper->method('findByToken')
			->willReturn($this->make(SecretRequest::STATUS_PENDING, null));

		$this->assertSame('req-1', $this->policy->requireOpenByToken(token: 'tok-1')->getId());
	}//end testARequestWithoutAnExpiryIsAllowed()

	/**
	 * A lapsed request is refused on `expires_at` alone, before any sweeper runs.
	 *
	 * The job runs hourly, so between a request lapsing and the next run its
	 * stored status still reads `pending`. This case was already refused before
	 * the check was hoisted -- the pending branch checked expiry itself -- so this
	 * test pins existing behaviour rather than proving the hoist. What the hoist
	 * buys is asserted by testExpiryTakesPrecedenceOverATemporaryStatus().
	 *
	 * @return void
	 *
	 * @spec openspec/changes/secret-request-expiry-lifecycle/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testLapsedButUnsweptRequestIsRefused(): void {
		$this->mapper->method('findByToken')->willReturn(
			$this->make(SecretRequest::STATUS_PENDING, new DateTime('-1 minute'))
		);

		try {
			$this->policy->requireOpenByToken(token: 'tok-1');
			$this->fail('a lapsed request must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(408, $e->getCode());
			$this->assertStringContainsString('expired', strtolower($e->getMessage()));
		}
	}//end testLapsedButUnsweptRequestIsRefused()

	/**
	 * The terminal `expired` status reports expiry, not an unknown state.
	 *
	 * Without its own arm this status falls to `default`, which answers 500
	 * "Request is in an unknown state" — a server error for a request that simply
	 * ran out, shown to an external recipient.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/secret-request-expiry-lifecycle/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testExpiredStatusReportsExpiryRatherThanAServerError(): void {
		$this->mapper->method('findByToken')->willReturn($this->make(SecretRequest::STATUS_EXPIRED));

		try {
			$this->policy->requireOpenByToken(token: 'tok-1');
			$this->fail('an expired request must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertNotSame(500, $e->getCode(), 'must not be reported as an unknown state');
			$this->assertSame(410, $e->getCode());
			$this->assertStringContainsString('expired', strtolower($e->getMessage()));
		}
	}//end testExpiredStatusReportsExpiryRatherThanAServerError()

	/**
	 * A lapsed request reports EXPIRY even when another status would also refuse.
	 *
	 * This is what hoisting the check above the switch actually buys, and it is
	 * worth being precise: a lapsed PENDING request was already refused before the
	 * hoist, because the pending branch checked expiry itself. What changes is
	 * precedence for every other status — a locked request whose expiry has passed
	 * now says "expired" rather than "temporarily unavailable".
	 *
	 * That is the more honest answer for the recipient: locked is temporary and
	 * invites them to try later, while an expired request can never be filled. It
	 * also means a status added in future cannot silently bypass expiry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/secret-request-expiry-lifecycle/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testExpiryTakesPrecedenceOverATemporaryStatus(): void {
		$this->mapper->method('findByToken')->willReturn(
			$this->make(SecretRequest::STATUS_LOCKED, new DateTime('-1 day'))
		);

		try {
			$this->policy->requireOpenByToken(token: 'tok-1');
			$this->fail('a lapsed request must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(408, $e->getCode(), 'expiry must win over locked');
			$this->assertStringContainsString('expired', strtolower($e->getMessage()));
		}
	}//end testExpiryTakesPrecedenceOverATemporaryStatus()

	/**
	 * Every other status keeps the code it returned before.
	 *
	 * Hoisting the expiry check moved code that all four branches sit under, so
	 * this guards against having changed one of them by accident.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	public function testOtherStatusesKeepTheirCodes(): void {
		$expected = [
			SecretRequest::STATUS_LOCKED => 423,
			SecretRequest::STATUS_FULFILLED => 410,
			SecretRequest::STATUS_DECLINED => 410,
		];

		foreach ($expected as $status => $code) {
			$mapper = $this->createMock(SecretRequestMapper::class);
			$mapper->method('findByToken')->willReturn($this->make($status));
			$policy = new SecretRequestPolicy(mapper: $mapper);

			try {
				$policy->requireOpenByToken(token: 'tok-1');
				$this->fail($status . ' must be refused');
			} catch (InvalidArgumentException $e) {
				$this->assertSame($code, $e->getCode(), $status);
			}
		}
	}//end testOtherStatusesKeepTheirCodes()

	/**
	 * An unknown token is a 404, and an empty one a 400.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	public function testUnknownAndEmptyTokens(): void {
		$this->mapper->method('findByToken')->willThrowException(new DoesNotExistException('nope'));

		try {
			$this->policy->requireOpenByToken(token: 'tok-1');
			$this->fail('unknown token must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(404, $e->getCode());
		}

		try {
			$this->policy->requireOpenByToken(token: '');
			$this->fail('empty token must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(400, $e->getCode());
		}
	}//end testUnknownAndEmptyTokens()
}//end class
