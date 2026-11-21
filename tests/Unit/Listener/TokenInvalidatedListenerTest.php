<?php

/**
 * Unit tests for TokenInvalidatedListener.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
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

namespace OCA\Keepiq\Tests\Unit\Listener;

use OCA\Keepiq\Db\PasskeyMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Listener\TokenInvalidatedListener;
use OCA\Keepiq\Service\ExtensionChallengeStore;
use OCP\Authentication\Events\TokenInvalidatedEvent;
use OCP\Authentication\Token\IToken;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * An invalidated app password takes its extension passkeys with it.
 */
class TokenInvalidatedListenerTest extends TestCase {
	/**
	 * Revoking an app password deletes its credentials and challenges and
	 * audits each owner.
	 *
	 * @return void
	 */
	public function testDeletesTheCredentialsAndChallengesOfTheAppPassword(): void {
		if (class_exists(TokenInvalidatedEvent::class) === false) {
			$this->markTestSkipped('Needs OCP\Authentication\Events\TokenInvalidatedEvent (Nextcloud 32)');
		}

		$token = $this->createMock(originalClassName: IToken::class);
		$token->method('getId')->willReturn(42);

		$passkeys = $this->createMock(originalClassName: PasskeyMapper::class);
		$passkeys->expects($this->once())->method('deleteByToken')->with(42)->willReturn(['alice' => 2]);
		$challenges = $this->createMock(originalClassName: ExtensionChallengeStore::class);
		$challenges->expects($this->once())->method('forgetToken')->with(42)->willReturn(1);

		$audited = [];
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(static function (Event $event) use (&$audited): void {
			$audited[] = $event;
		});

		(new TokenInvalidatedListener(passkeys: $passkeys, challenges: $challenges, dispatcher: $dispatcher))
			->handle(new TokenInvalidatedEvent($token));

		$this->assertCount(1, $audited);
		$this->assertInstanceOf(AuditEvent::class, $audited[0]);
		$this->assertSame(AuditEventTypes::PASSKEY_REVOKED_WITH_APP_PASSWORD, $audited[0]->getEventType());
		$this->assertSame('alice', $audited[0]->getActorId());
		$this->assertSame(['credentialCount' => 2], $audited[0]->getMetadata());
	}//end testDeletesTheCredentialsAndChallengesOfTheAppPassword()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresOtherEvents(): void {
		$passkeys = $this->createMock(originalClassName: PasskeyMapper::class);
		$passkeys->expects($this->never())->method('deleteByToken');
		$challenges = $this->createMock(originalClassName: ExtensionChallengeStore::class);
		$challenges->expects($this->never())->method('forgetToken');

		(new TokenInvalidatedListener(passkeys: $passkeys, challenges: $challenges, dispatcher: $this->createMock(IEventDispatcher::class)))
			->handle(new Event());
	}//end testIgnoresOtherEvents()
}//end class
