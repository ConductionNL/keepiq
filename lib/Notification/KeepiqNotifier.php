<?php

/**
 * Keepiq Notifier
 *
 * INotifier implementation that renders Keepiq notification subjects
 * into a localised parsed payload (icon + subject + message + link).
 *
 * @category Notification
 * @package  OCA\Keepiq\Notification
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

namespace OCA\Keepiq\Notification;

use InvalidArgumentException;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Kernel\Contracts\NotificationServiceInterface;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders Keepiq notifications.
 *
 * The subject IDs here must match NotificationServiceInterface::SUBJECT_SETTING_MAP.
 * Each branch builds a short subject line, a longer message line and a
 * deep-link the user clicks to land on the affected secret / queue.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) 50 against a threshold of
 *   50. Nextcloud registers one INotifier per app, so every Keepiq subject
 *   renders here; the complexity is the sum of about twenty small branches,
 *   already split per subject group. The share-request and group-member
 *   approval actions (keepiq#747) pushed it to the threshold. Splitting the
 *   class would only move branches into a second class this one calls.
 */
class KeepiqNotifier implements INotifier {
	/**
	 * Constructor for KeepiqNotifier.
	 *
	 * @param IFactory $l10nFactory The L10N factory
	 * @param IURLGenerator $url The URL generator
	 *
	 * @return void
	 */
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
	) {
	}//end __construct()

	/**
	 * Identifier for the manager registration.
	 *
	 * @return string
	 */
	public function getID(): string {
		return AppIdentity::APP_ID;
	}//end getID()

	/**
	 * Human-readable name used by the Nextcloud notification settings.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Keepiq';
	}//end getName()

	/**
	 * Prepare a notification for display.
	 *
	 * @param INotification $notification The notification instance
	 * @param string $languageCode The user's language code
	 *
	 * @return INotification
	 *
	 * @throws UnknownNotificationException
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== AppIdentity::APP_ID) {
			throw new UnknownNotificationException();
		}

		$subj = $notification->getSubject();
		if (array_key_exists($subj, NotificationServiceInterface::SUBJECT_SETTING_MAP) === false) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(app: AppIdentity::APP_ID, lang: $languageCode);
		$params = $notification->getSubjectParameters();

		$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(AppIdentity::APP_ID, 'app.svg')));

		$rendering = new SubjectRendering(notification: $notification, subject: $subj, params: $params, l: $l);

		// Each renderer owns one family of subjects and reports whether it
		// recognised this one. The first renderer that claims the subject wins.
		$renderers = [
			fn (): bool => $this->renderSharingSubject(r: $rendering),
			fn (): bool => $this->renderGroupMemberSubject(r: $rendering),
			fn (): bool => $this->renderSecretLifecycleSubject(r: $rendering),
			fn (): bool => $this->renderAdminSubject(r: $rendering),
			fn (): bool => $this->renderVaultAccessSubject(r: $rendering),
			fn (): bool => $this->renderEmergencySubject(r: $rendering),
			fn (): bool => $this->renderDeviceApprovalSubject(r: $rendering),
			fn (): bool => $this->renderRecoverySubject(r: $rendering),
			fn (): bool => $this->renderAccessEndSubject(r: $rendering),
		];
		foreach ($renderers as $render) {
			if ($render() === true) {
				return $notification;
			}
		}

		throw new UnknownNotificationException();
	}//end prepare()

	/**
	 * Render the sharing-workflow subjects. All of them deep-link to a secret.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool True when this renderer recognised the subject.
	 */
	private function renderSharingSubject(SubjectRendering $r): bool {
		switch ($r->subject) {
			case 'secret_shared':
				$secretName = (string)($r->params['secret_name'] ?? $r->l->t('a secret'));
				$sharedBy = (string)($r->params['shared_by'] ?? $r->l->t('a user'));
				$r->notification->setParsedSubject((string)$r->l->t('Secret shared with you'));
				$r->notification->setParsedMessage(
					(string)$r->l->t('%1$s shared the secret "%2$s" with you.', [$sharedBy, $secretName])
				);
				$this->withSecretLink(notification: $r->notification, params: $r->params);
				return true;
			case 'federated_share_received':
				$secretName = (string)($r->params['secret_name'] ?? $r->l->t('a secret'));
				$sharedBy = (string)($r->params['shared_by'] ?? $r->l->t('a user'));
				$r->notification->setParsedSubject((string)$r->l->t('A secret from another organisation'));
				$r->notification->setParsedMessage(
					(string)$r->l->t('%1$s shared "%2$s" with you. Accept it under Incoming from other organisations.', [$sharedBy, $secretName])
				);
				$this->withAppLink(notification: $r->notification, path: 'incoming');
				return true;
			case 'share_request':
				return $this->renderShareRequest(r: $r);
			case 'share_request_result':
				return $this->renderShareRequestResult(r: $r);
		}//end switch

		return false;
	}//end renderSharingSubject()

	/**
	 * Render the subjects sent to the owner when someone joins a group their
	 * secret is shared with. Both deep-link to the owner's secret.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool True when this renderer recognised the subject.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-new-group-member-owner-notification
	 */
	private function renderGroupMemberSubject(SubjectRendering $r): bool {
		switch ($r->subject) {
			case 'group_member_added':
				// Sent to the owner, so secretId is the owner's own secret.
				$params = self::normaliseParams(params: $r->params, aliases: []);
				$groupId = (string)($params['group_id'] ?? '');
				$secretName = (string)($params['secret_name'] ?? $r->l->t('a secret'));
				$r->notification->setParsedSubject((string)$r->l->t('Group member added'));
				$r->notification->setParsedMessage(
					(string)$r->l->t('A new member joined the group "%1$s" — approve to share "%2$s".', [$groupId, $secretName])
				);
				$this->withSecretLink(notification: $r->notification, params: $params);
				$this->withGroupMemberActions(r: $r, params: $params);
				return true;
			case 'group_member_gained_write':
				// The member's copy already saves for everyone, so there is nothing to approve.
				$params = self::normaliseParams(params: $r->params, aliases: []);
				$newMemberId = (string)($params['new_member_id'] ?? $r->l->t('a user'));
				$groupId = (string)($params['group_id'] ?? '');
				$secretName = (string)($params['secret_name'] ?? $r->l->t('a secret'));
				$r->notification->setParsedSubject((string)$r->l->t('A group member can now change your secret'));
				$r->notification->setParsedMessage(
					(string)$r->l->t(
						'%1$s joined the group "%2$s" and can now change "%3$s" for everyone it is shared with. Revoke their share to stop this.',
						[$newMemberId, $groupId, $secretName]
					)
				);
				$this->withSecretLink(notification: $r->notification, params: $params);
				return true;
		}//end switch

		return false;
	}//end renderGroupMemberSubject()

	/**
	 * Render the organisation account recovery subjects
	 * (crypto-organisation-account-recovery 5.2). All link to the app.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool True when this renderer recognised the subject.
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
	 */
	private function renderRecoverySubject(SubjectRendering $r): bool {
		$texts = [
			'recovery_officer_named' => (string)$r->l->t('You are now an account recovery officer'),
			'recovery_requested' => (string)$r->l->t(
				'%s asks to recover their account. Compare the words with them before you approve.',
				[(string)($r->params['user'] ?? $r->l->t('A user'))]
			),
			'recovery_declined' => (string)$r->l->t('Your account recovery request was declined'),
			'recovery_ready' => (string)$r->l->t('Your account recovery is ready. Open Keepiq in the browser you asked from.'),
		];
		if (isset($texts[$r->subject]) === false) {
			return false;
		}

		$r->notification->setParsedSubject($texts[$r->subject]);
		try {
			$r->notification->setLink(
				$this->url->getAbsoluteURL($this->url->linkToRoute(AppIdentity::APP_ID . '.dashboard.page'))
			);
		} catch (InvalidArgumentException) {
			// The link is optional; the notification still says what happened.
		}

		return true;
	}//end renderRecoverySubject()

	/**
	 * Render a new device's request to open the vault
	 * (crypto-new-device-approval D5). Links to the app, where the unlocked
	 * vault shows the approval dialog.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool True when this renderer recognised the subject.
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
	 */
	private function renderDeviceApprovalSubject(SubjectRendering $r): bool {
		if ($r->subject !== 'device_approval_requested') {
			return false;
		}

		$label = (string)($r->params['device_label'] ?? '');
		if ($label === '') {
			$label = (string)$r->l->t('A device');
		}

		$r->notification->setParsedSubject((string)$r->l->t('A new device asks to open your vault'));
		$r->notification->setParsedMessage(
			(string)$r->l->t('%s asks to be approved. Only approve a device you are using right now.', [$label])
		);
		try {
			$r->notification->setLink(
				$this->url->getAbsoluteURL($this->url->linkToRoute(AppIdentity::APP_ID . '.dashboard.page'))
			);
		} catch (InvalidArgumentException) {
			// The link is optional; the notification still says what happened.
		}

		return true;
	}//end renderDeviceApprovalSubject()

	/**
	 * Render the end-of-access subjects of shares that end by themselves
	 * (sharing-use-only-and-expiring-shares D6).
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool True when this renderer recognised the subject.
	 *
	 * @spec openspec/specs/expiring-shares/spec.md#requirement-people-are-told-before-and-when-access-ends
	 */
	private function renderAccessEndSubject(SubjectRendering $r): bool {
		$secretName = (string)($r->params['secret_name'] ?? $r->l->t('a secret'));
		switch ($r->subject) {
			case 'share_access_ending':
				$r->notification->setParsedSubject((string)$r->l->t('Your access to "%s" ends tomorrow', [$secretName]));
				$this->withSecretLink(notification: $r->notification, params: $r->params);
				return true;
			case 'share_access_ended':
				$r->notification->setParsedSubject((string)$r->l->t('Your access to "%s" has ended', [$secretName]));
				return true;
			case 'share_access_ended_owner':
				$recipient = (string)($r->params['recipient'] ?? $r->l->t('a user'));
				$r->notification->setParsedSubject(
					(string)$r->l->t('%1$s no longer has access to "%2$s"', [$recipient, $secretName])
				);
				$message = (string)$r->l->t('%1$s could see this password. Rotate it if %1$s should no longer know it.', [$recipient]);
				if (($r->params['use_only'] ?? false) === true) {
					$message = (string)$r->l->t('%s could not view this password in Keepiq.', [$recipient]);
				}

				$r->notification->setParsedMessage($message);
				$this->withSecretLink(notification: $r->notification, params: $r->params);
				return true;
		}//end switch

		return false;
	}//end renderAccessEndSubject()

	/**
	 * Render the secret-lifecycle subjects. All of them deep-link to a secret.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool True when this renderer recognised the subject.
	 */
	private function renderSecretLifecycleSubject(SubjectRendering $r): bool {
		switch ($r->subject) {
			case 'secret_compromised':
				return $this->renderSecretCompromised(r: $r);
			case 'shared_secret_compromised':
				return $this->renderSharedSecretCompromised(r: $r);
			case 'request_fulfilled':
				$secretName = (string)($r->params['secret_name'] ?? $r->l->t('a secret'));
				$r->notification->setParsedSubject((string)$r->l->t('Secret request fulfilled'));
				$r->notification->setParsedMessage(
					(string)$r->l->t('Your request for "%s" has been filled in.', [$secretName])
				);
				$this->withSecretLink(notification: $r->notification, params: $r->params);
				return true;
			case 'secret_expiring':
				return $this->renderSecretExpiring(r: $r);
			case 'secret_rotation_due':
				return $this->renderSecretRotationDue(r: $r);
		}//end switch

		return false;
	}//end renderSecretLifecycleSubject()

	/**
	 * Render the administrator-facing subjects. All of them link to the admin section.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool True when this renderer recognised the subject.
	 */
	private function renderAdminSubject(SubjectRendering $r): bool {
		switch ($r->subject) {
			case 'app_pending':
				$appName = (string)($r->params['app_name'] ?? $r->l->t('an application'));
				$registeredBy = (string)($r->params['registered_by'] ?? $r->l->t('an external party'));
				$r->notification->setParsedSubject((string)$r->l->t('New application pending approval'));
				$r->notification->setParsedMessage(
					(string)$r->l->t('Application "%1$s" was registered by %2$s and is awaiting approval.', [$appName, $registeredBy])
				);
				$this->withAdminSectionLink(notification: $r->notification);
				return true;
			case 'siem_dead_letter':
				$sinkName = (string)($r->params['sink_name'] ?? $r->l->t('a SIEM sink'));
				$r->notification->setParsedSubject((string)$r->l->t('SIEM delivery failing'));
				$r->notification->setParsedMessage(
					(string)$r->l->t(
						'Audit events for SIEM sink "%1$s" could not be delivered and were dead-lettered. Check the sink configuration.',
						[$sinkName]
					)
				);
				$this->withAdminSectionLink(notification: $r->notification);
				return true;
			case 'ca_root_expiring':
				return $this->renderRootExpiring(r: $r);
		}//end switch

		return false;
	}//end renderAdminSubject()

	/**
	 * Render the vault-access subjects — team folders, decoys, certificates and
	 * emergency access. None of them carry a deep-link.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool True when this renderer recognised the subject.
	 */
	private function renderVaultAccessSubject(SubjectRendering $r): bool {
		switch ($r->subject) {
			case 'team_folder_shared':
				$sharedBy = (string)($r->params['sharedBy'] ?? $r->l->t('a user'));
				$r->notification->setParsedSubject((string)$r->l->t('Team folder shared with you'));
				$r->notification->setParsedMessage(
					(string)$r->l->t('%s shared a team folder with you. Its secrets are now in your vault.', [$sharedBy])
				);
				return true;
			case 'team_folder_member_confirmed':
				return $this->renderTeamFolderMembersConfirmed(r: $r);
			case 'team_folder_join_request':
				$newMemberId = (string)($r->params['newMemberId'] ?? $r->l->t('a user'));
				$joinGroupId = (string)($r->params['groupId'] ?? '');
				$r->notification->setParsedSubject((string)$r->l->t('Team folder join request'));
				$r->notification->setParsedMessage(
					(string)$r->l->t('%1$s joined the group "%2$s" — approve to share your team folder with them.', [$newMemberId, $joinGroupId])
				);
				return true;
			case 'honey_access':
				return $this->renderHoneyAccess(r: $r);
			case 'certificate_expiring':
				return $this->renderCertificateExpiring(r: $r);
		}//end switch

		return false;
	}//end renderVaultAccessSubject()

	/**
	 * Render the emergency-access subjects: requests, grants that were used,
	 * cleared and compromised contacts. None of them carry a deep-link.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool True when this renderer recognised the subject.
	 *
	 * @spec openspec/specs/emergency-access/spec.md
	 */
	private function renderEmergencySubject(SubjectRendering $r): bool {
		switch ($r->subject) {
			case 'emergency_access_requested':
				return $this->renderEmergencyAccessRequested(r: $r);
			case 'emergency_grantee_compromised':
				$granteeName = (string)($r->params['grantee_name'] ?? $r->params['granteeUserId'] ?? $r->l->t('a trusted contact'));
				$r->notification->setParsedSubject((string)$r->l->t('Emergency contact compromised'));
				$r->notification->setParsedMessage(
					(string)$r->l->t(
						'%s had approved emergency access to your vault. Their encryption key was revoked as compromised, so start a key rotation.',
						[$granteeName]
					)
				);
				return true;
			case 'emergency_access_cleared':
				return $this->renderEmergencyAccessCleared(r: $r);
			case 'emergency_access_accessed':
				$granteeName = (string)($r->params['grantee_name'] ?? $r->params['granteeUserId'] ?? $r->l->t('a trusted contact'));
				$r->notification->setParsedSubject((string)$r->l->t('Emergency access used'));
				$r->notification->setParsedMessage(
					(string)$r->l->t('%s accessed your vault through emergency access.', [$granteeName])
				);
				return true;
		}//end switch

		return false;
	}//end renderEmergencySubject()

	/**
	 * Render a share request to the owner, with Approve and Deny when it names everyone.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool Always true: the subject is recognised.
	 */
	private function renderShareRequest(SubjectRendering $r): bool {
		// Sent to the owner, so sourceSecretId is the owner's own secret.
		$params = self::normaliseParams(params: $r->params, aliases: ['requesterId' => 'requester', 'sourceSecretId' => 'secret_id']);
		$requester = (string)($params['requester'] ?? $r->l->t('a user'));
		$secretName = (string)($params['secret_name'] ?? $r->l->t('a secret'));
		$targetUserId = (string)($params['target_user_id'] ?? '');
		$r->notification->setParsedSubject((string)$r->l->t('Share request'));
		$message = (string)$r->l->t('%1$s requested access to the secret "%2$s".', [$requester, $secretName]);
		if ($targetUserId !== '' && $targetUserId !== $requester) {
			$message = (string)$r->l->t('%1$s asks you to share the secret "%2$s" with %3$s.', [$requester, $secretName, $targetUserId]);
		}

		$r->notification->setParsedMessage($message);
		$this->withSecretLink(notification: $r->notification, params: $params);
		$this->withShareRequestActions(r: $r, params: $params);
		return true;
	}//end renderShareRequest()

	/**
	 * Render the outcome of a share request, to the requester.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool Always true: the subject is recognised.
	 */
	private function renderShareRequestResult(SubjectRendering $r): bool {
		$secretName = (string)($r->params['secret_name'] ?? $r->l->t('a secret'));
		$result = (string)($r->params['result'] ?? 'denied');
		$r->notification->setParsedSubject((string)$r->l->t('Share request result'));
		$resultMessage = (string)$r->l->t('Your share request for "%s" was denied.', [$secretName]);
		if ($result === 'approved') {
			$resultMessage = (string)$r->l->t('Your share request for "%s" was approved.', [$secretName]);
		}

		$r->notification->setParsedMessage($resultMessage);
		$this->withSecretLink(notification: $r->notification, params: $r->params);
		return true;
	}//end renderShareRequestResult()

	/**
	 * Render a possibly compromised secret, to its owner.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool Always true: the subject is recognised.
	 */
	private function renderSecretCompromised(SubjectRendering $r): bool {
		$secretName = (string)($r->params['secret_name'] ?? $r->l->t('a secret'));
		$otherCount = (int)($r->params['other_count'] ?? 0);
		$r->notification->setParsedSubject((string)$r->l->t('Secret may be compromised'));
		// One notice per owner, so it says how many secrets it covers:
		// a single name read as "only this one" (keepiq#875).
		$message = $r->l->t('Your secret "%s" may be compromised and requires migration.', [$secretName]);
		if ($otherCount > 0) {
			$message = $r->l->t(
				'Your secret "%1$s" and %2$d other secret(s) may be compromised and require migration.',
				[$secretName, $otherCount]
			);
		}

		$r->notification->setParsedMessage((string)$message);
		$this->withSecretLink(notification: $r->notification, params: $r->params);
		return true;
	}//end renderSecretCompromised()

	/**
	 * Render a possibly compromised secret, to a recipient of a copy.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool Always true: the subject is recognised.
	 */
	private function renderSharedSecretCompromised(SubjectRendering $r): bool {
		$secretName = (string)($r->params['secret_name'] ?? $r->l->t('a secret'));
		$otherCount = (int)($r->params['other_count'] ?? 0);
		$r->notification->setParsedSubject((string)$r->l->t('Shared secret may be compromised'));
		$message = $r->l->t('The secret "%s" shared with you may be compromised. Change it where it is used.', [$secretName]);
		if ($otherCount > 0) {
			$message = $r->l->t(
				'The secret "%1$s" and %2$d other secret(s) shared with you may be compromised. Change them where they are used.',
				[$secretName, $otherCount]
			);
		}

		$r->notification->setParsedMessage((string)$message);
		$this->withSecretLink(notification: $r->notification, params: $r->params);
		return true;
	}//end renderSharedSecretCompromised()

	/**
	 * Render a secret that expires soon.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool Always true: the subject is recognised.
	 */
	private function renderSecretExpiring(SubjectRendering $r): bool {
		$secretName = (string)($r->params['secret_name'] ?? $r->l->t('a secret'));
		$daysLeft = (int)($r->params['days_left'] ?? 0);
		$r->notification->setParsedSubject((string)$r->l->t('Secret expiring soon'));
		$r->notification->setParsedMessage(
			(string)$r->l->t('Your secret "%1$s" expires in %2$d day(s). Rotate it before then.', [$secretName, $daysLeft])
		);
		$this->withSecretLink(notification: $r->notification, params: $r->params);
		return true;
	}//end renderSecretExpiring()

	/**
	 * Render a secret past its expiry.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool Always true: the subject is recognised.
	 */
	private function renderSecretRotationDue(SubjectRendering $r): bool {
		$secretName = (string)($r->params['secret_name'] ?? $r->l->t('a secret'));
		$r->notification->setParsedSubject((string)$r->l->t('Secret rotation due'));
		$r->notification->setParsedMessage(
			(string)$r->l->t('Your secret "%s" is past its expiry and has been flagged for rotation.', [$secretName])
		);
		$this->withSecretLink(notification: $r->notification, params: $r->params);
		return true;
	}//end renderSecretRotationDue()

	/**
	 * Render a vault root certificate that expires soon.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool Always true: the subject is recognised.
	 */
	private function renderRootExpiring(SubjectRendering $r): bool {
		$rootDaysLeft = (int)($r->params['days_left'] ?? 0);
		$r->notification->setParsedSubject((string)$r->l->t('Root certificate expiring soon'));
		$r->notification->setParsedMessage(
			(string)$r->l->t(
				'The vault root certificate expires in %1$d day(s). Renew it before then. Renewing re-signs every encryption suite.',
				[$rootDaysLeft]
			)
		);
		$this->withAdminSectionLink(notification: $r->notification);
		return true;
	}//end renderRootExpiring()

	/**
	 * Render an access to a decoy secret.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool Always true: the subject is recognised.
	 */
	private function renderHoneyAccess(SubjectRendering $r): bool {
		$honeyChannel = (string)($r->params['channel'] ?? $r->l->t('unknown channel'));
		$honeyAccessor = (string)($r->params['accessor'] ?? $r->l->t('an unknown accessor'));
		$r->notification->setParsedSubject((string)$r->l->t('Honey credential accessed'));
		$r->notification->setParsedMessage(
			(string)$r->l->t(
				'A decoy secret was accessed by %1$s via %2$s. Review the honey alerts now — this may indicate a compromise.',
				[$honeyAccessor, $honeyChannel]
			)
		);
		return true;
	}//end renderHoneyAccess()

	/**
	 * Render a vault certificate that expires soon.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool Always true: the subject is recognised.
	 */
	private function renderCertificateExpiring(SubjectRendering $r): bool {
		$certDaysLeft = (int)($r->params['days_left'] ?? 0);
		$r->notification->setParsedSubject((string)$r->l->t('Vault certificate expiring soon'));
		$r->notification->setParsedMessage(
			(string)$r->l->t(
				'Your vault encryption certificate expires in %1$d day(s). Re-issue it from the certificate inventory before it expires.',
				[$certDaysLeft]
			)
		);
		return true;
	}//end renderCertificateExpiring()

	/**
	 * Render new team folder members a member confirmed.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool Always true: the subject is recognised.
	 */
	private function renderTeamFolderMembersConfirmed(SubjectRendering $r): bool {
		$confirmedBy = (string)($r->params['confirmedBy'] ?? $r->l->t('a member'));
		$confirmedMembers = implode(', ', array_map('strval', (array)($r->params['memberIds'] ?? [])));
		$r->notification->setParsedSubject((string)$r->l->t('New team folder members confirmed'));
		$r->notification->setParsedMessage(
			(string)$r->l->t('%1$s gave %2$s access to your team folder.', [$confirmedBy, $confirmedMembers])
		);
		return true;
	}//end renderTeamFolderMembersConfirmed()

	/**
	 * Render a request for emergency access, to the grantor.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool Always true: the subject is recognised.
	 */
	private function renderEmergencyAccessRequested(SubjectRendering $r): bool {
		$granteeName = (string)($r->params['grantee_name'] ?? $r->params['granteeUserId'] ?? $r->l->t('a trusted contact'));
		$waitDays = (int)($r->params['waitPeriodDays'] ?? 7);
		$r->notification->setParsedSubject((string)$r->l->t('Emergency access requested'));
		$r->notification->setParsedMessage(
			(string)$r->l->t(
				'%1$s requested emergency access to your vault. It will be granted in %2$d day(s) unless you decline.',
				[$granteeName, $waitDays]
			)
		);
		return true;
	}//end renderEmergencyAccessRequested()

	/**
	 * Render emergency contacts an administrator removed.
	 *
	 * @param SubjectRendering $r The notification, its subject, parameters and translator
	 *
	 * @return bool Always true: the subject is recognised.
	 */
	private function renderEmergencyAccessCleared(SubjectRendering $r): bool {
		$clearedCount = (int)($r->params['count'] ?? 0);
		$r->notification->setParsedSubject((string)$r->l->t('Emergency access removed'));
		$r->notification->setParsedMessage(
			(string)$r->l->t(
				'An administrator revoked your vault key and deleted %d emergency contact(s). Add them again once your vault is set up.',
				[$clearedCount]
			)
		);
		return true;
	}//end renderEmergencyAccessCleared()

	/**
	 * Accept the parameter names the services actually send.
	 *
	 * The share-request and group-member services notify with camelCase keys
	 * (`secretName`, `requesterId`, `sourceSecretId`, `groupId`), while the
	 * renderers read snake_case. Both notifications therefore showed "a user"
	 * and "a secret" and carried no link (#747). Each camelCase key gains its
	 * snake_case twin, and a key whose meaning differs is mapped by name. A
	 * key already present is never overwritten. Applied per subject, because
	 * only where the recipient is the owner does a secret id name a secret
	 * in the recipient's own vault.
	 *
	 * @param array<string,mixed>  $params  The raw subject parameters
	 * @param array<string,string> $aliases camelCase key => snake_case key, where they differ
	 *
	 * @return array<string,mixed> The parameters with both spellings
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
	 */
	private static function normaliseParams(array $params, array $aliases): array {
		foreach ($params as $key => $value) {
			$snake = $aliases[$key] ?? strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', (string)$key));
			if ($snake !== $key && array_key_exists($snake, $params) === false) {
				$params[$snake] = $value;
			}
		}

		return $params;
	}//end normaliseParams()

	/**
	 * Approve and deny actions on a share request.
	 *
	 * Approving needs the owner's unlocked vault, because the browser
	 * encrypts the copy for the new recipient, so Approve opens the approval
	 * page in Keepiq. Denying needs no key, so Deny calls the API directly.
	 *
	 * @param SubjectRendering $r The notification being prepared and its translator
	 * @param array<string,mixed> $params The normalised subject parameters
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
	 */
	private function withShareRequestActions(SubjectRendering $r, array $params): void {
		$query = [
			'sourceSecretId' => (string)($params['secret_id'] ?? ''),
			'requesterId' => (string)($params['requester'] ?? ''),
			'targetUserId' => (string)($params['target_user_id'] ?? ''),
		];
		if (in_array('', $query, true) === true) {
			return;
		}

		$this->addActions(
			notification: $r->notification,
			l: $r->l,
			approvePage: 'approvals/share-request?' . http_build_query($query),
			denyApi: 'api/v1/share-requests/deny?' . http_build_query($query),
		);
	}//end withShareRequestActions()

	/**
	 * Approve and deny actions on a new group member of a group share.
	 *
	 * @param SubjectRendering $r The notification being prepared and its translator
	 * @param array<string,mixed> $params The normalised subject parameters
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-new-group-member-owner-notification
	 */
	private function withGroupMemberActions(SubjectRendering $r, array $params): void {
		$groupShareId = (string)($params['group_share_id'] ?? '');
		$newMemberId = (string)($params['new_member_id'] ?? '');
		$secretId = (string)($params['secret_id'] ?? '');
		if ($groupShareId === '' || $newMemberId === '' || $secretId === '') {
			return;
		}

		$this->addActions(
			notification: $r->notification,
			l: $r->l,
			approvePage: 'approvals/group-member?' . http_build_query(
				['groupShareId' => $groupShareId, 'newMemberId' => $newMemberId, 'secretId' => $secretId]
			),
			denyApi: 'api/v1/group-shares/' . rawurlencode($groupShareId) . '/deny-new-member?'
				. http_build_query(['newMemberId' => $newMemberId]),
		);
	}//end withGroupMemberActions()

	/**
	 * Add a primary Approve action (a Keepiq page) and a Deny action (a POST).
	 *
	 * @param INotification $notification The notification to mutate
	 * @param IL10N $l The localisation helper
	 * @param string $approvePage The SPA path and query, relative to the app root
	 * @param string $denyApi The API path and query, relative to the app root
	 *
	 * @return void
	 */
	private function addActions(INotification $notification, IL10N $l, string $approvePage, string $denyApi): void {
		try {
			$appRoot = $this->url->linkToRoute(AppIdentity::APP_ID . '.dashboard.page');
		} catch (InvalidArgumentException) {
			return;
		}

		$approve = $notification->createAction();
		$approve->setLabel('approve')
			->setParsedLabel((string)$l->t('Approve'))
			->setLink($this->url->getAbsoluteURL($appRoot . $approvePage), 'WEB')
			->setPrimary(true);
		$notification->addParsedAction($approve);

		$deny = $notification->createAction();
		$deny->setLabel('deny')
			->setParsedLabel((string)$l->t('Deny'))
			->setLink($this->url->getAbsoluteURL($appRoot . $denyApi), 'POST')
			->setPrimary(false);
		$notification->addParsedAction($deny);
	}//end addActions()

	/**
	 * Link a notification to a page of the app.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param string $path The app path, without a leading slash
	 *
	 * @return void
	 */
	private function withAppLink(INotification $notification, string $path): void {
		try {
			$route = $this->url->linkToRoute(AppIdentity::APP_ID . '.dashboard.page') . $path;
			$notification->setLink($this->url->getAbsoluteURL($route));
		} catch (InvalidArgumentException) {
			// The link is optional; the notification still says what happened.
		}
	}//end withAppLink()

	/**
	 * Attach a deep-link to the affected secret, when the params include one.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param array<string,mixed> $params The subject parameters
	 *
	 * @return void
	 */
	private function withSecretLink(INotification $notification, array $params): void {
		$secretId = (string)($params['secret_id'] ?? '');
		if ($secretId === '') {
			return;
		}

		try {
			/* Route names are namespaced by the app id, so this must be built
			   FROM the id rather than spelled out. It read
			   'keepiq.dashboard.page' as a literal, which is correct only
			   for as long as APP_ID happens to equal 'keepiq' — the next
			   rename would move the route and leave the literal behind. The
			   catch below then swallows the resulting exception, so every
			   secret deep-link would quietly stop appearing with nothing
			   logged: exactly the silent-failure shape this app id rename
			   was full of. */
			/* A path, not the retired '#/secrets/' hash form — the SPA's
			   createWebHistory router never reads the fragment. The route is
			   gated, so the click lands on the lock screen with this path as
			   returnUrl and resumes here after unlock. */
			$route = $this->url->linkToRoute(AppIdentity::APP_ID . '.dashboard.page')
				. 'secrets/' . $secretId;
			$notification->setLink($this->url->getAbsoluteURL($route));
		} catch (InvalidArgumentException) {
			/* Deliberately swallowed: the link is optional and a notification
			   without one is still useful. Worth knowing that this catch is
			   what made the hardcoded route above dangerous — an unresolvable
			   route produced no link, no error and no log. Deriving the name
			   from APP_ID removes the way that actually happened; if a
			   further failure mode turns up, this is where to add logging,
			   which needs a logger injected into the constructor. */
		}
	}//end withSecretLink()

	/**
	 * Attach a link to the Keepiq section of the Nextcloud admin settings.
	 *
	 * @param INotification $notification The notification to mutate
	 *
	 * @return void
	 */
	private function withAdminSectionLink(INotification $notification): void {
		$notification->setLink(
			$this->url->getAbsoluteURL(
				$this->url->linkToRoute('settings.AdminSettings.index', ['section' => AppIdentity::APP_ID])
			)
		);
	}//end withAdminSectionLink()
}//end class
