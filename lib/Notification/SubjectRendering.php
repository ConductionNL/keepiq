<?php

/**
 * Keepiq Subject Rendering
 *
 * One notification being prepared: the notification, its subject, its
 * parameters and the translator for the recipient's language.
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

use OCP\IL10N;
use OCP\Notification\INotification;

/**
 * The values every subject renderer of KeepiqNotifier reads; built once per
 * prepare() and never kept beyond it.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-new-group-member-owner-notification
 */
final class SubjectRendering {
	/**
	 * Constructor for SubjectRendering.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return void
	 */
	public function __construct(
		public readonly INotification $notification,
		public readonly string $subject,
		public readonly array $params,
		public readonly IL10N $l,
	) {
	}//end __construct()
}//end class
