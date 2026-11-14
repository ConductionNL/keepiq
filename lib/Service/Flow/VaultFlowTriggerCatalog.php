<?php

/**
 * Keepiq vault flow trigger catalog
 *
 * Maps every audited vault event to the flow trigger it fires, a plain
 * sentence label and the trigger group an administrator switches on.
 *
 * @category Service
 * @package  OCA\Keepiq\Service\Flow
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

namespace OCA\Keepiq\Service\Flow;

use OCA\Keepiq\Event\Audit\AuditEventTypes;
use ReflectionClass;

/**
 * The vault flow trigger catalog (clients-vault-event-flow-triggers D2, D4).
 *
 * Trigger ids are `keepiq.` plus the audit event type. Every audit event type
 * belongs to exactly one trigger group; the group is what an administrator
 * enables, and nothing fires while its group is off.
 */
class VaultFlowTriggerCatalog {

	/**
	 * The prefix of every Keepiq flow trigger id.
	 *
	 * @var string
	 */
	public const TRIGGER_PREFIX = 'keepiq.';

	/**
	 * The group the flow builder lists every Keepiq trigger under.
	 *
	 * @var string
	 */
	public const PALETTE_GROUP = 'Vault';

	/**
	 * The trigger groups an administrator can switch on, in display order.
	 *
	 * @var string[]
	 */
	public const GROUPS = [
		'items',
		'item_reads',
		'sharing',
		'links',
		'requests',
		'emergency_access',
		'applications',
		'keys',
	];

	/**
	 * Event types whose group differs from their category's group. Reads are
	 * their own group because they fire on every reveal (design D4).
	 *
	 * @var array<string,string>
	 */
	private const TYPE_GROUPS = [
		AuditEventTypes::SECRET_READ          => 'item_reads',
		AuditEventTypes::SECRET_USED          => 'item_reads',
		AuditEventTypes::ATTACHMENT_DOWNLOADED => 'item_reads',
		AuditEventTypes::HONEY_ACCESSED       => 'item_reads',
	];

	/**
	 * The group of each event category (the part before the first dot).
	 *
	 * @var array<string,string>
	 */
	private const CATEGORY_GROUPS = [
		'secret'             => 'items',
		'folder'             => 'items',
		'attachment'         => 'items',
		'lease'              => 'items',
		'vault'              => 'items',
		'backup'             => 'items',
		'policy'             => 'items',
		'password_policy'    => 'items',
		'vault_policy'       => 'items',
		'compliance'         => 'items',
		'share'              => 'sharing',
		'team_folder'        => 'sharing',
		'federated_share'    => 'sharing',
		'federation_partner' => 'sharing',
		'link_share'         => 'links',
		'request'            => 'requests',
		'emergency_access'   => 'emergency_access',
		'recovery'           => 'emergency_access',
		'application'        => 'applications',
		'mcp'                => 'applications',
		'siem'               => 'applications',
		'suite'              => 'keys',
		'key_proof'          => 'keys',
		'passkey'            => 'keys',
		'certificate'        => 'keys',
		'device_approval'    => 'keys',
	];

	/**
	 * How the subject of each category reads in a label.
	 *
	 * @var array<string,string>
	 */
	private const CATEGORY_NOUNS = [
		'secret'             => 'An item',
		'folder'             => 'A folder',
		'attachment'         => 'An attachment',
		'lease'              => 'A lease',
		'vault'              => 'A vault',
		'backup'             => 'A backup',
		'policy'             => 'A policy',
		'password_policy'    => 'The password policy',
		'vault_policy'       => 'A vault policy',
		'compliance'         => 'A compliance report',
		'share'              => 'A share',
		'team_folder'        => 'A team folder',
		'federated_share'    => 'A federated share',
		'federation_partner' => 'A federation partner',
		'link_share'         => 'A link share',
		'request'            => 'A request',
		'emergency_access'   => 'Emergency access',
		'recovery'           => 'Account recovery',
		'application'        => 'An application',
		'mcp'                => 'An MCP tool',
		'siem'               => 'A SIEM sink',
		'suite'              => 'An encryption suite',
		'key_proof'          => 'A key proof',
		'passkey'            => 'A passkey',
		'certificate'        => 'A certificate',
		'device_approval'    => 'A device approval',
		'honey'              => 'A honey item',
	];

	/**
	 * Every audit event type, in declaration order.
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-1.1
	 */
	public function eventTypes(): array {
		$types = [];
		foreach ((new ReflectionClass(AuditEventTypes::class))->getConstants() as $value) {
			if (is_string($value) === true) {
				$types[] = $value;
			}
		}

		return array_values(array_unique($types));
	}//end eventTypes()

	/**
	 * The trigger id an event type fires.
	 *
	 * @param string $eventType The audit event type
	 *
	 * @return string
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-1.1
	 */
	public function triggerId(string $eventType): string {
		return self::TRIGGER_PREFIX . $eventType;
	}//end triggerId()

	/**
	 * The trigger group of an event type, or null for an unknown category.
	 *
	 * @param string $eventType The audit event type
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-1.1
	 */
	public function groupOf(string $eventType): ?string {
		if (isset(self::TYPE_GROUPS[$eventType]) === true) {
			return self::TYPE_GROUPS[$eventType];
		}

		return self::CATEGORY_GROUPS[$this->category(eventType: $eventType)] ?? null;
	}//end groupOf()

	/**
	 * The plain-sentence label of an event type, e.g. "A share is granted".
	 *
	 * A single-word action reads as "<subject> is <action>"; a longer one as
	 * "<subject>: <action words>", which stays readable for every type.
	 *
	 * @param string $eventType The audit event type
	 *
	 * @return string
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-1.1
	 */
	public function label(string $eventType): string {
		$category = $this->category(eventType: $eventType);
		$noun = self::CATEGORY_NOUNS[$category] ?? ucfirst(str_replace('_', ' ', $category));
		$action = substr($eventType, strlen($category) + 1);
		if ($action === '') {
			return $noun;
		}

		if (str_contains($action, '_') === false) {
			return $noun . ' is ' . $action;
		}

		return $noun . ': ' . str_replace('_', ' ', $action);
	}//end label()

	/**
	 * Every catalog entry: trigger id, label, trigger group and event type.
	 *
	 * @return array<int,array{id:string,label:string,group:string,eventType:string}>
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-1.1
	 */
	public function entries(): array {
		$entries = [];
		foreach ($this->eventTypes() as $eventType) {
			$group = $this->groupOf(eventType: $eventType);
			if ($group === null) {
				continue;
			}

			$entries[] = [
				'id'        => $this->triggerId(eventType: $eventType),
				'label'     => $this->label(eventType: $eventType),
				'group'     => $group,
				'eventType' => $eventType,
			];
		}

		return $entries;
	}//end entries()

	/**
	 * The enabled groups from the stored `flow_trigger_groups` value: a JSON
	 * list, filtered to known groups. Anything unreadable enables nothing.
	 *
	 * @param string $stored The stored app config value
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-2.1
	 */
	public function parseEnabledGroups(string $stored): array {
		$decoded = json_decode($stored, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_intersect(self::GROUPS, $decoded));
	}//end parseEnabledGroups()

	/**
	 * The category of an event type: the part before the first dot.
	 *
	 * @param string $eventType The audit event type
	 *
	 * @return string
	 */
	private function category(string $eventType): string {
		return explode('.', $eventType, 2)[0];
	}//end category()
}//end class
