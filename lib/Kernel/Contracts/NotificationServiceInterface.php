<?php

/**
 * Contract of NotificationService
 *
 * The public surface of NotificationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Keepiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/keepiq
 *
 * @spec openspec/specs/app-shell/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Kernel\Contracts;

/**
 * Contract of NotificationService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NotificationServiceInterface {

	/**
	 * Subject -> user-setting key map. A null value means the
	 * notification is unconditionally delivered (admin queue).
	 *
	 * @var array<string,string|null>
	 */
	public const SUBJECT_SETTING_MAP = [
		'secret_shared' => 'notify_shares',
		'share_request' => 'notify_shares',
		'share_request_result' => 'notify_shares',
		// A partner instance shared a secret (sharing-federated-recipients D4).
		'federated_share_received' => 'notify_shares',
		'group_member_added' => 'notify_group_shares',
		// A copy holder who joined a shared group can now overwrite the owner's
		// secret without approval: a change in who can write it, not a request.
		'group_member_gained_write' => 'notify_security',
		'secret_compromised' => 'notify_security',
		'request_fulfilled' => 'notify_requests',
		'app_pending' => null,
		// Emergency access — grantor is notified on a break-glass request (so
		// the veto window is actionable) and on actual access. Gated on the
		// existing security-notification category (add-emergency-access §4.2).
		'emergency_access_requested' => 'notify_security',
		'emergency_access_accessed' => 'notify_security',
		// Administrator compromise force-revoke (admin-suite-revocation):
		// the holders of copies of the revoked user's secrets, the grantors
		// whose vault the revoked key could open (keepiq#872), and the owner
		// whose emergency contacts the revoke deleted (keepiq#876).
		'shared_secret_compromised' => 'notify_security',
		'emergency_grantee_compromised' => 'notify_security',
		'emergency_access_cleared' => 'notify_security',
		// Team folder sharing (team-folder-sharing §4.2): fan-out share
		// to a recipient; group-join approval request to the owner.
		'team_folder_shared' => 'notify_shares',
		'team_folder_join_request' => 'notify_group_shares',
		// Automatic member confirmation (admin-auto-confirm-members D6): the
		// owner learns who confirmed whom, under the same group-share toggle.
		'team_folder_member_confirmed' => 'notify_group_shares',
		// Rotation & expiry (rotation-expiry-policies §4): approaching
		// expiry reminders and the overdue/rotation-due flag.
		'secret_expiring' => 'notify_security',
		'secret_rotation_due' => 'notify_security',
		// SIEM audit export (siem-audit-export §5.1): an operational
		// admin alert like app_pending — never user-suppressible.
		'siem_dead_letter' => null,
		// Certificate lifecycle (certificate-lifecycle §3.2): suite
		// certificate approaching notAfter.
		'certificate_expiring' => 'notify_security',
		// The CA root approaching expiry (keepiq#741): an operational admin
		// alert like siem_dead_letter, never user-suppressible.
		'ca_root_expiring' => null,
		// Honey credentials (honey-credentials §D3): a muted tripwire
		// is worthless — always pages, like app_pending.
		'honey_access' => null,
		// Shares that end by themselves (sharing-use-only-and-expiring-shares
		// D6): the holder a day ahead and when it ended; the owner when it ended.
		'share_access_ending' => 'notify_shares',
		'share_access_ended' => 'notify_shares',
		'share_access_ended_owner' => 'notify_shares',
		// New device approval (crypto-new-device-approval D5): someone
		// signed in as this user asks to open the vault. Always shown.
		'device_approval_requested' => null,
		// Organisation account recovery (crypto-organisation-account-recovery
		// 5.2): security events, never suppressible.
		'recovery_officer_named' => null,
		'recovery_requested' => null,
		'recovery_declined' => null,
		'recovery_ready' => null,
	];

	/**
	 * Dispatch a notification to a single Nextcloud user.
	 *
	 * @param string $subject The subject key (must be in SUBJECT_SETTING_MAP)
	 * @param string $recipientId The recipient Nextcloud user ID
	 * @param array<string,mixed> $params Subject-specific parameters (passed to the notifier)
	 * @param string|null $objectType Optional object type (e.g. 'secret', 'application')
	 * @param string|null $objectId Optional object ID
	 *
	 * @return bool True when the notification was queued, false when suppressed.
	 *
	 * @spec openspec/specs/user-settings/spec.md#requirement-notification-toggles-mvp
	 * @spec openspec/specs/user-sharing/spec.md#requirement-notification-on-share-received
	 */
	public function notify(
		string $subject,
		string $recipientId,
		array $params = [],
		?string $objectType = null,
		?string $objectId = null,
	): bool;
}//end interface
