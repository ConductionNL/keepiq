<?php

/**
 * Keepiq Backup Table Registry
 *
 * Every Keepiq table a vault backup copies (admin-scheduled-vault-backups D1).
 * A unit test compares this list with the consolidated schema migration, so a
 * new table can never be left out of a backup silently.
 *
 * @category Backup
 * @package  OCA\Keepiq\Backup
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

namespace OCA\Keepiq\Backup;

/**
 * The tables in a vault backup, without the `keepiq_` prefix.
 */
final class BackupTableRegistry {
	/**
	 * The table prefix every Keepiq table carries.
	 */
	public const PREFIX = 'keepiq_';

	/**
	 * Every Keepiq table, unprefixed, in a stable order.
	 *
	 * @var string[]
	 */
	public const TABLES = [
		'app_lease_policies',
		'applications',
		'attachment_grants',
		'attachments',
		'audit_log',
		'ca_certs',
		'certificate_metadata',
		'compliance_reports',
		'dashboard_settings',
		'device_approvals',
		'emergency_contacts',
		'enc_suites',
		'ephemeral_sends',
		'expiry_policies',
		'folders',
		'group_shares',
		'honey_alerts',
		'honey_flags',
		'link_shares',
		'machine_leases',
		'migration_failures',
		'passkey_credentials',
		'recovery_approvals',
		'recovery_enrolments',
		'recovery_keys',
		'recovery_officers',
		'recovery_requests',
		'rotation_flags',
		'secret_delegations',
		'secret_requests',
		'secret_tags',
		'secret_types',
		'secret_versions',
		'secrets',
		'share_targets',
		'siem_queue',
		'siem_sinks',
		'suite_migr',
		'team_folder_members',
		'team_folders',
		'used_proofs',
	];

	/**
	 * Tables with an autoincrement `id`, whose sequence a restore resets.
	 *
	 * @var string[]
	 */
	public const AUTOINCREMENT = ['audit_log', 'migration_failures', 'secret_tags', 'used_proofs'];
}//end class
