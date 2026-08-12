<?php

/**
 * Unit tests for HoneyTripwireListener (honey-credentials §6): channel
 * derivation per source event, the share-copy pivot, and fail-softness.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Listener
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

namespace OCA\Doriath\Tests\Unit\Listener;

use OCA\Doriath\Db\LinkShare;
use OCA\Doriath\Db\LinkShareMapper;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Listener\HoneyTripwireListener;
use OCA\Doriath\Service\HoneyTripwireService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the central tripwire listener.
 */
class HoneyTripwireListenerTest extends TestCase
{
    private HoneyTripwireListener $listener;

    private HoneyTripwireService&MockObject $honeyService;

    private LinkShareMapper&MockObject $linkShareMapper;

    private ShareTargetMapper&MockObject $shareTargetMapper;

    /**
     * Build the listener over mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->honeyService      = $this->createMock(originalClassName: HoneyTripwireService::class);
        $this->linkShareMapper   = $this->createMock(originalClassName: LinkShareMapper::class);
        $this->shareTargetMapper = $this->createMock(originalClassName: ShareTargetMapper::class);

        $request = $this->createMock(originalClassName: IRequest::class);
        $request->method('getRemoteAddress')->willReturn('192.0.2.7');
        $request->method('getHeader')->willReturn('TestAgent/1.0');

        $this->listener = new HoneyTripwireListener(
            honeyService: $this->honeyService,
            linkShareMapper: $this->linkShareMapper,
            shareTargetMapper: $this->shareTargetMapper,
            request: $request,
            logger: new NullLogger(),
        );
    }//end setUp()

    /**
     * A UI read of a flagged secret trips with channel ui + transport
     * metadata (§6.1).
     *
     * @return void
     */
    public function testSecretReadTripsUiChannel(): void
    {
        $this->honeyService->expects($this->once())->method('raiseAlert')
            ->with(
                $this->equalTo('secret-1'),
                $this->equalTo('user'),
                $this->equalTo('bob'),
                $this->equalTo('ui'),
                $this->equalTo('192.0.2.7'),
                $this->equalTo('TestAgent/1.0'),
            )
            ->willReturn(true);
        $this->shareTargetMapper->expects($this->never())->method('findByRecipientSecret');

        $this->listener->handle(
            AuditEvent::forUser(actorId: 'bob', eventType: AuditEventTypes::SECRET_READ, objectType: 'secret', objectId: 'secret-1')
        );
    }//end testSecretReadTripsUiChannel()

    /**
     * A read of an UNflagged copy pivots to its flagged source with
     * channel share (§6.1).
     *
     * @return void
     */
    public function testCopyReadPivotsToSourceAsShareChannel(): void
    {
        $shareTarget = new ShareTarget();
        $shareTarget->setSourceSecretId('source-1');
        $this->shareTargetMapper->method('findByRecipientSecret')->with('copy-1')->willReturn($shareTarget);

        $calls = [];
        $this->honeyService->method('raiseAlert')
            ->willReturnCallback(static function (string $secretId, string $accessorType, ?string $accessorId, string $channel) use (&$calls): bool {
                $calls[] = [$secretId, $channel];

                return ($secretId === 'source-1');
            });

        $this->listener->handle(
            AuditEvent::forUser(actorId: 'bob', eventType: AuditEventTypes::SECRET_READ, objectType: 'secret', objectId: 'copy-1')
        );

        $this->assertSame([['copy-1', 'ui'], ['source-1', 'share']], $calls);
    }//end testCopyReadPivotsToSourceAsShareChannel()

    /**
     * A machine retrieval trips with channel machine_api (§6.1).
     *
     * @return void
     */
    public function testApplicationRetrievalTripsMachineChannel(): void
    {
        $this->honeyService->expects($this->once())->method('raiseAlert')
            ->with($this->anything(), $this->equalTo('application'), $this->equalTo('app-9'), $this->equalTo('machine_api'))
            ->willReturn(true);

        $this->listener->handle(
            AuditEvent::forApplication(
                actorId: 'app-9',
                eventType: AuditEventTypes::APPLICATION_SECRET_RETRIEVED,
                objectType: 'secret',
                objectId: 'secret-1'
            )
        );
    }//end testApplicationRetrievalTripsMachineChannel()

    /**
     * An anonymous link access resolves the link row to its secret and
     * trips with channel link (§6.1).
     *
     * @return void
     */
    public function testLinkAccessResolvesSecretAndTripsLinkChannel(): void
    {
        $linkShare = new LinkShare();
        $linkShare->setSecretId('secret-1');
        $this->linkShareMapper->method('findById')->with('link-1')->willReturn($linkShare);

        $this->honeyService->expects($this->once())->method('raiseAlert')
            ->with($this->equalTo('secret-1'), $this->equalTo('link_visitor'), $this->isNull(), $this->equalTo('link'))
            ->willReturn(true);

        $this->listener->handle(
            AuditEvent::forLinkVisitor(eventType: AuditEventTypes::LINK_SHARE_ACCESSED, objectType: 'link_share', objectId: 'link-1')
        );
    }//end testLinkAccessResolvesSecretAndTripsLinkChannel()

    /**
     * Non-read events never touch the honey service (§6.1).
     *
     * @return void
     */
    public function testNonReadEventsAreIgnored(): void
    {
        $this->honeyService->expects($this->never())->method('raiseAlert');

        $this->listener->handle(
            AuditEvent::forUser(actorId: 'bob', eventType: AuditEventTypes::SECRET_CREATED, objectType: 'secret', objectId: 'secret-1')
        );
        $this->listener->handle(
            AuditEvent::forSystem(eventType: AuditEventTypes::HONEY_ACCESSED, objectType: 'secret', objectId: 'secret-1')
        );
    }//end testNonReadEventsAreIgnored()

    /**
     * The listener is fail-soft: a resolver failure is swallowed (§6.2).
     *
     * @return void
     */
    public function testListenerIsFailSoft(): void
    {
        $this->linkShareMapper->method('findById')->willThrowException(new DoesNotExistException('gone'));

        $this->listener->handle(
            AuditEvent::forLinkVisitor(eventType: AuditEventTypes::LINK_SHARE_ACCESSED, objectType: 'link_share', objectId: 'link-x')
        );

        $this->addToAssertionCount(1);
    }//end testListenerIsFailSoft()
}//end class
