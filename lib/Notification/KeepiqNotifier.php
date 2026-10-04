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
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Service\NotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders Keepiq notifications.
 *
 * The subject IDs here must match NotificationService::SUBJECT_SETTING_MAP.
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
		return Application::APP_ID;
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
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$subj = $notification->getSubject();
		if (array_key_exists($subj, NotificationService::SUBJECT_SETTING_MAP) === false) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(app: Application::APP_ID, lang: $languageCode);
		$params = $notification->getSubjectParameters();

		$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));

		// Each renderer owns one family of subjects and reports whether it
		// recognised this one. The first renderer that claims the subject wins.
		$renderers = [
			fn (): bool => $this->renderSharingSubject(notification: $notification, subject: $subj, params: $params, l: $l),
			fn (): bool => $this->renderSecretLifecycleSubject(notification: $notification, subject: $subj, params: $params, l: $l),
			fn (): bool => $this->renderAdminSubject(notification: $notification, subject: $subj, params: $params, l: $l),
			fn (): bool => $this->renderVaultAccessSubject(notification: $notification, subject: $subj, params: $params, l: $l),
			fn (): bool => $this->renderEmergencySubject(notification: $notification, subject: $subj, params: $params, l: $l),
			fn (): bool => $this->renderDeviceApprovalSubject(notification: $notification, subject: $subj, params: $params, l: $l),
			fn (): bool => $this->renderRecoverySubject(notification: $notification, subject: $subj, params: $params, l: $l),
			fn (): bool => $this->renderAccessEndSubject(notification: $notification, subject: $subj, params: $params, l: $l),
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
	 * @param INotification $notification The notification to mutate
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return bool True when this renderer recognised the subject.
	 */
	private function renderSharingSubject(INotification $notification, string $subject, array $params, IL10N $l): bool {
		switch ($subject) {
			case 'secret_shared':
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$sharedBy = (string)($params['shared_by'] ?? $l->t('a user'));
				$notification->setParsedSubject((string)$l->t('Secret shared with you'));
				$notification->setParsedMessage(
					(string)$l->t('%1$s shared the secret "%2$s" with you.', [$sharedBy, $secretName])
				);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
			case 'share_request':
				// Sent to the owner, so sourceSecretId is the owner's own secret.
				$params = self::normaliseParams(params: $params, aliases: ['requesterId' => 'requester', 'sourceSecretId' => 'secret_id']);
				$requester = (string)($params['requester'] ?? $l->t('a user'));
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$targetUserId = (string)($params['target_user_id'] ?? '');
				$notification->setParsedSubject((string)$l->t('Share request'));
				$message = (string)$l->t('%1$s requested access to the secret "%2$s".', [$requester, $secretName]);
				if ($targetUserId !== '' && $targetUserId !== $requester) {
					$message = (string)$l->t('%1$s asks you to share the secret "%2$s" with %3$s.', [$requester, $secretName, $targetUserId]);
				}

				$notification->setParsedMessage($message);
				$this->withSecretLink(notification: $notification, params: $params);
				$this->withShareRequestActions(notification: $notification, params: $params, l: $l);
				return true;
			case 'share_request_result':
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$result = (string)($params['result'] ?? 'denied');
				$notification->setParsedSubject((string)$l->t('Share request result'));
				$resultMessage = (string)$l->t('Your share request for "%s" was denied.', [$secretName]);
				if ($result === 'approved') {
					$resultMessage = (string)$l->t('Your share request for "%s" was approved.', [$secretName]);
				}

				$notification->setParsedMessage($resultMessage);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
			case 'group_member_added':
				// Sent to the owner, so secretId is the owner's own secret.
				$params = self::normaliseParams(params: $params, aliases: []);
				$groupId = (string)($params['group_id'] ?? '');
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$notification->setParsedSubject((string)$l->t('Group member added'));
				$notification->setParsedMessage(
					(string)$l->t('A new member joined the group "%1$s" — approve to share "%2$s".', [$groupId, $secretName])
				);
				$this->withSecretLink(notification: $notification, params: $params);
				$this->withGroupMemberActions(notification: $notification, params: $params, l: $l);
				return true;
		}//end switch

		return false;
	}//end renderSharingSubject()

	/**
	 * Render the organisation account recovery subjects
	 * (crypto-organisation-account-recovery 5.2). All link to the app.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return bool True when this renderer recognised the subject.
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
	 */
	private function renderRecoverySubject(INotification $notification, string $subject, array $params, IL10N $l): bool {
		$texts = [
			'recovery_officer_named' => (string)$l->t('You are now an account recovery officer'),
			'recovery_requested' => (string)$l->t(
				'%s asks to recover their account. Compare the words with them before you approve.',
				[(string)($params['user'] ?? $l->t('A user'))]
			),
			'recovery_declined' => (string)$l->t('Your account recovery request was declined'),
			'recovery_ready' => (string)$l->t('Your account recovery is ready. Open Keepiq in the browser you asked from.'),
		];
		if (isset($texts[$subject]) === false) {
			return false;
		}

		$notification->setParsedSubject($texts[$subject]);
		try {
			$notification->setLink(
				$this->url->getAbsoluteURL($this->url->linkToRoute(Application::APP_ID . '.dashboard.page'))
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
	 * @param INotification $notification The notification to mutate
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return bool True when this renderer recognised the subject.
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
	 */
	private function renderDeviceApprovalSubject(INotification $notification, string $subject, array $params, IL10N $l): bool {
		if ($subject !== 'device_approval_requested') {
			return false;
		}

		$label = (string)($params['device_label'] ?? '');
		if ($label === '') {
			$label = (string)$l->t('A device');
		}

		$notification->setParsedSubject((string)$l->t('A new device asks to open your vault'));
		$notification->setParsedMessage(
			(string)$l->t('%s asks to be approved. Only approve a device you are using right now.', [$label])
		);
		try {
			$notification->setLink(
				$this->url->getAbsoluteURL($this->url->linkToRoute(Application::APP_ID . '.dashboard.page'))
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
	 * @param INotification $notification The notification to mutate
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return bool True when this renderer recognised the subject.
	 *
	 * @spec openspec/specs/expiring-shares/spec.md#requirement-people-are-told-before-and-when-access-ends
	 */
	private function renderAccessEndSubject(INotification $notification, string $subject, array $params, IL10N $l): bool {
		$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
		switch ($subject) {
			case 'share_access_ending':
				$notification->setParsedSubject((string)$l->t('Your access to "%s" ends tomorrow', [$secretName]));
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
			case 'share_access_ended':
				$notification->setParsedSubject((string)$l->t('Your access to "%s" has ended', [$secretName]));
				return true;
			case 'share_access_ended_owner':
				$recipient = (string)($params['recipient'] ?? $l->t('a user'));
				$notification->setParsedSubject(
					(string)$l->t('%1$s no longer has access to "%2$s"', [$recipient, $secretName])
				);
				$message = (string)$l->t('%1$s could see this password. Rotate it if %1$s should no longer know it.', [$recipient]);
				if (($params['use_only'] ?? false) === true) {
					$message = (string)$l->t('%s could not view this password in Keepiq.', [$recipient]);
				}

				$notification->setParsedMessage($message);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
		}//end switch

		return false;
	}//end renderAccessEndSubject()

	/**
	 * Render the secret-lifecycle subjects. All of them deep-link to a secret.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return bool True when this renderer recognised the subject.
	 */
	private function renderSecretLifecycleSubject(INotification $notification, string $subject, array $params, IL10N $l): bool {
		switch ($subject) {
			case 'secret_compromised':
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$otherCount = (int)($params['other_count'] ?? 0);
				$notification->setParsedSubject((string)$l->t('Secret may be compromised'));
				// One notice per owner, so it says how many secrets it covers:
				// a single name read as "only this one" (keepiq#875).
				$message = $l->t('Your secret "%s" may be compromised and requires migration.', [$secretName]);
				if ($otherCount > 0) {
					$message = $l->t(
						'Your secret "%1$s" and %2$d other secret(s) may be compromised and require migration.',
						[$secretName, $otherCount]
					);
				}

				$notification->setParsedMessage((string)$message);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
			case 'shared_secret_compromised':
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$otherCount = (int)($params['other_count'] ?? 0);
				$notification->setParsedSubject((string)$l->t('Shared secret may be compromised'));
				$message = $l->t('The secret "%s" shared with you may be compromised. Change it where it is used.', [$secretName]);
				if ($otherCount > 0) {
					$message = $l->t(
						'The secret "%1$s" and %2$d other secret(s) shared with you may be compromised. Change them where they are used.',
						[$secretName, $otherCount]
					);
				}

				$notification->setParsedMessage((string)$message);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
			case 'request_fulfilled':
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$notification->setParsedSubject((string)$l->t('Secret request fulfilled'));
				$notification->setParsedMessage(
					(string)$l->t('Your request for "%s" has been filled in.', [$secretName])
				);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
			case 'secret_expiring':
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$daysLeft = (int)($params['days_left'] ?? 0);
				$notification->setParsedSubject((string)$l->t('Secret expiring soon'));
				$notification->setParsedMessage(
					(string)$l->t('Your secret "%1$s" expires in %2$d day(s). Rotate it before then.', [$secretName, $daysLeft])
				);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
			case 'secret_rotation_due':
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$notification->setParsedSubject((string)$l->t('Secret rotation due'));
				$notification->setParsedMessage(
					(string)$l->t('Your secret "%s" is past its expiry and has been flagged for rotation.', [$secretName])
				);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
		}//end switch

		return false;
	}//end renderSecretLifecycleSubject()

	/**
	 * Render the administrator-facing subjects. All of them link to the admin section.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return bool True when this renderer recognised the subject.
	 */
	private function renderAdminSubject(INotification $notification, string $subject, array $params, IL10N $l): bool {
		switch ($subject) {
			case 'app_pending':
				$appName = (string)($params['app_name'] ?? $l->t('an application'));
				$registeredBy = (string)($params['registered_by'] ?? $l->t('an external party'));
				$notification->setParsedSubject((string)$l->t('New application pending approval'));
				$notification->setParsedMessage(
					(string)$l->t('Application "%1$s" was registered by %2$s and is awaiting approval.', [$appName, $registeredBy])
				);
				$this->withAdminSectionLink(notification: $notification);
				return true;
			case 'siem_dead_letter':
				$sinkName = (string)($params['sink_name'] ?? $l->t('a SIEM sink'));
				$notification->setParsedSubject((string)$l->t('SIEM delivery failing'));
				$notification->setParsedMessage(
					(string)$l->t(
						'Audit events for SIEM sink "%1$s" could not be delivered and were dead-lettered. Check the sink configuration.',
						[$sinkName]
					)
				);
				$this->withAdminSectionLink(notification: $notification);
				return true;
			case 'ca_root_expiring':
				$rootDaysLeft = (int)($params['days_left'] ?? 0);
				$notification->setParsedSubject((string)$l->t('Root certificate expiring soon'));
				$notification->setParsedMessage(
					(string)$l->t(
						'The vault root certificate expires in %1$d day(s). Renew it before then. Renewing re-signs every encryption suite.',
						[$rootDaysLeft]
					)
				);
				$this->withAdminSectionLink(notification: $notification);
				return true;
		}//end switch

		return false;
	}//end renderAdminSubject()

	/**
	 * Render the vault-access subjects — team folders, decoys, certificates and
	 * emergency access. None of them carry a deep-link.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return bool True when this renderer recognised the subject.
	 */
	private function renderVaultAccessSubject(INotification $notification, string $subject, array $params, IL10N $l): bool {
		switch ($subject) {
			case 'team_folder_shared':
				$sharedBy = (string)($params['sharedBy'] ?? $l->t('a user'));
				$notification->setParsedSubject((string)$l->t('Team folder shared with you'));
				$notification->setParsedMessage(
					(string)$l->t('%s shared a team folder with you. Its secrets are now in your vault.', [$sharedBy])
				);
				return true;
			case 'team_folder_member_confirmed':
				$confirmedBy = (string)($params['confirmedBy'] ?? $l->t('a member'));
				$confirmedMembers = implode(', ', array_map('strval', (array)($params['memberIds'] ?? [])));
				$notification->setParsedSubject((string)$l->t('New team folder members confirmed'));
				$notification->setParsedMessage(
					(string)$l->t('%1$s gave %2$s access to your team folder.', [$confirmedBy, $confirmedMembers])
				);
				return true;
			case 'team_folder_join_request':
				$newMemberId = (string)($params['newMemberId'] ?? $l->t('a user'));
				$joinGroupId = (string)($params['groupId'] ?? '');
				$notification->setParsedSubject((string)$l->t('Team folder join request'));
				$notification->setParsedMessage(
					(string)$l->t('%1$s joined the group "%2$s" — approve to share your team folder with them.', [$newMemberId, $joinGroupId])
				);
				return true;
			case 'honey_access':
				$honeyChannel = (string)($params['channel'] ?? $l->t('unknown channel'));
				$honeyAccessor = (string)($params['accessor'] ?? $l->t('an unknown accessor'));
				$notification->setParsedSubject((string)$l->t('Honey credential accessed'));
				$notification->setParsedMessage(
					(string)$l->t(
						'A decoy secret was accessed by %1$s via %2$s. Review the honey alerts now — this may indicate a compromise.',
						[$honeyAccessor, $honeyChannel]
					)
				);
				return true;
			case 'certificate_expiring':
				$certDaysLeft = (int)($params['days_left'] ?? 0);
				$notification->setParsedSubject((string)$l->t('Vault certificate expiring soon'));
				$notification->setParsedMessage(
					(string)$l->t(
						'Your vault encryption certificate expires in %1$d day(s). Re-issue it from the certificate inventory before it expires.',
						[$certDaysLeft]
					)
				);
				return true;
		}//end switch

		return false;
	}//end renderVaultAccessSubject()

	/**
	 * Render the emergency-access subjects: requests, grants that were used,
	 * cleared and compromised contacts. None of them carry a deep-link.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return bool True when this renderer recognised the subject.
	 *
	 * @spec openspec/specs/emergency-access/spec.md
	 */
	private function renderEmergencySubject(INotification $notification, string $subject, array $params, IL10N $l): bool {
		switch ($subject) {
			case 'emergency_access_requested':
				$granteeName = (string)($params['grantee_name'] ?? $params['granteeUserId'] ?? $l->t('a trusted contact'));
				$waitDays = (int)($params['waitPeriodDays'] ?? 7);
				$notification->setParsedSubject((string)$l->t('Emergency access requested'));
				$notification->setParsedMessage(
					(string)$l->t(
						'%1$s requested emergency access to your vault. It will be granted in %2$d day(s) unless you decline.',
						[$granteeName, $waitDays]
					)
				);
				return true;
			case 'emergency_grantee_compromised':
				$granteeName = (string)($params['grantee_name'] ?? $params['granteeUserId'] ?? $l->t('a trusted contact'));
				$notification->setParsedSubject((string)$l->t('Emergency contact compromised'));
				$notification->setParsedMessage(
					(string)$l->t(
						'%s had approved emergency access to your vault. Their encryption key was revoked as compromised, so start a key rotation.',
						[$granteeName]
					)
				);
				return true;
			case 'emergency_access_cleared':
				$clearedCount = (int)($params['count'] ?? 0);
				$notification->setParsedSubject((string)$l->t('Emergency access removed'));
				$notification->setParsedMessage(
					(string)$l->t(
						'An administrator revoked your vault key and deleted %d emergency contact(s). Add them again once your vault is set up.',
						[$clearedCount]
					)
				);
				return true;
			case 'emergency_access_accessed':
				$granteeName = (string)($params['grantee_name'] ?? $params['granteeUserId'] ?? $l->t('a trusted contact'));
				$notification->setParsedSubject((string)$l->t('Emergency access used'));
				$notification->setParsedMessage(
					(string)$l->t('%s accessed your vault through emergency access.', [$granteeName])
				);
				return true;
		}//end switch

		return false;
	}//end renderEmergencySubject()

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
	 * @param INotification $notification The notification to mutate
	 * @param array<string,mixed> $params The normalised subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
	 */
	private function withShareRequestActions(INotification $notification, array $params, IL10N $l): void {
		$query = [
			'sourceSecretId' => (string)($params['secret_id'] ?? ''),
			'requesterId' => (string)($params['requester'] ?? ''),
			'targetUserId' => (string)($params['target_user_id'] ?? ''),
		];
		if (in_array('', $query, true) === true) {
			return;
		}

		$this->addActions(
			notification: $notification,
			l: $l,
			approvePage: 'approvals/share-request?' . http_build_query($query),
			denyApi: 'api/v1/share-requests/deny?' . http_build_query($query),
		);
	}//end withShareRequestActions()

	/**
	 * Approve and deny actions on a new group member of a group share.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param array<string,mixed> $params The normalised subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-new-group-member-owner-notification
	 */
	private function withGroupMemberActions(INotification $notification, array $params, IL10N $l): void {
		$groupShareId = (string)($params['group_share_id'] ?? '');
		$newMemberId = (string)($params['new_member_id'] ?? '');
		$secretId = (string)($params['secret_id'] ?? '');
		if ($groupShareId === '' || $newMemberId === '' || $secretId === '') {
			return;
		}

		$this->addActions(
			notification: $notification,
			l: $l,
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
			$appRoot = $this->url->linkToRoute(Application::APP_ID . '.dashboard.page');
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
			$route = $this->url->linkToRoute(Application::APP_ID . '.dashboard.page')
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
				$this->url->linkToRoute('settings.AdminSettings.index', ['section' => Application::APP_ID])
			)
		);
	}//end withAdminSectionLink()
}//end class
