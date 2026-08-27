<?php

/**
 * Unit tests for the CXP relay's pairing authorization.
 *
 * The relay is a blind mailbox shared by TWO accounts on purpose: the importer
 * mints a pairing and publishes its CXP request, the pairing code is read out
 * of band to somebody else, whose vault fetches that request and writes back an
 * HPKE-sealed reply. So "the caller must own the row" cannot be the test, and
 * these cases pin the two questions that CAN be asked — was the pairing issued
 * by this relay, and for the one operation only the minter ever performs, is
 * the caller that minter.
 *
 * Every assertion is on the CACHE — what was written, and under which key —
 * not on the response shape. A relay that answered 200 and stored nothing, or
 * stored under an attacker-chosen key, would satisfy a status-code assertion.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\CxpRelayController;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Tests the relay's mint check and its minter binding on the response read.
 */
class CxpRelayControllerTest extends TestCase {
	/**
	 * The pairing id the stubbed ISecureRandom always mints.
	 */
	private const MINTED = 'MINTEDPAIRINGID0000000AA';

	/**
	 * The fake distributed cache contents.
	 *
	 * @var array<string,mixed>
	 */
	private array $store = [];

	/**
	 * The UID the session currently reports, or null for no session.
	 *
	 * @var string|null
	 */
	private ?string $currentUid = 'importer';

	private CxpRelayController $controller;

	/**
	 * Build the controller over an in-memory cache fake and a switchable
	 * session, so a write is observable as a stored value under a specific key
	 * and the calling identity can be changed mid-test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = [];
		$this->currentUid = 'importer';

		$cache = $this->createMock(originalClassName: ICache::class);
		$cache->method('get')->willReturnCallback(
			fn (string $key): mixed => ($this->store[$key] ?? null)
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->store[$key]);
				return true;
			}
		);

		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$secureRandom = $this->createMock(originalClassName: ISecureRandom::class);
		$secureRandom->method('generate')->willReturn(self::MINTED);

		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(
			function (): ?IUser {
				if ($this->currentUid === null) {
					return null;
				}

				$user = $this->createMock(originalClassName: IUser::class);
				$user->method('getUID')->willReturn($this->currentUid);
				return $user;
			}
		);

		$this->controller = new CxpRelayController(
			request: $this->createMock(originalClassName: IRequest::class),
			cacheFactory: $cacheFactory,
			secureRandom: $secureRandom,
			userSession: $userSession,
		);
	}//end setUp()

	/**
	 * Run the importer's first call: mint a pairing and publish the request.
	 *
	 * @return void
	 */
	private function mint(): void {
		$this->currentUid = 'importer';
		$this->controller->put(pairingId: null, slot: 'request', payload: 'cxp-request');
	}//end mint()

	/**
	 * Minting records WHO started the transfer, and stores the request.
	 *
	 * @return void
	 */
	public function testMintingRecordsTheMinterAndStoresTheRequest(): void {
		$response = $this->controller->put(pairingId: null, slot: 'request', payload: 'cxp-request');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::MINTED, $response->getData()['pairingId']);
		$this->assertSame(
			'importer',
			$this->store['pairing:' . self::MINTED] ?? null,
			'the minter UID must be recorded — it is what binds the sealed reply back to them'
		);
		$this->assertSame('cxp-request', $this->store[self::MINTED . ':request']);
	}//end testMintingRecordsTheMinterAndStoresTheRequest()

	/**
	 * The full happy path still works across TWO different accounts.
	 *
	 * This is the positive control for every denial below: without it, tests
	 * that only prove refusals would also pass against a relay that refuses
	 * everything and has broken the feature.
	 *
	 * @return void
	 */
	public function testTheTwoPartyHandshakeStillCompletes(): void {
		$this->mint();

		// The peer — a DIFFERENT account — reads the request and seals a reply.
		$this->currentUid = 'exporter';
		$read = $this->controller->get(pairingId: self::MINTED, slot: 'request');
		$this->assertSame(200, $read->getStatus(), 'the peer must be able to read the request');
		$this->assertSame('cxp-request', $read->getData()['payload']);

		$write = $this->controller->put(
			pairingId: self::MINTED,
			slot: 'response',
			payload: 'sealed-envelope'
		);
		$this->assertSame(200, $write->getStatus(), 'the peer must be able to write the sealed reply');
		$this->assertSame('sealed-envelope', $this->store[self::MINTED . ':response']);

		// The importer collects it.
		$this->currentUid = 'importer';
		$collect = $this->controller->get(pairingId: self::MINTED, slot: 'response');
		$this->assertSame(200, $collect->getStatus());
		$this->assertSame('sealed-envelope', $collect->getData()['payload']);
	}//end testTheTwoPartyHandshakeStillCompletes()

	/**
	 * A caller-invented pairing id is refused, and stores NOTHING.
	 *
	 * Before the mint check, any authenticated user could write an unlimited
	 * number of 4 MiB blobs under keys of their own choosing — arbitrary
	 * attacker-keyed storage in the SHARED distributed cache, with no
	 * handshake ever started.
	 *
	 * @return void
	 */
	public function testAnUnmintedPairingIdIsRefusedAndWritesNothing(): void {
		$response = $this->controller->put(
			pairingId: 'attackerchosenkey0000001',
			slot: 'response',
			payload: str_repeat('A', 4096)
		);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(
			[],
			$this->store,
			'a refused write must leave the shared cache completely untouched'
		);
	}//end testAnUnmintedPairingIdIsRefusedAndWritesNothing()

	/**
	 * A third party who overheard the pairing code cannot take the envelope.
	 *
	 * The relay DELETES a slot on read, so before this binding an eavesdropper
	 * — the code travels by voice, chat or ticket, out of band by design —
	 * could consume the sealed envelope and leave the importer polling until
	 * timeout. The envelope must still be there for its owner afterwards.
	 *
	 * @return void
	 */
	public function testAThirdPartyCannotConsumeTheSealedEnvelope(): void {
		$this->mint();
		$this->currentUid = 'exporter';
		$this->controller->put(pairingId: self::MINTED, slot: 'response', payload: 'sealed-envelope');

		$this->currentUid = 'eavesdropper';
		$stolen = $this->controller->get(pairingId: self::MINTED, slot: 'response');

		$this->assertSame(403, $stolen->getStatus());
		$this->assertSame(
			'sealed-envelope',
			$this->store[self::MINTED . ':response'] ?? null,
			'a refused read must NOT consume the slot — otherwise the denial is itself the denial-of-service'
		);

		$this->currentUid = 'importer';
		$this->assertSame(
			200,
			$this->controller->get(pairingId: self::MINTED, slot: 'response')->getStatus(),
			'the rightful importer must still be able to collect it'
		);
	}//end testAThirdPartyCannotConsumeTheSealedEnvelope()

	/**
	 * Reading an unminted pairing is refused.
	 *
	 * @return void
	 */
	public function testReadingAnUnmintedPairingIsRefused(): void {
		$response = $this->controller->get(pairingId: 'neverminted000000000000A', slot: 'response');

		$this->assertSame(404, $response->getStatus());
	}//end testReadingAnUnmintedPairingIsRefused()

	/**
	 * The one-shot read is preserved for the authorized caller.
	 *
	 * @return void
	 */
	public function testALivePairingReadsOnceAndIsConsumed(): void {
		$this->mint();

		$this->currentUid = 'exporter';
		$first = $this->controller->get(pairingId: self::MINTED, slot: 'request');
		$this->assertSame(200, $first->getStatus());

		$second = $this->controller->get(pairingId: self::MINTED, slot: 'request');
		$this->assertSame(404, $second->getStatus(), 'the slot must be consumed on read');
	}//end testALivePairingReadsOnceAndIsConsumed()

	/**
	 * The slot whitelist and payload bounds still reject before the guard.
	 *
	 * @return void
	 */
	public function testInvalidSlotAndEmptyPayloadAreStillRejected(): void {
		$this->assertSame(
			400,
			$this->controller->put(pairingId: null, slot: 'not-a-slot', payload: 'x')->getStatus()
		);
		$this->assertSame(
			400,
			$this->controller->put(pairingId: null, slot: 'request', payload: '')->getStatus()
		);
		$this->assertSame([], $this->store);
	}//end testInvalidSlotAndEmptyPayloadAreStillRejected()

	/**
	 * An anonymous caller cannot mint a pairing.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerCannotMint(): void {
		$this->currentUid = null;

		$response = $this->controller->put(pairingId: null, slot: 'request', payload: 'x');

		$this->assertSame(401, $response->getStatus());
		$this->assertSame([], $this->store);
	}//end testAnAnonymousCallerCannotMint()
}//end class
