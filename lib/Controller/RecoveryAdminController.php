<?php

/**
 * Keepiq RecoveryAdminController
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
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\RecoveryAudit;
use OCA\Keepiq\Service\RecoveryEnrolmentService;
use OCA\Keepiq\Service\RecoveryKeyService;
use OCA\Keepiq\Service\RecoveryPolicyService;
use OCA\Keepiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Administrator settings of organisation account recovery
 * (crypto-organisation-account-recovery task 1.2, 2.3). Admin only; every
 * change also needs a fresh Nextcloud password confirmation.
 */
class RecoveryAdminController extends Controller {

	/**
	 * Constructor for RecoveryAdminController.
	 *
	 * @param IRequest                 $request     The request
	 * @param RecoveryPolicyService    $policy      The policy service
	 * @param RecoveryKeyService       $keys        The recovery keys
	 * @param RecoveryEnrolmentService $enrolments  The enrolments (suite warning)
	 * @param RecoveryAudit            $audit       The audit trail
	 * @param IUserSession             $userSession The user session
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private RecoveryPolicyService $policy,
		private RecoveryKeyService $keys,
		private RecoveryEnrolmentService $enrolments,
		private RecoveryAudit $audit,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The policy, officers, threshold and the active key's fingerprint.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function show(): JSONResponse {
		$key    = $this->keys->activeKey();
		$active = null;
		if ($key !== null) {
			$active = [
				'id' => $key->getId(),
				'fingerprint' => $key->getFingerprint(),
				'createdBy' => $key->getCreatedBy(),
				'createdAt' => $key->getCreatedAt()?->format('c'),
			];
		}

		return new JSONResponse(data: $this->policy->settings() + ['activeKey' => $active]);
	}//end show()

	/**
	 * Set the policy, officers and threshold.
	 *
	 * @param string   $policy    The policy
	 * @param string[] $officers  The officer user ids
	 * @param int      $threshold The approval threshold
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[PasswordConfirmationRequired]
	public function update(string $policy = 'off', array $officers = [], int $threshold = 1): JSONResponse {
		try {
			$settings = $this->policy->update(policy: $policy, officers: $officers, threshold: $threshold);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$this->audit->record(
			actorId: (string)$this->userSession->getUser()?->getUID(),
			eventType: RecoveryAudit::SETTINGS_CHANGED,
			objectId: 'settings',
			metadata: [
				'policy' => $settings['policy'],
				'threshold' => $settings['threshold'],
				'officerCount' => count($settings['officers']),
			]
		);

		return new JSONResponse(data: $settings);
	}//end update()

	/**
	 * Retire a recovery key.
	 *
	 * @param string $id The key
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[PasswordConfirmationRequired]
	public function retireKey(string $id): JSONResponse {
		try {
			$this->keys->retire(recoveryKeyId: $id, adminUid: (string)$this->userSession->getUser()?->getUID());
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['status' => 'retired']);
	}//end retireKey()

	/**
	 * Whether a suite's owner is enrolled through that suite, for the warning
	 * before a force-revocation.
	 *
	 * @param string $suiteId The suite
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-force-revocation-warns-about-enrolled-users
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function enrolled(string $suiteId = ''): JSONResponse {
		return new JSONResponse(data: ['enrolled' => $this->enrolments->isSuiteEnrolled(suiteId: $suiteId)]);
	}//end enrolled()
}//end class
