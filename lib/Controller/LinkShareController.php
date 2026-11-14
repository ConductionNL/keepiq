<?php

/**
 * Keepiq Link Share Controller
 *
 * Authenticated API controller for link share CRUD operations performed
 * by the secret owner.
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
use OCA\Keepiq\Db\LinkShare;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Kernel\Contracts\EncryptionSuiteServiceInterface;
use OCA\Keepiq\Kernel\Contracts\LinkShareServiceInterface;
use OCA\Keepiq\Service\NewLinkShare;
use OCA\Keepiq\Support\MessageResponse;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Authenticated API controller for link share CRUD.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Each refusal of a link share
 *   (not found, invalid input, a read-only copy from another organisation)
 *   is its own exception class mapped to its own status; the thirteenth is
 *   the read-only refusal of sharing-federated-recipients task 3.4.
 */
class LinkShareController extends OCSController {
	/**
	 * Constructor for LinkShareController.
	 *
	 * @param IRequest $request The request object
	 * @param LinkShareServiceInterface $linkShareService The link share service
	 * @param EncryptionSuiteServiceInterface $suiteService The encryption suite service
	 * @param IUserSession $userSession The user session
	 * @param IURLGenerator $urlGenerator The URL generator
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private LinkShareServiceInterface $linkShareService,
		private EncryptionSuiteServiceInterface $suiteService,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List link shares for a secret owned by the current user.
	 *
	 * @param string $secretId The secret ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-link-sharing/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function index(string $secretId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$shares = $this->linkShareService->listBySecret(secretId: $secretId, userId: $user->getUID());

		return new JSONResponse(
			data: array_map(
				static fn ($share) => $share->jsonSerialize(),
				$shares
			)
		);
	}//end index()

	/**
	 * Create a link share for a secret owned by the current user.
	 *
	 * The browser performs all cryptography: it decrypts the secret, derives
	 * the AES key from a generated password via Argon2id, encrypts the
	 * snapshot, and posts the encrypted blob and salt here. The password is
	 * never transmitted.
	 *
	 * @param string $secretId The secret ID
	 * @param string $encryptedSecretSnapshot The AES-256-GCM blob (base64)
	 * @param string $argon2idSalt The base64-encoded Argon2id salt
	 * @param int $usageLimit The usage limit (1-10)
	 * @param string|null $expiresAt Optional ISO-8601 expiry
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-link-sharing/tasks.md#task-4.1
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) Parameter names mirror the API payload fields.
	 */
	#[NoAdminRequired]
	public function create(
		string $secretId,
		string $encryptedSecretSnapshot,
		string $argon2idSalt,
		int $usageLimit = 1,
		?string $expiresAt = null,
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		$encryptionSuiteId = $this->activeSuiteId(userId: $userId);
		if ($encryptionSuiteId === null) {
			return new JSONResponse(
				data: ['message' => 'No active encryption suite for the current user'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$expiry = $this->expiryOf(expiresAt: $expiresAt);
		} catch (Exception) {
			return new JSONResponse(
				data: ['message' => 'Invalid expiry timestamp'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$linkShare = $this->linkShareService->create(
				link: new NewLinkShare(
					secretId: $secretId,
					encryptedSnapshot: $encryptedSecretSnapshot,
					salt: $argon2idSalt,
					encryptionSuiteId: $encryptionSuiteId,
					usageLimit: $usageLimit,
					expiresAt: $expiry,
					userId: $userId
				)
			);
		} catch (DoesNotExistException | ForbiddenException | ShareScanPendingException | InvalidArgumentException $e) {
			return $this->createRefusal(exception: $e);
		}

		return new JSONResponse(data: $this->createdPayload(linkShare: $linkShare), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * The caller's active suite id, or null when they have none.
	 *
	 * @param string $userId The caller
	 *
	 * @return string|null
	 */
	private function activeSuiteId(string $userId): ?string {
		try {
			return $this->suiteService->getActiveSuite(ownerType: 'user', ownerId: $userId)->getId();
		} catch (DoesNotExistException) {
			return null;
		}
	}//end activeSuiteId()

	/**
	 * The response to a refused link-share create.
	 *
	 * @param DoesNotExistException|ForbiddenException|ShareScanPendingException|InvalidArgumentException $exception The refusal
	 *
	 * @return JSONResponse
	 */
	private function createRefusal(
		DoesNotExistException|ForbiddenException|ShareScanPendingException|InvalidArgumentException $exception
	): JSONResponse {
		if ($exception instanceof DoesNotExistException) {
			// Not the owner and not a delegate, or no such secret: the same
			// answer either way (keepiq#214).
			return new JSONResponse(data: ['message' => 'Secret not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($exception instanceof ForbiddenException) {
			// A copy from another organisation (sharing-federated-recipients task 3.4).
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		if ($exception instanceof ShareScanPendingException) {
			return new JSONResponse(
				data: ['error' => 'share_scan_pending', 'message' => $exception->getMessage()],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		return new MessageResponse(error: $exception, status: Http::STATUS_BAD_REQUEST);
	}//end createRefusal()

	/**
	 * The created link share with the URL its recipients open.
	 *
	 * The /public shell serves the SPA as #[PublicPage] — the
	 * authenticated dashboard.page route bounced account-less
	 * recipients to the Nextcloud login (fixed with ephemeral-send).
	 * A PATH under the shell (publicShell.pageCatchAll serves it), not
	 * the retired '#/share/link/' hash form: the SPA routes with
	 * createWebHistory, which never reads the fragment, so the hash
	 * form showed recipients the lock screen.
	 *
	 * @param LinkShare $linkShare The created link share
	 *
	 * @return array<string,mixed>
	 */
	private function createdPayload(LinkShare $linkShare): array {
		$linkUrl = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('keepiq.publicShell.page') . '/share/link/' . $linkShare->getToken()
		);

		$payload = $linkShare->jsonSerialize();
		$payload['linkUrl'] = $linkUrl;

		return $payload;
	}//end createdPayload()

	/**
	 * The expiry a create request names, or null for none.
	 *
	 * @param string|null $expiresAt The requested expiry timestamp
	 *
	 * @return DateTime|null
	 *
	 * @throws Exception When the timestamp cannot be parsed
	 */
	private function expiryOf(?string $expiresAt): ?DateTime {
		if ($expiresAt === null || $expiresAt === '') {
			return null;
		}

		return new DateTime($expiresAt);
	}//end expiryOf()

	/**
	 * Revoke (delete) a link share owned by the current user.
	 *
	 * @param string $id The link share ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-link-sharing/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->linkShareService->delete(id: $id, userId: $user->getUID());
		} catch (InvalidArgumentException $e) {
			return new MessageResponse(error: $e, status: Http::STATUS_FORBIDDEN);
		} catch (Exception $e) {
			return new MessageResponse(error: $e, status: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['status' => 'deleted']);
	}//end destroy()
}//end class
