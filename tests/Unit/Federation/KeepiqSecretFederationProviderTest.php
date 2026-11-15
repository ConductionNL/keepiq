<?php

/**
 * Unit tests for KeepiqSecretFederationProvider.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Federation
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

namespace OCA\Keepiq\Tests\Unit\Federation;

use OCA\Keepiq\Federation\KeepiqSecretFederationProvider;
use OCA\Keepiq\Service\FederatedDeclineReceiver;
use OCA\Keepiq\Service\FederatedRemoteChangeService;
use OCA\Keepiq\Service\FederatedShareReceiver;
use OCP\Federation\ICloudFederationShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for KeepiqSecretFederationProvider: which service each federation call
 * reaches, and what happens when no decline receiver is wired.
 *
 * @covers \OCA\Keepiq\Federation\KeepiqSecretFederationProvider
 */
class KeepiqSecretFederationProviderTest extends TestCase {

	private FederatedShareReceiver&MockObject $receiver;
	private FederatedRemoteChangeService&MockObject $remoteChanges;
	private FederatedDeclineReceiver&MockObject $declines;

	protected function setUp(): void {
		$this->receiver      = $this->createMock(FederatedShareReceiver::class);
		$this->remoteChanges = $this->createMock(FederatedRemoteChangeService::class);
		$this->declines      = $this->createMock(FederatedDeclineReceiver::class);
	}//end setUp()

	/**
	 * The provider, with or without a decline receiver.
	 *
	 * @param bool $withDeclines Whether a decline receiver is wired.
	 *
	 * @return KeepiqSecretFederationProvider
	 */
	private function provider(bool $withDeclines = true): KeepiqSecretFederationProvider {
		return new KeepiqSecretFederationProvider(
			$this->receiver,
			$this->remoteChanges,
			$withDeclines === true ? $this->declines : null
		);
	}//end provider()

	/**
	 * It serves the keepiq-secret resource type to users only.
	 *
	 * @return void
	 */
	public function testItServesKeepiqSecretsToUsers(): void {
		$provider = $this->provider();

		$this->assertSame('keepiq-secret', $provider->getShareType());
		$this->assertSame(['user'], $provider->getSupportedShareTypes());
	}//end testItServesKeepiqSecretsToUsers()

	/**
	 * A received share goes to the share receiver and its id comes back.
	 *
	 * @return void
	 */
	public function testAReceivedShareGoesToTheReceiver(): void {
		$share = $this->createMock(ICloudFederationShare::class);
		$this->receiver->expects($this->once())->method('receive')->with($share)->willReturn('local-42');

		$this->assertSame('local-42', $this->provider()->shareReceived($share));
	}//end testAReceivedShareGoesToTheReceiver()

	/**
	 * A decline is handled by the decline receiver, not as a remote change.
	 *
	 * @return void
	 */
	public function testADeclineGoesToTheDeclineReceiver(): void {
		$this->declines->expects($this->once())->method('handle')->with('p-1', ['a' => 1])->willReturn(['declined' => true]);
		$this->declines->expects($this->never())->method('resume');
		$this->remoteChanges->expects($this->never())->method('handle');

		$this->assertSame(['declined' => true], $this->provider()->notificationReceived('SHARE_DECLINED', 'p-1', ['a' => 1]));
	}//end testADeclineGoesToTheDeclineReceiver()

	/**
	 * A restored copy resumes the share through the decline receiver.
	 *
	 * @return void
	 */
	public function testAnAcceptanceResumesTheShare(): void {
		$this->declines->expects($this->once())->method('resume')->with('p-1', ['b' => 2])->willReturn(['resumed' => true]);
		$this->declines->expects($this->never())->method('handle');
		$this->remoteChanges->expects($this->never())->method('handle');

		$this->assertSame(['resumed' => true], $this->provider()->notificationReceived('SHARE_ACCEPTED', 'p-1', ['b' => 2]));
	}//end testAnAcceptanceResumesTheShare()

	/**
	 * Every other notification is a remote change.
	 *
	 * @return void
	 */
	public function testOtherNotificationsAreRemoteChanges(): void {
		$this->remoteChanges->expects($this->once())
			->method('handle')
			->with('SECRET_UPDATED', 'p-2', ['c' => 3])
			->willReturn(['updated' => true]);
		$this->declines->expects($this->never())->method('handle');

		$this->assertSame(['updated' => true], $this->provider()->notificationReceived('SECRET_UPDATED', 'p-2', ['c' => 3]));
	}//end testOtherNotificationsAreRemoteChanges()

	/**
	 * Without a decline receiver, decline and acceptance fall through to the remote-change handler.
	 *
	 * @return void
	 */
	public function testWithoutADeclineReceiverDeclinesFallThroughToRemoteChanges(): void {
		$seen = [];
		$this->remoteChanges->expects($this->exactly(2))->method('handle')->willReturnCallback(
			static function (string $type) use (&$seen): array {
				$seen[] = $type;
				return ['handled' => $type];
			}
		);

		$provider = $this->provider(withDeclines: false);

		$this->assertSame(['handled' => 'SHARE_DECLINED'], $provider->notificationReceived('SHARE_DECLINED', 'p-1', []));
		$this->assertSame(['handled' => 'SHARE_ACCEPTED'], $provider->notificationReceived('SHARE_ACCEPTED', 'p-1', []));
		$this->assertSame(['SHARE_DECLINED', 'SHARE_ACCEPTED'], $seen);
	}//end testWithoutADeclineReceiverDeclinesFallThroughToRemoteChanges()

	/**
	 * A known sender wins, and the decline receiver is not asked.
	 *
	 * @return void
	 */
	public function testAKnownSenderIsTheFederationId(): void {
		$this->remoteChanges->expects($this->once())->method('senderOf')->with('secret', ['x' => 1])->willReturn('alice@remote.test');
		$this->declines->expects($this->never())->method('recipientOf');

		$this->assertSame('alice@remote.test', $this->provider()->getFederationIdFromSharedSecret('secret', ['x' => 1]));
	}//end testAKnownSenderIsTheFederationId()

	/**
	 * Without a known sender the decline receiver names the recipient.
	 *
	 * @return void
	 */
	public function testAnUnknownSenderFallsBackToTheDeclinedRecipient(): void {
		$this->remoteChanges->method('senderOf')->willReturn('');
		$this->declines->expects($this->once())->method('recipientOf')->with('secret', ['x' => 1])->willReturn('bob@remote.test');

		$this->assertSame('bob@remote.test', $this->provider()->getFederationIdFromSharedSecret('secret', ['x' => 1]));
	}//end testAnUnknownSenderFallsBackToTheDeclinedRecipient()

	/**
	 * Without a sender or a decline receiver the federation id is empty.
	 *
	 * @return void
	 */
	public function testNoSenderAndNoDeclineReceiverIsEmpty(): void {
		$this->remoteChanges->method('senderOf')->willReturn('');

		$this->assertSame('', $this->provider(withDeclines: false)->getFederationIdFromSharedSecret('secret', []));
	}//end testNoSenderAndNoDeclineReceiverIsEmpty()
}//end class
