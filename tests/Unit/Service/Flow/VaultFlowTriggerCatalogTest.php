<?php

/**
 * Unit tests for VaultFlowTriggerCatalog: every audit event type has one trigger.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\Flow
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

namespace OCA\Keepiq\Tests\Unit\Service\Flow;

use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\Flow\VaultFlowTriggerCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for VaultFlowTriggerCatalog.
 */
#[CoversClass(VaultFlowTriggerCatalog::class)]
class VaultFlowTriggerCatalogTest extends TestCase {
	private VaultFlowTriggerCatalog $catalog;

	/**
	 * Build the catalog.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->catalog = new VaultFlowTriggerCatalog();
	}//end setUp()

	/**
	 * Every string constant in AuditEventTypes has exactly one catalog entry,
	 * in a known group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-1.1
	 */
	public function testEveryAuditEventTypeHasExactlyOneEntry(): void {
		$types = [];
		foreach ((new ReflectionClass(AuditEventTypes::class))->getConstants() as $name => $value) {
			if (is_string($value) === true) {
				$types[$name] = $value;
			}
		}

		$this->assertGreaterThan(100, count($types));
		$entries = $this->catalog->entries();
		$counts = array_count_values(array_column($entries, 'eventType'));
		foreach ($types as $name => $type) {
			$this->assertSame(1, $counts[$type] ?? 0, $name . ' (' . $type . ') must have exactly one trigger entry');
		}

		$this->assertCount(count(array_unique($types)), $entries);
		foreach ($entries as $entry) {
			$this->assertContains($entry['group'], VaultFlowTriggerCatalog::GROUPS);
			$this->assertSame('keepiq.' . $entry['eventType'], $entry['id']);
			$this->assertNotSame('', $entry['label']);
		}
	}//end testEveryAuditEventTypeHasExactlyOneEntry()

	/**
	 * The spec's own examples land in their groups, reads apart from items.
	 *
	 * @return void
	 */
	public function testGroupsFollowTheSpec(): void {
		$this->assertSame('sharing', $this->catalog->groupOf(eventType: AuditEventTypes::SHARE_GRANTED));
		$this->assertSame('items', $this->catalog->groupOf(eventType: AuditEventTypes::SECRET_CREATED));
		$this->assertSame('item_reads', $this->catalog->groupOf(eventType: AuditEventTypes::SECRET_READ));
		$this->assertSame('links', $this->catalog->groupOf(eventType: AuditEventTypes::LINK_SHARE_ACCESSED));
		$this->assertSame('requests', $this->catalog->groupOf(eventType: AuditEventTypes::REQUEST_CREATED));
		$this->assertSame('emergency_access', $this->catalog->groupOf(eventType: AuditEventTypes::EMERGENCY_ACCESS_REQUESTED));
		$this->assertSame('applications', $this->catalog->groupOf(eventType: AuditEventTypes::APPLICATION_REGISTERED));
		$this->assertSame('keys', $this->catalog->groupOf(eventType: AuditEventTypes::SUITE_REVOKED));
		$this->assertNull($this->catalog->groupOf(eventType: 'unknown.thing'));
	}//end testGroupsFollowTheSpec()

	/**
	 * Labels read as plain sentences.
	 *
	 * @return void
	 */
	public function testLabelsArePlainSentences(): void {
		$this->assertSame('A share is granted', $this->catalog->label(eventType: 'share.granted'));
		$this->assertSame('Emergency access is requested', $this->catalog->label(eventType: 'emergency_access.requested'));
		$this->assertSame('A link share: access failed', $this->catalog->label(eventType: 'link_share.access_failed'));
		$this->assertSame('Widget is made', $this->catalog->label(eventType: 'widget.made'));
		$this->assertSame('keepiq.share.granted', $this->catalog->triggerId(eventType: 'share.granted'));
	}//end testLabelsArePlainSentences()

	/**
	 * The stored value enables only known groups, in catalog order.
	 *
	 * @return void
	 */
	public function testParseEnabledGroups(): void {
		$this->assertSame([], $this->catalog->parseEnabledGroups(stored: '[]'));
		$this->assertSame([], $this->catalog->parseEnabledGroups(stored: 'not json'));
		$this->assertSame([], $this->catalog->parseEnabledGroups(stored: '"sharing"'));
		$this->assertSame(
			['sharing', 'emergency_access'],
			$this->catalog->parseEnabledGroups(stored: '["emergency_access","bogus","sharing"]')
		);
	}//end testParseEnabledGroups()
	/**
	 * A type without an action reads as its noun alone; an unknown category
	 * reads as its words, capitalised.
	 *
	 * @return void
	 */
	public function testLabelsOfABareCategoryAndAnUnknownOne(): void {
		$this->assertSame('A share', $this->catalog->label(eventType: 'share'));
		$this->assertSame('A share', $this->catalog->label(eventType: 'share.'));
		$this->assertSame('Some thing is done', $this->catalog->label(eventType: 'some_thing.done'));
		$this->assertSame('Some thing: went wrong twice', $this->catalog->label(eventType: 'some_thing.went_wrong_twice'));
		$this->assertSame('Widget', $this->catalog->label(eventType: 'widget'));
	}//end testLabelsOfABareCategoryAndAnUnknownOne()

	/**
	 * A per-type group wins over its category's group; a category with a noun
	 * but no group has no group of its own.
	 *
	 * @return void
	 */
	public function testTypeGroupsOverrideCategoryGroups(): void {
		$this->assertSame('item_reads', $this->catalog->groupOf(eventType: AuditEventTypes::HONEY_ACCESSED));
		$this->assertSame('item_reads', $this->catalog->groupOf(eventType: AuditEventTypes::ATTACHMENT_DOWNLOADED));
		$this->assertSame('items', $this->catalog->groupOf(eventType: 'attachment.uploaded'));
		$this->assertNull($this->catalog->groupOf(eventType: 'honey.planted'));
		$this->assertSame('keys', $this->catalog->groupOf(eventType: 'device_approval.anything'));
	}//end testTypeGroupsOverrideCategoryGroups()

	/**
	 * Entries skip an event type without a group and keep the order of the
	 * event types.
	 *
	 * @return void
	 */
	public function testEntriesSkipATypeWithoutAGroup(): void {
		$catalog = new class extends VaultFlowTriggerCatalog {
			/**
			 * A fixed list with one type that has no group.
			 *
			 * @return string[]
			 */
			public function eventTypes(): array {
				return ['share.granted', 'honey.planted', 'secret.read'];
			}//end eventTypes()
		};

		$this->assertSame(
			[
				['id' => 'keepiq.share.granted', 'label' => 'A share is granted', 'group' => 'sharing', 'eventType' => 'share.granted'],
				['id' => 'keepiq.secret.read', 'label' => 'An item is read', 'group' => 'item_reads', 'eventType' => 'secret.read'],
			],
			$catalog->entries()
		);
	}//end testEntriesSkipATypeWithoutAGroup()

	/**
	 * Every event type is listed once, as a string.
	 *
	 * @return void
	 */
	public function testEventTypesAreUniqueStrings(): void {
		$types = $this->catalog->eventTypes();

		$this->assertSame(array_values(array_unique($types)), $types);
		$this->assertContains(AuditEventTypes::SHARE_GRANTED, $types);
		$this->assertContainsOnly('string', $types);
	}//end testEventTypesAreUniqueStrings()

	/**
	 * A JSON object or a list with non-strings enables only the known groups it names.
	 *
	 * @return void
	 */
	public function testParseEnabledGroupsIgnoresNonGroupValues(): void {
		$this->assertSame(['keys'], $this->catalog->parseEnabledGroups(stored: '{"a":"keys","b":7}'));
		$this->assertSame([], $this->catalog->parseEnabledGroups(stored: '[1,null,true]'));
		$this->assertSame([], $this->catalog->parseEnabledGroups(stored: ''));
		$this->assertSame(VaultFlowTriggerCatalog::GROUPS, $this->catalog->parseEnabledGroups(stored: json_encode(array_reverse(VaultFlowTriggerCatalog::GROUPS))));
	}//end testParseEnabledGroupsIgnoresNonGroupValues()
}//end class
