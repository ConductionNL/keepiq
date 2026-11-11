<?php

/**
 * Keepiq Legacy Register Sweep
 *
 * The register half of one legacy cleanup plan.
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
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

namespace OCA\Keepiq\Repair;

/**
 * Every register, the schemas this plan removes and the data tables per
 * register, plus the registers the sweep removes and the lists it rewrites;
 * built by LegacyRegisterPlanner per plan and never kept.
 *
 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
 */
final class LegacyRegisterSweep {
	/**
	 * The registers to remove, id => register, in sweep order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $removed = [];

	/**
	 * The lists to rewrite, register id => remaining list, in sweep order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $rewrites = [];

	/**
	 * Every register, id => {slug, application, list, ids}.
	 *
	 * @var array<int,array{slug:string,application:string|null,list:array<int,mixed>,ids:int[]}>
	 */
	public array $registers = [];

	/**
	 * Constructor for LegacyRegisterSweep.
	 *
	 * @param int[]                     $removedSchemas   The ids of the schemas this plan removes
	 * @param array<int,array<int,string>> $tablesByRegister Register id => its data tables
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $removedSchemas,
		public readonly array $tablesByRegister,
	) {
	}//end __construct()

	/**
	 * The data tables of one register.
	 *
	 * @param int $registerId The register id
	 *
	 * @return string[] Unprefixed table names, in catalogue order
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
	 */
	public function tablesOf(int $registerId): array {
		return $this->tablesByRegister[$registerId] ?? [];
	}//end tablesOf()

	/**
	 * The ids of every register the sweep keeps.
	 *
	 * @return int[]
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
	 */
	public function keptRegisters(): array {
		return array_diff(array_keys($this->registers), array_keys($this->removed));
	}//end keptRegisters()

	/**
	 * The data tables of every register the sweep removes, in sweep order.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
	 */
	public function removedTables(): array {
		return array_merge([], ...array_map(fn (int $id): array => $this->tablesOf(registerId: $id), array_keys($this->removed)));
	}//end removedTables()
}//end class
