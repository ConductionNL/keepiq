<?php

/**
 * Keepiq Application Secret Request Service
 *
 * Session-less secret-request creation for applications: the machine half of
 * the secret-requests capability.
 *
 * Split from SecretRequestService rather than added to it. That class is the
 * user-session surface — it takes a `userId`, locks against a user's vault and
 * records a user as the creator — and bolting a machine surface onto it pushed
 * it past its complexity budget while mixing two authentication models in one
 * place. Keeping them apart means the controller depends on exactly the surface
 * it uses, and neither set of guards can be reached by the wrong caller.
 *
 * Two entrypoints, one verification path:
 *   - `createForApplicationVault()` trusts an application id the CALLER has
 *     already proven (the machine route's Bearer token, checked by
 *     JwtAuthMiddleware before the handler runs).
 *   - `createForApplicationBySignedProof()` proves it here, for in-process
 *     callers, by delegating to JwtAuthService::verifyAssertion().
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Db\SecretRequestMapper;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Creation and listing of application-owned secret requests.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Fourteen against a limit of
 *   thirteen. Two authentication models meet here by design — a pre-verified
 *   application id from the machine route, and a signed proof verified through
 *   JwtAuthService — and each creation also needs the mapper, policy, outbox,
 *   write lock and the Secret-shell writer. Splitting the two entrypoints apart
 *   would duplicate the shell creation and rollback, which is the part that must
 *   not drift.
 */
class ApplicationSecretRequestService {
	/**
	 * Constructor.
	 *
	 * @param SecretRequestMapper $mapper The request mapper
	 * @param SecretRequestPolicy $policy Suite resolution + guard parity
	 * @param SecretRequestOutbox $outbox Audit + notification
	 * @param WriteLockService $writeLockService The compromise-recovery write lock
	 * @param LoggerInterface $logger The logger
	 * @param ContainerInterface $container Resolves SecretService / JwtAuthService lazily
	 *
	 * @return void
	 */
	public function __construct(
		private SecretRequestMapper $mapper,
		private SecretRequestPolicy $policy,
		private SecretRequestOutbox $outbox,
		private WriteLockService $writeLockService,
		private LoggerInterface $logger,
		private ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Create a request in an application's OWN vault, with no user session.
	 *
	 * The session-bound siblings (`create`, `createForApplication`) both require
	 * a `userId`, which an unattended application does not have — that is the
	 * gap this closes. Authority comes from the caller having already proven the
	 * application's identity (JWT-Bearer on the machine route, or a verified
	 * signed proof on the DI seam); this method takes the application id as
	 * established fact and never accepts a user.
	 *
	 * The Secret shell is created here rather than demanded from the caller,
	 * because an application has nothing to point at yet. Shell and request are
	 * created together, and a failure after the shell is written removes it: an
	 * empty orphan Secret in an application's vault is indistinguishable from a
	 * real unfilled credential, so it must not survive a failed creation.
	 *
	 * @param string $applicationId The application whose vault receives the request
	 * @param array<int,string> $requestedFields Field names being asked for
	 * @param string|null $name Optional secret name
	 * @param string|null $folderId Optional folder placement
	 * @param DateTime|null $expiresAt Optional fill-link expiry
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException When the application has no usable suite
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function createForApplicationVault(
		string $applicationId,
		array $requestedFields,
		?string $name = null,
		?string $folderId = null,
		?DateTime $expiresAt = null,
	): SecretRequest {
		if ($requestedFields === []) {
			throw new InvalidArgumentException(message: 'requestedFields is required', code: 400);
		}

		// Guard parity with token issuance: refuses a pending/rejected/deleted
		// application and a revoked/compromised suite, and yields the suite the
		// submitted values will be encrypted under.
		$suiteId = $this->policy->requireApplicationSuiteId(applicationId: $applicationId);

		// The application's own lock, not a user's: a rotation in the
		// application's vault must refuse new pending requests for the same
		// reason it refuses them for a user.
		$this->writeLockService->assertNotWriteLocked(
			ownerId: $applicationId,
			ownerType: 'application'
		);

		$secretService = $this->container->get(SecretService::class);

		$shell = $secretService->createByApplication(
			data: [
				'name' => ($name ?? 'Unfilled request'),
				'folderId' => $folderId,
				// An unfilled shell: the recipient supplies the value, and the
				// server never holds a plaintext one (ADR-003).
				'key' => '',
			],
			applicationId: $applicationId,
			// Without this the create fails with "A secret requires a name and a
			// key" and the whole machine surface is dead on the happy path. It
			// was invisible to every controller/service unit test because they
			// mock SecretService and so never reach its validation; a live
			// probe of POST /api/v1/app/secret-requests found it immediately.
			allowUnfilled: true
		);

		try {
			return $this->createInApplicationVault(
				secretId: $shell->getId(),
				applicationId: $applicationId,
				encryptionSuiteId: $suiteId,
				requestedFields: $requestedFields,
				expiresAt: $expiresAt
			);
		} catch (Throwable $exception) {
			// No orphan shell: it exists only to receive this request.
			try {
				$secretService->deleteByApplication(
					secretId: $shell->getId(),
					applicationId: $applicationId
				);
			} catch (Throwable $cleanupFailure) {
				$this->logger->error(
					'Keepiq: failed to remove the shell of a failed application request',
					[
						'secretId' => $shell->getId(),
						'applicationId' => $applicationId,
						'exception' => $cleanupFailure,
					]
				);
			}

			throw $exception;
		}//end try
	}//end createForApplicationVault()

	/**
	 * Create a request in an application's vault, authenticated by signed proof.
	 *
	 * The in-process seam for same-instance callers that prefer DI over loopback
	 * HTTP. It deliberately does NOT accept an application id as authority: an id
	 * is a public identifier, so trusting one would let any code in the process
	 * create requests in any application's vault. Authority is a signature over
	 * the application's REGISTERED certificate, which is the same bar the machine
	 * route clears via its Bearer token.
	 *
	 * Verification is delegated to JwtAuthService::verifyAssertion(), not
	 * reimplemented, so this path inherits every guard the token exchange
	 * enforces — claim acceptability and assertion lifetime, jti replay refusal,
	 * issuer-must-be-active (so pending, rejected and deleted applications are
	 * refused), and signature against the registered key. A second copy of those
	 * checks would eventually drift from the first, and the drift would be a
	 * silent authentication weakness.
	 *
	 * The application id comes from the VERIFIED `iss`, never from an argument,
	 * so the vault cannot be redirected by the caller.
	 *
	 * @param string $assertion The RS256/ES256 JWS compact serialization
	 * @param array<int,string> $requestedFields Field names being asked for
	 * @param string|null $name Optional secret name
	 * @param string|null $folderId Optional folder placement
	 * @param DateTime|null $expiresAt Optional fill-link expiry
	 *
	 * @return SecretRequest
	 *
	 * @throws RuntimeException When the proof is missing, invalid, replayed, or
	 *                          signed by a key that is not the application's
	 * @throws InvalidArgumentException When the field list is empty
	 *
	 * @orphaned-write-capability exclude The consumer is another app in another
	 *   repository. This seam exists so OpenConnector can create a request
	 *   in-process instead of over loopback HTTP (see the proposal's DI-seam
	 *   decision), so it has no in-repo caller by construction and will not
	 *   acquire one. Note the risk the gate is right about: an uncalled write
	 *   path is indistinguishable from dead code, and this session already found
	 *   one such method whose documented guarantee was false. What stands in for
	 *   a caller here is ApplicationSecretRequestServiceTest, which drives the
	 *   seam directly including every verification failure, plus
	 *   docs/integration-openconnector.md showing the exact call.
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function createForApplicationBySignedProof(
		string $assertion,
		array $requestedFields,
		?string $name = null,
		?string $folderId = null,
		?DateTime $expiresAt = null,
	): SecretRequest {
		if (trim($assertion) === '') {
			// An empty proof is the appId-only case: refused before anything
			// else happens, because there is nothing to verify.
			throw new RuntimeException(
				message: 'A signed assertion is required; an application id alone is not authority'
			);
		}

		$application = $this->container->get(JwtAuthService::class)
			->verifyAssertion(assertion: $assertion);

		return $this->createForApplicationVault(
			// From the verified `iss`, so the caller cannot choose the vault.
			applicationId: $application->getId(),
			requestedFields: $requestedFields,
			name: $name,
			folderId: $folderId,
			expiresAt: $expiresAt
		);
	}//end createForApplicationBySignedProof()

	/**
	 * The pending requests an application itself created.
	 *
	 * Exists so a fill-link is retrievable after creation: the token is returned
	 * once at creation, and an application that lost the response has no other
	 * way back to it.
	 *
	 * Scoping is structural rather than filtered-after-the-fact. Rows are keyed
	 * on `created_by = "application:<id>"`, a value only this service writes —
	 * no request body can influence it, so one application cannot enumerate
	 * another's. Requests a USER created against this application's vault are
	 * deliberately not listed: they are the user's to manage, and surfacing them
	 * here would widen what a machine token can see.
	 *
	 * @param string $applicationId The calling application
	 *
	 * @return array<int,SecretRequest> Pending requests, newest first
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function listPendingForApplicationVault(string $applicationId): array {
		if ($applicationId === '') {
			throw new InvalidArgumentException(message: 'applicationId is required', code: 400);
		}

		$rows = $this->mapper->findByApplication($applicationId);

		$pending = array_values(
			array_filter(
				$rows,
				static fn (SecretRequest $row): bool => $row->getStatus() === SecretRequest::STATUS_PENDING
			)
		);

		usort(
			$pending,
			static fn (SecretRequest $a, SecretRequest $b): int => ($b->getCreatedAt()?->getTimestamp() ?? 0)
				<=> ($a->getCreatedAt()?->getTimestamp() ?? 0)
		);

		return $pending;
	}//end listPendingForApplicationVault()

	/**
	 * Persist an application-owned request row.
	 *
	 * Split from the shell creation so the rollback above has exactly one
	 * failure point to guard, and kept separate from `create()` because that
	 * method's contract is user-session bound: it takes a `userId`, locks
	 * against a user's vault and records that user as the creator.
	 *
	 * @param string $secretId The shell Secret
	 * @param string $applicationId The owning application
	 * @param string $encryptionSuiteId The suite values will be encrypted under
	 * @param array<int,string> $requestedFields Field names being asked for
	 * @param DateTime|null $expiresAt Optional fill-link expiry
	 *
	 * @return SecretRequest
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	private function createInApplicationVault(
		string $secretId,
		string $applicationId,
		string $encryptionSuiteId,
		array $requestedFields,
		?DateTime $expiresAt,
	): SecretRequest {
		$entity = new SecretRequest();
		$entity->setId(Uuid::uuid4()->toString());
		$entity->setSecretId($secretId);
		$entity->setEncryptionSuiteId($encryptionSuiteId);
		$entity->setRequestedFields((string)json_encode(array_values($requestedFields)));
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setIsReRequest(false);
		$entity->setExpiresAt($expiresAt);
		// The actor is the application, not a user. `created_by` is a plain
		// string column, so the prefix keeps an application id from being read
		// as a Nextcloud user id by anything that consumes this field.
		$entity->setCreatedByApplication(applicationId: $applicationId);
		$entity->setCreatedAt(new DateTime());
		$entity->setToken(bin2hex(random_bytes(16)));

		$persisted = $this->mapper->insert($entity);

		// Exactly one audit event per successful creation, with the application
		// as actor. Dispatched after the insert so a failed write records
		// nothing, and fail-soft inside the outbox so audit never blocks the
		// creation it is describing.
		$this->outbox->recordCreatedByApplication(
			applicationId: $applicationId,
			requestId: $persisted->getId(),
			secretId: $secretId,
			requestedFieldCount: count($requestedFields)
		);

		$this->logger->info(
			'Created application-initiated secret request ' . $persisted->getId(),
			['app' => 'keepiq', 'applicationId' => $applicationId]
		);

		return $persisted;
	}//end createInApplicationVault()
}//end class
