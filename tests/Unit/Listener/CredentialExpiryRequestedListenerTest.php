<?php

/**
 * Unit tests for CredentialExpiryRequestedListener.
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

use DateTime;
use OCA\Keepiq\Db\BrokerMint;
use OCA\Keepiq\Db\BrokerMintMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\CredentialExpiryRequestedEvent;
use OCA\Keepiq\Listener\CredentialExpiryRequestedListener;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * integriq learns the expiry of its own organisation certificate, and of
 * nothing else.
 *
 * @spec openspec/changes/connection-certificate-expiry-for-integriq/specs/integration-boundary/spec.md#requirement-integriq-may-learn-when-its-own-connection-certificate-expires
 */
#[CoversClass(CredentialExpiryRequestedListener::class)]
#[CoversClass(CredentialExpiryRequestedEvent::class)]
#[UsesClass(BrokerMint::class)]
#[UsesClass(Secret::class)]
class CredentialExpiryRequestedListenerTest extends TestCase {
	private const SECRET = '9a1b2c3d-0000-4000-8000-000000000001';

	/**
	 * The mint records.
	 *
	 * @var BrokerMintMapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mints;

	/**
	 * The vault entries.
	 *
	 * @var SecretMapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $secrets;

	/**
	 * The listener under test.
	 *
	 * @var CredentialExpiryRequestedListener
	 */
	private CredentialExpiryRequestedListener $listener;

	/**
	 * Build the listener over mocked mappers and a URL generator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mints = $this->createMock(BrokerMintMapper::class);
		$this->secrets = $this->createMock(SecretMapper::class);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->with('keepiq.dashboard.page')->willReturn('/index.php/apps/keepiq/');
		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.zuiddrecht.example' . $path);

		$this->listener = new CredentialExpiryRequestedListener(mints: $this->mints, secrets: $this->secrets, url: $url);
	}//end setUp()

	/**
	 * A mint record.
	 *
	 * @param string|null $organisation The organisation
	 * @param string|null $consumer     The consumer
	 *
	 * @return BrokerMint
	 */
	private static function mint(?string $organisation, ?string $consumer): BrokerMint {
		$mint = new BrokerMint();
		$mint->setSecretId(self::SECRET);
		$mint->setOrganisationId($organisation);
		$mint->setConsumer($consumer);
		return $mint;
	}//end mint()

	/**
	 * A certificate secret expiring on 30 November 2026.
	 *
	 * @param string $ownerType application or user
	 *
	 * @return Secret
	 */
	private static function certificate(string $ownerType = 'application'): Secret {
		$secret = new Secret();
		$secret->setId(self::SECRET);
		$secret->setName('BRP mTLS');
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerType === 'application' ? 'app-or' : 'alice');
		$secret->setExpiresAt(new DateTime('2026-11-30T00:00:00+00:00'));
		return $secret;
	}//end certificate()

	/**
	 * Ask the listener, acting for an organisation.
	 *
	 * @param string $organisation The organisation integriq acts for
	 *
	 * @return CredentialExpiryRequestedEvent
	 */
	private function ask(string $organisation = 'zuiddrecht'): CredentialExpiryRequestedEvent {
		$event = new CredentialExpiryRequestedEvent(credentialId: self::SECRET, actingOrganisationId: $organisation);
		$this->listener->handle($event);
		return $event;
	}//end ask()

	/**
	 * integriq's own BRP certificate: notAfter 2026-11-30 and a link to the secret, nothing else.
	 *
	 * @return void
	 */
	public function testIntegriqGetsTheExpiryOfItsOwnCertificate(): void {
		$this->mints->method('findBySecret')->with(self::SECRET)->willReturn(self::mint('zuiddrecht', 'integriq'));
		$this->secrets->method('findById')->with(self::SECRET)->willReturn(self::certificate());

		$event = $this->ask();

		$this->assertTrue($event->isAnswered());
		$this->assertSame('2026-11-30T00:00:00+00:00', $event->getNotAfter());
		$this->assertSame('https://cloud.zuiddrecht.example/index.php/apps/keepiq/secrets/' . self::SECRET, $event->getRenewUrl());
	}//end testIntegriqGetsTheExpiryOfItsOwnCertificate()

	/**
	 * A personal vault certificate gets no answer, even with a matching record.
	 *
	 * @return void
	 */
	public function testAPersonalCredentialGetsNoAnswer(): void {
		$this->mints->method('findBySecret')->willReturn(self::mint('zuiddrecht', 'integriq'));
		$this->secrets->method('findById')->willReturn(self::certificate(ownerType: 'user'));

		$this->assertFalse($this->ask()->isAnswered());
	}//end testAPersonalCredentialGetsNoAnswer()

	/**
	 * Another organisation's certificate gets no answer, and the vault is not read.
	 *
	 * @return void
	 */
	public function testAnotherOrganisationsCredentialGetsNoAnswer(): void {
		$this->mints->method('findBySecret')->willReturn(self::mint('noordmeer', 'integriq'));
		$this->secrets->expects($this->never())->method('findById');

		$event = $this->ask();

		$this->assertFalse($event->isAnswered());
		$this->assertNull($event->getNotAfter());
		$this->assertNull($event->getRenewUrl());
	}//end testAnotherOrganisationsCredentialGetsNoAnswer()

	/**
	 * A credential minted for another app gets no answer.
	 *
	 * @return void
	 */
	public function testACredentialMintedForAnotherAppGetsNoAnswer(): void {
		$this->mints->method('findBySecret')->willReturn(self::mint('zuiddrecht', 'pipelinq'));
		$this->secrets->expects($this->never())->method('findById');

		$this->assertFalse($this->ask()->isAnswered());
	}//end testACredentialMintedForAnotherAppGetsNoAnswer()

	/**
	 * A secret the broker did not mint, an unknown secret, an empty organisation,
	 * a trashed secret and one without an expiry all get no answer.
	 *
	 * @return void
	 */
	public function testEveryOtherRequestGetsNoAnswer(): void {
		$this->mints->method('findBySecret')->willReturnOnConsecutiveCalls(null, self::mint('zuiddrecht', 'integriq'), self::mint('zuiddrecht', 'integriq'), self::mint('zuiddrecht', 'integriq'));
		$trashed = self::certificate();
		$trashed->setTrashedAt(new DateTime('2026-11-01T00:00:00+00:00'));
		$noExpiry = self::certificate();
		$noExpiry->setExpiresAt(null);
		$this->secrets->method('findById')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('gone')),
			$trashed,
			$noExpiry,
		);

		$this->assertFalse($this->ask()->isAnswered(), 'not minted by the broker');
		$this->assertFalse($this->ask()->isAnswered(), 'unknown secret');
		$this->assertFalse($this->ask(organisation: '  ')->isAnswered(), 'no organisation');
		$this->assertFalse($this->ask()->isAnswered(), 'trashed');
		$this->assertFalse($this->ask()->isAnswered(), 'no expiry');
	}//end testEveryOtherRequestGetsNoAnswer()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->mints->expects($this->never())->method('findBySecret');
		$this->listener->handle(new Event());
		$this->addToAssertionCount(1);
	}//end testAnotherEventIsIgnored()
}//end class
