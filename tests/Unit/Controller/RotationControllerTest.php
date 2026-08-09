<?php

/**
 * Contract tests for the RotationController expiry, policy and flag endpoints.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Controller;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Controller\RotationController;
use OCA\Doriath\Db\ExpiryPolicy;
use OCA\Doriath\Db\RotationFlag;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Exception\NotFoundException;
use OCA\Doriath\Service\RotationPolicyService;
use OCA\Doriath\Service\SecretService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire contract for the six routed rotation/expiry endpoints:
 * `rotation#getExpiry`, `rotation#setExpiry`, `rotation#policies`,
 * `rotation#upsertPolicy`, `rotation#destroyPolicy`, `rotation#flags` and
 * `rotation#dismissFlag`.
 *
 * Every method is `#[NoAdminRequired]` and takes its owner from the SESSION,
 * never from the request body. The per-object authorization lives in the
 * services, and the controller deliberately collapses "not found" and
 * "forbidden" into one 404 so the API is not an existence oracle — a property
 * that only a test comparing BOTH branches' bodies can protect.
 *
 */
class RotationControllerTest extends TestCase
{

    /**
     * The single 404 body both the "absent" and the "refused" branch must
     * produce, so the endpoint never becomes an existence oracle.
     *
     * @var array<string,string>
     */
    private const NOT_FOUND_BODY = ['message' => 'Not found'];

    /**
     * The mocked request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * The mocked rotation policy service.
     *
     * @var RotationPolicyService&MockObject
     */
    private RotationPolicyService&MockObject $rotationService;

    /**
     * The mocked secret service.
     *
     * @var SecretService&MockObject
     */
    private SecretService&MockObject $secretService;

    /**
     * The mocked user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;


    /**
     * Set up the mocks shared by every test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(originalClassName: IRequest::class);
        $this->rotationService = $this->createMock(originalClassName: RotationPolicyService::class);
        $this->secretService   = $this->createMock(originalClassName: SecretService::class);
        $this->userSession     = $this->createMock(originalClassName: IUserSession::class);
    }//end setUp()


    /**
     * Build the controller with the session resolving to the given user.
     *
     * @param string|null $userId The session user, or null for an anonymous caller
     *
     * @return RotationController The controller under test.
     */
    private function controller(?string $userId='alice'): RotationController
    {
        if ($userId === null) {
            $this->userSession->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(originalClassName: IUser::class);
            $user->method('getUID')->willReturn($userId);
            $this->userSession->method('getUser')->willReturn($user);
        }

        return new RotationController(
            request: $this->request,
            rotationService: $this->rotationService,
            secretService: $this->secretService,
            userSession: $this->userSession,
        );
    }//end controller()


    /**
     * Build a real Secret entity (the controller serialises it verbatim).
     *
     * @param string        $id        The secret UUID
     * @param string        $name      The plaintext name
     * @param DateTime|null $expiresAt The stored expiry
     *
     * @return Secret The populated entity.
     */
    private function secret(string $id, string $name, ?DateTime $expiresAt=null): Secret
    {
        $secret = new Secret();
        $secret->setId($id);
        $secret->setName($name);
        $secret->setExpiresAt($expiresAt);

        return $secret;
    }//end secret()


    // ---------------------------------------------------------------
    // rotation#setExpiry — PUT /api/v1/secrets/{id}/expiry
    // ---------------------------------------------------------------


    /**
     * The submitted ISO-8601 instant must reach the service as a DateTime for
     * the routed secret and the session owner, and the response must carry the
     * stored secret plus the policy-resolved effective expiry.
     *
     * @return void
     */
    public function testSetExpiryForwardsTheParsedInstantAndReturnsBothExpiries(): void
    {
        $controller = $this->controller('alice');
        $stored     = $this->secret(
            id: 'aa000000-0000-4000-8000-00000000000a',
            name: 'Production database',
            expiresAt: new DateTime('2027-03-01T12:00:00+00:00')
        );

        $this->secretService->expects($this->once())
            ->method('setExpiry')
            ->with(
                'aa000000-0000-4000-8000-00000000000a',
                new DateTime('2027-03-01T12:00:00+00:00'),
                'alice'
            )
            ->willReturn($stored);

        $this->rotationService->expects($this->once())
            ->method('resolveEffectiveExpiry')
            ->with($stored)
            ->willReturn(new DateTime('2027-02-01T00:00:00+00:00'));

        $response = $controller->setExpiry(
            id: 'aa000000-0000-4000-8000-00000000000a',
            expiresAt: '2027-03-01T12:00:00+00:00'
        );

        $data = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            $stored->jsonSerialize(),
            $data['secret'],
            'the response must echo the secret as the service stored it'
        );
        $this->assertSame(
            '2027-03-01T12:00:00+00:00',
            $data['secret']['expiresAt'],
            'the stored expiry must be the submitted instant'
        );
        $this->assertSame(
            '2027-02-01T00:00:00+00:00',
            $data['effectiveExpiry'],
            'the effective expiry is the policy-resolved one, which may precede the stored one'
        );
    }//end testSetExpiryForwardsTheParsedInstantAndReturnsBothExpiries()


    /**
     * An empty `expiresAt` clears the expiry — the service must be called with
     * a null DateTime, not with an epoch or with the empty string.
     *
     * @return void
     */
    public function testSetExpiryWithAnEmptyValueClearsTheExpiry(): void
    {
        $controller = $this->controller('alice');
        $stored     = $this->secret(id: 'bb000000-0000-4000-8000-00000000000b', name: 'API key');

        $this->secretService->expects($this->once())
            ->method('setExpiry')
            ->with('bb000000-0000-4000-8000-00000000000b', null, 'alice')
            ->willReturn($stored);

        $this->rotationService->method('resolveEffectiveExpiry')->willReturn(null);

        $response = $controller->setExpiry(id: 'bb000000-0000-4000-8000-00000000000b', expiresAt: '');

        $data = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertNull($data['secret']['expiresAt'], 'the cleared expiry must serialise as null');
        $this->assertNull($data['effectiveExpiry'], 'no policy applies, so there is no effective expiry');
    }//end testSetExpiryWithAnEmptyValueClearsTheExpiry()


    /**
     * An unparseable instant is a 400 and must never reach the write.
     *
     * @return void
     */
    public function testSetExpiryRejectsAnUnparseableInstantWithoutWriting(): void
    {
        $controller = $this->controller('alice');

        $this->secretService->expects($this->never())->method('setExpiry');

        $response = $controller->setExpiry(
            id: 'bb000000-0000-4000-8000-00000000000b',
            expiresAt: 'the day after tomorrow-ish'
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['message' => 'Invalid expiresAt'], $response->getData());
    }//end testSetExpiryRejectsAnUnparseableInstantWithoutWriting()


    /**
     * A secret that does not exist answers 404 and no effective expiry is
     * resolved for a secret that was never loaded.
     *
     * @return void
     */
    public function testSetExpiryReportsAnAbsentSecretAsNotFound(): void
    {
        $controller = $this->controller('alice');

        $this->secretService->expects($this->once())
            ->method('setExpiry')
            ->with('cc000000-0000-4000-8000-00000000000c', null, 'alice')
            ->willThrowException(new NotFoundException('no such secret'));
        $this->rotationService->expects($this->never())->method('resolveEffectiveExpiry');

        $response = $controller->setExpiry(id: 'cc000000-0000-4000-8000-00000000000c');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(self::NOT_FOUND_BODY, $response->getData());
    }//end testSetExpiryReportsAnAbsentSecretAsNotFound()


    /**
     * A secret the caller does not own answers the byte-identical 404 the
     * absent-secret branch answers — the endpoint is not an existence oracle.
     *
     * Both branches are asserted against the SAME shared literal, so a change
     * that made a refusal distinguishable from an absence would fail here.
     *
     * @return void
     */
    public function testSetExpiryHidesOwnershipBehindTheSameNotFoundBody(): void
    {
        $controller = $this->controller('alice');

        $this->secretService->expects($this->once())
            ->method('setExpiry')
            ->with('cc000000-0000-4000-8000-00000000000c', null, 'alice')
            ->willThrowException(new ForbiddenException('not the owner'));
        $this->rotationService->expects($this->never())->method('resolveEffectiveExpiry');

        $response = $controller->setExpiry(id: 'cc000000-0000-4000-8000-00000000000c');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(
            self::NOT_FOUND_BODY,
            $response->getData(),
            'a refused secret and an absent one must be indistinguishable to the caller'
        );
    }//end testSetExpiryHidesOwnershipBehindTheSameNotFoundBody()


    /**
     * An anonymous caller gets 401 and no write is attempted.
     *
     * @return void
     */
    public function testSetExpiryRefusesAnAnonymousCaller(): void
    {
        $controller = $this->controller(null);

        $this->secretService->expects($this->never())->method('setExpiry');

        $response = $controller->setExpiry(id: 'dd000000-0000-4000-8000-00000000000d', expiresAt: '2027-01-01T00:00:00+00:00');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Unauthorized'], $response->getData());
    }//end testSetExpiryRefusesAnAnonymousCaller()


    // ---------------------------------------------------------------
    // rotation#getExpiry — GET /api/v1/secrets/{id}/expiry
    // ---------------------------------------------------------------


    /**
     * The read must be owner-scoped and return BOTH the stored expiry and the
     * policy-resolved effective expiry, formatted ISO-8601.
     *
     * @return void
     */
    public function testGetExpiryReadsTheOwnedSecretAndReturnsBothExpiries(): void
    {
        $controller = $this->controller('alice');
        $secret     = $this->secret(
            id: 'ee000000-0000-4000-8000-00000000000e',
            name: 'Signing certificate',
            expiresAt: new DateTime('2026-12-31T23:59:00+00:00')
        );

        $this->secretService->expects($this->once())
            ->method('findOwned')
            ->with('ee000000-0000-4000-8000-00000000000e', 'alice')
            ->willReturn($secret);

        $this->rotationService->expects($this->once())
            ->method('resolveEffectiveExpiry')
            ->with($secret)
            ->willReturn(new DateTime('2026-10-01T00:00:00+00:00'));

        $response = $controller->getExpiry(id: 'ee000000-0000-4000-8000-00000000000e');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            [
                'expiresAt'       => '2026-12-31T23:59:00+00:00',
                'effectiveExpiry' => '2026-10-01T00:00:00+00:00',
            ],
            $response->getData(),
            'the payload carries exactly the stored and the effective expiry'
        );
    }//end testGetExpiryReadsTheOwnedSecretAndReturnsBothExpiries()


    /**
     * A secret without a stored expiry reports null, not an omitted key — the
     * UI distinguishes "never expires" from "unknown".
     *
     * @return void
     */
    public function testGetExpiryReportsNullForASecretWithoutAnExpiry(): void
    {
        $controller = $this->controller('alice');
        $secret     = $this->secret(id: 'ff000000-0000-4000-8000-00000000000f', name: 'Wiki login');

        $this->secretService->expects($this->once())
            ->method('findOwned')
            ->with('ff000000-0000-4000-8000-00000000000f', 'alice')
            ->willReturn($secret);
        $this->rotationService->method('resolveEffectiveExpiry')->willReturn(null);

        $data = $controller->getExpiry(id: 'ff000000-0000-4000-8000-00000000000f')->getData();

        $this->assertArrayHasKey('expiresAt', $data);
        $this->assertArrayHasKey('effectiveExpiry', $data);
        $this->assertNull($data['expiresAt']);
        $this->assertNull($data['effectiveExpiry']);
    }//end testGetExpiryReportsNullForASecretWithoutAnExpiry()


    /**
     * A secret that does not exist answers 404 and resolves no expiry.
     *
     * @return void
     */
    public function testGetExpiryReportsAnAbsentSecretAsNotFound(): void
    {
        $controller = $this->controller('alice');

        $this->secretService->expects($this->once())
            ->method('findOwned')
            ->with('11000000-0000-4000-8000-000000000011', 'alice')
            ->willThrowException(new NotFoundException('no such secret'));
        $this->rotationService->expects($this->never())->method('resolveEffectiveExpiry');

        $response = $controller->getExpiry(id: '11000000-0000-4000-8000-000000000011');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(self::NOT_FOUND_BODY, $response->getData());
    }//end testGetExpiryReportsAnAbsentSecretAsNotFound()


    /**
     * A refused read answers the byte-identical 404 an absent one answers.
     *
     * @return void
     */
    public function testGetExpiryHidesOwnershipBehindTheSameNotFoundBody(): void
    {
        $controller = $this->controller('alice');

        $this->secretService->expects($this->once())
            ->method('findOwned')
            ->with('11000000-0000-4000-8000-000000000011', 'alice')
            ->willThrowException(new ForbiddenException('not the owner'));
        $this->rotationService->expects($this->never())->method('resolveEffectiveExpiry');

        $response = $controller->getExpiry(id: '11000000-0000-4000-8000-000000000011');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(
            self::NOT_FOUND_BODY,
            $response->getData(),
            'a refused read and an absent secret must be indistinguishable to the caller'
        );
    }//end testGetExpiryHidesOwnershipBehindTheSameNotFoundBody()


    /**
     * An anonymous caller gets 401 and the secret is never looked up.
     *
     * @return void
     */
    public function testGetExpiryRefusesAnAnonymousCaller(): void
    {
        $controller = $this->controller(null);

        $this->secretService->expects($this->never())->method('findOwned');

        $response = $controller->getExpiry(id: '11000000-0000-4000-8000-000000000011');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Unauthorized'], $response->getData());
    }//end testGetExpiryRefusesAnAnonymousCaller()


    // ---------------------------------------------------------------
    // rotation#policies — GET /api/v1/expiry-policies
    // ---------------------------------------------------------------


    /**
     * The listing must be scoped to the session user and serialise every
     * policy, decoding the stored reminder-day JSON into an array.
     *
     * @return void
     */
    public function testPoliciesListsTheSessionUsersPoliciesSerialised(): void
    {
        $controller = $this->controller('alice');

        $typePolicy = new ExpiryPolicy();
        $typePolicy->setId('a1000000-0000-4000-8000-0000000000a1');
        $typePolicy->setOwnerId('alice');
        $typePolicy->setScope('type');
        $typePolicy->setScopeId('password');
        $typePolicy->setMaxAgeDays(90);
        $typePolicy->setReminderDays('[30,7]');
        $typePolicy->setCreatedBy('alice');

        $folderPolicy = new ExpiryPolicy();
        $folderPolicy->setId('a2000000-0000-4000-8000-0000000000a2');
        $folderPolicy->setOwnerId('alice');
        $folderPolicy->setScope('folder');
        $folderPolicy->setScopeId('b3000000-0000-4000-8000-0000000000b3');
        $folderPolicy->setMaxAgeDays(null);
        $folderPolicy->setReminderDays('[14]');
        $folderPolicy->setCreatedBy('alice');

        $this->rotationService->expects($this->once())
            ->method('listPolicies')
            ->with('alice')
            ->willReturn([$typePolicy, $folderPolicy]);

        $response = $controller->policies();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(2, $data);
        $this->assertSame('type', $data[0]['scope']);
        $this->assertSame('password', $data[0]['scopeId']);
        $this->assertSame(90, $data[0]['maxAgeDays']);
        $this->assertSame([30, 7], $data[0]['reminderDays'], 'reminder days must be decoded, not the raw JSON string');
        $this->assertSame('folder', $data[1]['scope']);
        $this->assertNull($data[1]['maxAgeDays'], 'a reminder-only policy has no max age');
        $this->assertSame([14], $data[1]['reminderDays']);
    }//end testPoliciesListsTheSessionUsersPoliciesSerialised()


    /**
     * An anonymous caller gets 401 and no policy is listed.
     *
     * @return void
     */
    public function testPoliciesRefusesAnAnonymousCaller(): void
    {
        $controller = $this->controller(null);

        $this->rotationService->expects($this->never())->method('listPolicies');

        $response = $controller->policies();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Unauthorized'], $response->getData());
    }//end testPoliciesRefusesAnAnonymousCaller()


    // ---------------------------------------------------------------
    // rotation#upsertPolicy — POST /api/v1/expiry-policies
    // ---------------------------------------------------------------


    /**
     * Every submitted field must reach the service alongside the session owner,
     * and the stored policy is echoed back.
     *
     * @return void
     */
    public function testUpsertPolicyForwardsEveryFieldAndEchoesTheStoredPolicy(): void
    {
        $controller = $this->controller('alice');

        $policy = new ExpiryPolicy();
        $policy->setId('a3000000-0000-4000-8000-0000000000a3');
        $policy->setOwnerId('alice');
        $policy->setScope('type');
        $policy->setScopeId('api-key');
        $policy->setMaxAgeDays(180);
        $policy->setReminderDays('[30,7,1]');
        $policy->setCreatedBy('alice');

        $this->rotationService->expects($this->once())
            ->method('upsertPolicy')
            ->with('alice', 'type', 'api-key', 180, [30, 7, 1])
            ->willReturn($policy);

        $response = $controller->upsertPolicy(
            scope: 'type',
            scopeId: 'api-key',
            maxAgeDays: 180,
            reminderDays: [30, 7, 1]
        );

        $data = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('a3000000-0000-4000-8000-0000000000a3', $data['id']);
        $this->assertSame('type', $data['scope']);
        $this->assertSame('api-key', $data['scopeId']);
        $this->assertSame(180, $data['maxAgeDays']);
        $this->assertSame([30, 7, 1], $data['reminderDays']);
        $this->assertSame('alice', $data['ownerId'], 'the policy is owned by the session user');
    }//end testUpsertPolicyForwardsEveryFieldAndEchoesTheStoredPolicy()


    /**
     * Omitted optional fields must be forwarded as their documented defaults —
     * a null max age (reminder-only) and an empty reminder list.
     *
     * @return void
     */
    public function testUpsertPolicyForwardsTheDocumentedDefaultsWhenFieldsAreOmitted(): void
    {
        $controller = $this->controller('alice');

        $policy = new ExpiryPolicy();
        $policy->setId('a4000000-0000-4000-8000-0000000000a4');
        $policy->setOwnerId('alice');
        $policy->setScope('folder');
        $policy->setScopeId('b4000000-0000-4000-8000-0000000000b4');
        $policy->setCreatedBy('alice');

        $this->rotationService->expects($this->once())
            ->method('upsertPolicy')
            ->with('alice', 'folder', 'b4000000-0000-4000-8000-0000000000b4', null, [])
            ->willReturn($policy);

        $data = $controller->upsertPolicy(
            scope: 'folder',
            scopeId: 'b4000000-0000-4000-8000-0000000000b4'
        )->getData();

        $this->assertSame('folder', $data['scope']);
        $this->assertNull($data['maxAgeDays']);
    }//end testUpsertPolicyForwardsTheDocumentedDefaultsWhenFieldsAreOmitted()


    /**
     * A policy the service refuses answers 400 carrying the refusal reason,
     * never a success envelope.
     *
     * @return void
     */
    public function testUpsertPolicyReportsTheServiceRefusalAsABadRequest(): void
    {
        $controller = $this->controller('alice');

        $this->rotationService->expects($this->once())
            ->method('upsertPolicy')
            ->willThrowException(new InvalidArgumentException('scope must be one of: type, folder'));

        $response = $controller->upsertPolicy(scope: 'galaxy', scopeId: 'password');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(
            ['message' => 'scope must be one of: type, folder'],
            $response->getData(),
            'the caller must be told which field was refused and why'
        );
    }//end testUpsertPolicyReportsTheServiceRefusalAsABadRequest()


    /**
     * An anonymous caller gets 401 and no policy is written.
     *
     * @return void
     */
    public function testUpsertPolicyRefusesAnAnonymousCaller(): void
    {
        $controller = $this->controller(null);

        $this->rotationService->expects($this->never())->method('upsertPolicy');

        $response = $controller->upsertPolicy(scope: 'type', scopeId: 'password', maxAgeDays: 30);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Unauthorized'], $response->getData());
    }//end testUpsertPolicyRefusesAnAnonymousCaller()


    // ---------------------------------------------------------------
    // rotation#destroyPolicy — DELETE /api/v1/expiry-policies/{id}
    // ---------------------------------------------------------------


    /**
     * The delete must name the routed policy and the session owner, and only
     * then report the deletion.
     *
     * @return void
     */
    public function testDestroyPolicyDeletesTheRoutedPolicyForTheSessionOwner(): void
    {
        $controller = $this->controller('alice');

        $this->rotationService->expects($this->once())
            ->method('deletePolicy')
            ->with('a5000000-0000-4000-8000-0000000000a5', 'alice');

        $response = $controller->destroyPolicy(id: 'a5000000-0000-4000-8000-0000000000a5');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['deleted' => true], $response->getData());
    }//end testDestroyPolicyDeletesTheRoutedPolicyForTheSessionOwner()


    /**
     * A policy the caller may not delete answers 404 with the service's reason,
     * not a false "deleted".
     *
     * @return void
     */
    public function testDestroyPolicyReportsAnUnknownPolicyAsNotFound(): void
    {
        $controller = $this->controller('alice');

        $this->rotationService->expects($this->once())
            ->method('deletePolicy')
            ->with('a6000000-0000-4000-8000-0000000000a6', 'alice')
            ->willThrowException(new InvalidArgumentException('Policy not found'));

        $response = $controller->destroyPolicy(id: 'a6000000-0000-4000-8000-0000000000a6');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['message' => 'Policy not found'], $response->getData());
        $this->assertArrayNotHasKey(
            'deleted',
            $response->getData(),
            'a refused delete must never carry the success key'
        );
    }//end testDestroyPolicyReportsAnUnknownPolicyAsNotFound()


    /**
     * An anonymous caller gets 401 and nothing is deleted.
     *
     * @return void
     */
    public function testDestroyPolicyRefusesAnAnonymousCaller(): void
    {
        $controller = $this->controller(null);

        $this->rotationService->expects($this->never())->method('deletePolicy');

        $response = $controller->destroyPolicy(id: 'a5000000-0000-4000-8000-0000000000a5');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Unauthorized'], $response->getData());
    }//end testDestroyPolicyRefusesAnAnonymousCaller()


    // ---------------------------------------------------------------
    // rotation#flags — GET /api/v1/rotation-flags
    // ---------------------------------------------------------------


    /**
     * The flag list must be owner-scoped and each row enriched with the
     * secret's plaintext name, looked up by that row's OWN secret id.
     *
     * @return void
     */
    public function testFlagsEnrichesEachRowWithItsOwnSecretsName(): void
    {
        $controller = $this->controller('alice');

        $breachFlag = new RotationFlag();
        $breachFlag->setId('c1000000-0000-4000-8000-0000000000c1');
        $breachFlag->setSecretId('d1000000-0000-4000-8000-0000000000d1');
        $breachFlag->setReason('user_flagged');
        $breachFlag->setStatus('open');
        $breachFlag->setFlaggedAt(new DateTime('2026-08-01T09:00:00+00:00'));
        $breachFlag->setFlaggedBy('alice');

        $policyFlag = new RotationFlag();
        $policyFlag->setId('c2000000-0000-4000-8000-0000000000c2');
        $policyFlag->setSecretId('d2000000-0000-4000-8000-0000000000d2');
        $policyFlag->setReason('policy_expiry');
        $policyFlag->setStatus('open');
        $policyFlag->setFlaggedAt(new DateTime('2026-08-02T09:00:00+00:00'));

        $this->rotationService->expects($this->once())
            ->method('openFlags')
            ->with('alice')
            ->willReturn([$breachFlag, $policyFlag]);

        $names = [
            'd1000000-0000-4000-8000-0000000000d1' => 'GitHub token',
            'd2000000-0000-4000-8000-0000000000d2' => 'SMTP password',
        ];

        $this->secretService->expects($this->exactly(2))
            ->method('findOwned')
            ->willReturnCallback(
                function (string $id, string $userId) use ($names): Secret {
                    $this->assertSame('alice', $userId, 'the enrichment lookup must stay owner-scoped');
                    $this->assertArrayHasKey($id, $names, 'each row must be looked up by its own secret id');

                    return $this->secret(id: $id, name: $names[$id]);
                }
            );

        $response = $controller->flags();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(2, $data);
        $this->assertSame('c1000000-0000-4000-8000-0000000000c1', $data[0]['id']);
        $this->assertSame('user_flagged', $data[0]['reason']);
        $this->assertSame('open', $data[0]['status']);
        $this->assertSame('2026-08-01T09:00:00+00:00', $data[0]['flaggedAt']);
        $this->assertSame('GitHub token', $data[0]['secretName']);
        $this->assertSame('policy_expiry', $data[1]['reason']);
        $this->assertSame('SMTP password', $data[1]['secretName']);
    }//end testFlagsEnrichesEachRowWithItsOwnSecretsName()


    /**
     * A flag whose secret the caller can no longer read must still be listed,
     * with an empty name — dropping the row would hide an open rotation duty.
     *
     * @return void
     */
    public function testFlagsKeepsARowWhoseSecretIsUnreadableWithAnEmptyName(): void
    {
        $controller = $this->controller('alice');

        $flag = new RotationFlag();
        $flag->setId('c3000000-0000-4000-8000-0000000000c3');
        $flag->setSecretId('d3000000-0000-4000-8000-0000000000d3');
        $flag->setReason('suite_compromise');
        $flag->setStatus('open');

        $this->rotationService->expects($this->once())
            ->method('openFlags')
            ->with('alice')
            ->willReturn([$flag]);

        $this->secretService->expects($this->once())
            ->method('findOwned')
            ->with('d3000000-0000-4000-8000-0000000000d3', 'alice')
            ->willThrowException(new NotFoundException('no such secret'));

        $data = $controller->flags()->getData();

        $this->assertCount(1, $data, 'the flag must survive an unreadable secret');
        $this->assertSame('c3000000-0000-4000-8000-0000000000c3', $data[0]['id']);
        $this->assertSame('', $data[0]['secretName'], 'the name is withheld, not guessed');
    }//end testFlagsKeepsARowWhoseSecretIsUnreadableWithAnEmptyName()


    /**
     * An anonymous caller gets 401 and no flag is read.
     *
     * @return void
     */
    public function testFlagsRefusesAnAnonymousCaller(): void
    {
        $controller = $this->controller(null);

        $this->rotationService->expects($this->never())->method('openFlags');
        $this->secretService->expects($this->never())->method('findOwned');

        $response = $controller->flags();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Unauthorized'], $response->getData());
    }//end testFlagsRefusesAnAnonymousCaller()


    // ---------------------------------------------------------------
    // rotation#dismissFlag — POST /api/v1/rotation-flags/{id}/dismiss
    // ---------------------------------------------------------------


    /**
     * The dismissal must name the routed flag and the session owner.
     *
     * @return void
     */
    public function testDismissFlagDismissesTheRoutedFlagForTheSessionOwner(): void
    {
        $controller = $this->controller('alice');

        $this->rotationService->expects($this->once())
            ->method('dismiss')
            ->with('c4000000-0000-4000-8000-0000000000c4', 'alice');

        $response = $controller->dismissFlag(id: 'c4000000-0000-4000-8000-0000000000c4');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['dismissed' => true], $response->getData());
    }//end testDismissFlagDismissesTheRoutedFlagForTheSessionOwner()


    /**
     * A flag the caller may not dismiss answers 404 with the service's reason —
     * never a false "dismissed", which would clear the duty from the UI while
     * the flag stayed open.
     *
     * @return void
     */
    public function testDismissFlagReportsAnUnknownFlagAsNotFound(): void
    {
        $controller = $this->controller('alice');

        $this->rotationService->expects($this->once())
            ->method('dismiss')
            ->with('c5000000-0000-4000-8000-0000000000c5', 'alice')
            ->willThrowException(new InvalidArgumentException('Flag not found'));

        $response = $controller->dismissFlag(id: 'c5000000-0000-4000-8000-0000000000c5');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['message' => 'Flag not found'], $response->getData());
        $this->assertArrayNotHasKey('dismissed', $response->getData());
    }//end testDismissFlagReportsAnUnknownFlagAsNotFound()


    /**
     * An anonymous caller gets 401 and nothing is dismissed.
     *
     * @return void
     */
    public function testDismissFlagRefusesAnAnonymousCaller(): void
    {
        $controller = $this->controller(null);

        $this->rotationService->expects($this->never())->method('dismiss');

        $response = $controller->dismissFlag(id: 'c4000000-0000-4000-8000-0000000000c4');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Unauthorized'], $response->getData());
    }//end testDismissFlagRefusesAnAnonymousCaller()


}//end class
