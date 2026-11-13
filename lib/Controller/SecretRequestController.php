<?php

/**
 * Keepiq Secret Request Controller
 *
 * Authenticated API controller for SecretRequest CRUD (scaffold). The
 * #[PublicPage] two-phase fill-in endpoint is deferred to the full
 * implement-secret-requests build cycle.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use DateTime;
use Exception;
use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Service\SecretRequestService;
use OCA\Keepiq\Support\MessageResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for SecretRequest CRUD.
 */
class SecretRequestController extends OCSController {
	/**
	 * Creation path: a plain request against the recipient's suite.
	 *
	 * @var string
	 */
	private const MODE_PLAIN = 'plain';

	/**
	 * Creation path: a re-request of an already-filled secret.
	 *
	 * @var string
	 */
	private const MODE_RE_REQUEST = 're_request';

	/**
	 * Creation path: a request owned by an application.
	 *
	 * @var string
	 */
	private const MODE_APPLICATION = 'application';

	/**
	 * A FRESH request: no Secret named, so the system creates the unfilled
	 * placeholder itself. This is what a person doing it for the first time
	 * takes — they have nothing to point at yet, and requiring them to invent a
	 * key for the credential they are asking for is the defect this closes.
	 *
	 * @var string
	 */
	private const MODE_FRESH = 'fresh';

	/**
	 * Constructor for SecretRequestController.
	 *
	 * @param IRequest $request The request object
	 * @param SecretRequestService $service The secret-request service
	 * @param IUserSession $session The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private SecretRequestService $service,
		private IUserSession $session,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List secret requests created by the current user.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$requests = $this->service->listByUser($user->getUID());

		return new JSONResponse(
			data: array_map(static fn ($r) => $r->jsonSerialize(), $requests)
		);
	}//end index()

	/**
	 * List secret requests for a given Secret — owner-only.
	 *
	 * @param string $secretId The Secret ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-3.8
	 */
	#[NoAdminRequired]
	public function listBySecret(string $secretId): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$requests = $this->service->listBySecret(secretId: $secretId, userId: $user->getUID());
		} catch (InvalidArgumentException $e) {
			return new MessageResponse(error: $e, status: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(
			data: array_map(static fn ($r) => $r->jsonSerialize(), $requests)
		);
	}//end listBySecret()

	/**
	 * Create a new pending secret request.
	 *
	 * @param string $secretId The Secret ID (unfilled or to-be-overwritten)
	 * @param string $encryptionSuiteId The recipient's active suite ID
	 * @param array<string> $requestedFields Field names to be filled in
	 * @param bool $isReRequest Whether this is a re-request
	 * @param string|null $expiresAt Optional ISO-8601 expiry
	 * @param string|null $applicationId Optional owning application ID
	 * @param string|null $name Name for the Secret a FRESH request creates
	 * @param string|null $folderId Optional folder for that created Secret
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-4.1
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $isReRequest is a field of the POST
	 *   body that the Nextcloud router binds by name, not an internal switch a caller
	 *   chooses. Splitting it into two methods would split the route, changing the HTTP
	 *   contract; the discriminator therefore stays and the dispatch to createReRequest()
	 *   vs create() is made explicit below.
	 */
	#[NoAdminRequired]
	public function create(
		string $secretId = '',
		string $encryptionSuiteId = '',
		array $requestedFields = [],
		bool $isReRequest = false,
		?string $expiresAt = null,
		?string $applicationId = null,
		?string $name = null,
		?string $folderId = null,
	): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// A re-request overwrites values in an existing Secret, so it cannot be
		// asked for without naming one. Refused explicitly rather than left to
		// fail deeper with a generic 'secretId is required', because the two
		// flows differing by required input is what keeps them distinct.
		if ($isReRequest === true && $secretId === '') {
			return new JSONResponse(
				data: ['message' => 'A re-request requires secretId'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$entity = $this->dispatchCreate(
				mode: $this->createMode(secretId: $secretId, isReRequest: $isReRequest, applicationId: $applicationId),
				secretId: $secretId,
				encryptionSuiteId: $encryptionSuiteId,
				requestedFields: $requestedFields,
				expiry: $this->parseExpiry(expiresAt: $expiresAt),
				applicationId: (string)$applicationId,
				userId: $user->getUID(),
				name: $name,
				folderId: $folderId
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: $this->refusalStatus(exception: $e)
			);
		}//end try

		return new JSONResponse(data: $entity->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Resolve the three creation paths to one explicit mode, so the dispatcher
	 * is a plain lookup rather than a flag-driven branch. An applicationId wins
	 * over the re-request discriminator.
	 *
	 * @param string $secretId The Secret named in the request, or ''
	 * @param bool $isReRequest Whether the request is a re-request
	 * @param string|null $applicationId The owning application ID, if any
	 *
	 * @return string One of the MODE_* constants
	 */
	private function createMode(string $secretId, bool $isReRequest, ?string $applicationId): string {
		return match (true) {
			($applicationId !== null && $applicationId !== '') => self::MODE_APPLICATION,
			($isReRequest === true) => self::MODE_RE_REQUEST,
			// No Secret named and not a re-request: the system creates one.
			($secretId === '') => self::MODE_FRESH,
			default => self::MODE_PLAIN,
		};
	}//end createMode()

	/**
	 * Map a service refusal to its HTTP status: 403, 404 and 409 pass
	 * through, anything else is a 400.
	 *
	 * @param InvalidArgumentException $exception The service refusal
	 *
	 * @return int The HTTP status
	 */
	private function refusalStatus(InvalidArgumentException $exception): int {
		$code = $exception->getCode();
		if ($code === 403 || $code === 404 || $code === 409) {
			return $code;
		}

		return Http::STATUS_BAD_REQUEST;
	}//end refusalStatus()

	/**
	 * Parse the optional expiry timestamp. An unparseable value surfaces as
	 * an InvalidArgumentException, which the caller already maps to 400.
	 *
	 * @param string|null $expiresAt The ISO-8601 expiry, or null/'' for none
	 *
	 * @return DateTime|null
	 *
	 * @throws InvalidArgumentException When the timestamp cannot be parsed.
	 */
	private function parseExpiry(?string $expiresAt): ?DateTime {
		if ($expiresAt === null || $expiresAt === '') {
			return null;
		}

		try {
			return new DateTime($expiresAt);
		} catch (Exception) {
			throw new InvalidArgumentException(message: 'Invalid expiry timestamp');
		}
	}//end parseExpiry()

	/**
	 * Route a create to the application, re-request or plain service path.
	 *
	 * @param string $mode One of the MODE_* constants
	 * @param string $secretId The Secret ID
	 * @param string $encryptionSuiteId The recipient's active suite ID
	 * @param array<string> $requestedFields Field names to be filled in
	 * @param DateTime|null $expiry Parsed expiry, or null
	 * @param string $applicationId Owning application ID ('' unless
	 *                              $mode is MODE_APPLICATION)
	 * @param string $userId The requesting user
	 * @param string|null $name Name for a FRESH request's created Secret
	 * @param string|null $folderId Folder for that created Secret
	 *
	 * @return \OCA\Keepiq\Db\SecretRequest
	 *
	 * @throws InvalidArgumentException Propagated from the service.
	 */
	private function dispatchCreate(
		string $mode,
		string $secretId,
		string $encryptionSuiteId,
		array $requestedFields,
		?DateTime $expiry,
		string $applicationId,
		string $userId,
		?string $name = null,
		?string $folderId = null,
	): \OCA\Keepiq\Db\SecretRequest {
		if ($mode === self::MODE_APPLICATION) {
			return $this->service->createForApplication(
				secretId: $secretId,
				applicationId: $applicationId,
				requestedFields: $requestedFields,
				expiresAt: $expiry,
				userId: $userId,
			);
		}

		if ($mode === self::MODE_FRESH) {
			return $this->service->createForUserVault(
				userId: $userId,
				requestedFields: $requestedFields,
				name: $name,
				folderId: $folderId,
				expiresAt: $expiry,
			);
		}

		if ($mode === self::MODE_RE_REQUEST) {
			return $this->service->createReRequest(
				secretId: $secretId,
				requestedFields: $requestedFields,
				expiresAt: $expiry,
				userId: $userId,
			);
		}

		return $this->service->create(
			secretId: $secretId,
			encryptionSuiteId: $encryptionSuiteId,
			requestedFields: $requestedFields,
			isReRequest: false,
			expiresAt: $expiry,
			userId: $userId,
		);
	}//end dispatchCreate()

	/**
	 * Approve a pending secret request.
	 *
	 * @param string $id The request ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function approve(string $id): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$entity = $this->service->approve(requestId: $id, userId: $user->getUID());
		} catch (InvalidArgumentException $e) {
			return new MessageResponse(error: $e, status: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $entity->jsonSerialize());
	}//end approve()

	/**
	 * Decline a pending secret request.
	 *
	 * @param string $id The request ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function decline(string $id): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$entity = $this->service->decline(requestId: $id, userId: $user->getUID());
		} catch (InvalidArgumentException $e) {
			return new MessageResponse(error: $e, status: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $entity->jsonSerialize());
	}//end decline()

	/**
	 * Revoke (cascade-delete) all requests for the secret bound to $id.
	 *
	 * Scaffold-level revoke: deletes the row through the mapper by relying
	 * on the service's owned-request lookup; the full revoke semantics
	 * (delete unfilled Secret with new requests, keep Secret on
	 * re-requests) ship with the dedicated build cycle.
	 *
	 * @param string $id The request ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$entity = $this->service->decline(requestId: $id, userId: $user->getUID());
		} catch (InvalidArgumentException $e) {
			return new MessageResponse(error: $e, status: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(data: ['status' => 'deleted', 'request' => $entity->jsonSerialize()]);
	}//end destroy()
}//end class
