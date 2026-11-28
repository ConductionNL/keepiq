<?php

/**
 * Keepiq Secret Update Controller
 *
 * `PUT /api/v1/secrets/{id}`: a partial update of one secret. Encrypted fields
 * are passed through as ciphertext; the server never decrypts them (ADR-003).
 * An offline edit names the version it was made from (`baseUpdatedAt`); when
 * the secret changed since, nothing is written and the answer is 409 with
 * the current row (offline-edit-queue). An update without a base tries once
 * more when a save lands while it is processed.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\ShareSaveRequiredException;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Exception\WriteLockedException;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Service\SecretUrlService;
use OCA\Keepiq\Kernel\Contracts\SecretServiceInterface;
use OCA\Keepiq\Service\PrivilegedAccess\PrivilegedCopyGate;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Updates one secret.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-update-secret
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thin transport layer: each
 *   type is a request, a response or a refusal it maps to a status, and the
 *   extra website addresses of a login (vault-website-addresses) add one.
 */
class SecretUpdateController extends OCSController {
	/**
	 * HTTP 423 Locked status code.
	 *
	 * @var int
	 */
	private const STATUS_LOCKED = 423;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object
	 * @param SecretServiceInterface $secretService The secret service
	 * @param IUserSession $userSession The user session
	 * @param SecretUrlService|null $urls The extra website addresses of a login
	 * @param PrivilegedCopyGate|null $privilegedGate Withholds a privileged copy outside its approved window
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private SecretServiceInterface $secretService,
		private IUserSession $userSession,
		private ?SecretUrlService $urls = null,
		private ?PrivilegedCopyGate $privilegedGate = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Update a secret. Only the fields present in the request change: `name`,
	 * `url`, `typeId`, `folderId`, the ciphertext `key`, `login`, `additionalFields`,
	 * `totp` and `passkeys`, plus `mergedPending` (request-filled blobs the client merged, keepiq#750)
	 * and `baseUpdatedAt` (the version an offline edit was made from).
	 * A field the request leaves out keeps its stored value, and an empty string clears it.
	 * A JSON null counts as left out, as Nextcloud's getParam() reads it.
	 * A value change of a shared secret answers 409 `share_save_required`: it
	 * goes through the share-save, which writes every copy (keepiq#1300).
	 *
	 * @param string $id The secret ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-update-secret
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-value-change-of-a-shared-secret-goes-through-the-share-save
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			// The extra addresses are checked before anything is written (vault-website-addresses).
			$extraUrls = $this->extraUrls(id: $id, userId: $user->getUID());
			$secret = $this->updateOnCurrent(id: $id, changes: $this->requestedChanges(), userId: $user->getUID());
			// A privileged copy outside its window is returned without its
			// ciphertext (capability-requirements-from-the-matrix 3.1).
			$data = $this->serialised(secret: $secret, userId: $user->getUID());
			if ($extraUrls !== null && $this->urls !== null) {
				$data['additionalUrls'] = $this->urls->replace(secret: $secret, userId: $user->getUID(), urls: $extraUrls);
			}
		} catch (StaleWriteException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage(), 'current' => $this->serialised(secret: $e->getCurrent(), userId: $user->getUID())],
				statusCode: Http::STATUS_CONFLICT
			);
		} catch (ShareSaveRequiredException $e) {
			$code = ShareSaveRequiredException::CODE;
			return new JSONResponse(data: ['error' => $code, 'code' => $code, 'message' => $e->getMessage()], statusCode: Http::STATUS_CONFLICT);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return $this->forbidden(exception: $e);
		} catch (WriteLockedException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: self::STATUS_LOCKED);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try

		return new JSONResponse(data: $data);
	}//end update()

	/**
	 * The 403 of a refused update. A vault policy refusal carries its code
	 * (admin-vault-policies D4).
	 *
	 * @param ForbiddenException $exception The refusal
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-update-secret
	 */
	private function forbidden(ForbiddenException $exception): JSONResponse {
		$body = ['message' => $exception->getMessage()];
		if ($exception->policyCode() !== null) {
			$body['code'] = $exception->policyCode();
		}

		return new JSONResponse(data: $body, statusCode: Http::STATUS_FORBIDDEN);
	}//end forbidden()

	/**
	 * The extra addresses the request carries, normalised, or null when it
	 * carries none. Only the owner of a login changes them; a recipient's
	 * copy is refused before the update writes anything.
	 *
	 * @param string $id     The secret ID
	 * @param string $userId The requesting user
	 *
	 * @return list<string>|null
	 *
	 * @throws InvalidArgumentException When the list cannot be stored
	 * @throws ForbiddenException When the login is not the caller's own
	 * @throws NotFoundException When the secret does not exist
	 *
	 * @spec openspec/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
	 */
	private function extraUrls(string $id, string $userId): ?array {
		$urls = $this->request->getParam('additionalUrls');
		if ($urls === null || $this->urls === null) {
			return null;
		}

		$list = $this->urls->normalise(urls: $urls);
		$secret = $this->secretService->get($id, $userId);
		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
			throw new ForbiddenException(message: 'Only the owner of a login changes its addresses');
		}

		return $list;
	}//end extraUrls()

	/**
	 * Run the update. One with a base is refused at once when the secret
	 * moved on. One without a base is refused only by a save that lands
	 * between the server's read and its write; it runs once more on the row
	 * as it is now, which keeps that save as a version, and a second
	 * refusal stands.
	 *
	 * @param string $id The secret ID
	 * @param array<string,mixed> $changes The changes the request carries
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return Secret
	 *
	 * @throws StaleWriteException When the secret moved on from the base, or twice during the update
	 * @throws ShareSaveRequiredException When a value change must go through the share-save
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-value-change-of-a-shared-secret-goes-through-the-share-save
	 */
	private function updateOnCurrent(string $id, array $changes, string $userId): Secret {
		try {
			return $this->secretService->updateAlone(id: $id, data: $changes, userId: $userId);
		} catch (StaleWriteException $e) {
			if (array_key_exists('baseUpdatedAt', $changes) === true) {
				throw $e;
			}

			return $this->secretService->updateAlone(id: $id, data: $changes, userId: $userId);
		}
	}//end updateOnCurrent()

	/**
	 * The changes the request carries: each updatable field that is present
	 * (an empty string clears it; getParam() reads a JSON null as absent), and
	 * the merge count and base version when given.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-update-secret
	 */
	private function requestedChanges(): array {
		$data = [];
		foreach (SecretServiceInterface::UPDATABLE_FIELDS as $field) {
			$value = $this->request->getParam($field, '__unset__');
			if ($value !== '__unset__') {
				$data[$field] = (string)$value;
			}
		}

		$mergedPending = $this->request->getParam('mergedPending');
		if ($mergedPending !== null) {
			$data['mergedPending'] = (int)$mergedPending;
		}

		// The re-prompt flag is a boolean, not a string field (crypto-item-reprompt D1).
		$reprompt = $this->request->getParam('reprompt');
		if ($reprompt !== null) {
			$data['reprompt'] = filter_var($reprompt, FILTER_VALIDATE_BOOLEAN);
		}

		$baseUpdatedAt = $this->request->getParam('baseUpdatedAt');
		if ($baseUpdatedAt !== null && $baseUpdatedAt !== '') {
			$data['baseUpdatedAt'] = (string)$baseUpdatedAt;
		}

		return $data;
	}//end requestedChanges()

	/**
	 * A row as it is returned to the caller: without its ciphertext when it
	 * is a privileged copy outside its approved window.
	 *
	 * @param Secret $secret The row
	 * @param string $userId The caller
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	private function serialised(Secret $secret, string $userId): array {
		return $this->privilegedGate?->serialisedFor(secret: $secret, userId: $userId) ?? $secret->jsonSerialize();
	}//end serialised()
}//end class
