<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The column mapping of a generic CSV import: one select per column of the
  file, pre-filled with the detected field. Changing a select re-parses the
  file through the import store, so the preview below always shows what will
  be imported. Shown only for formats that report `adjustableMapping`.

  @spec openspec/specs/portability-import-mapping/spec.md#requirement-adjustable-csv-mapping
-->
<template>
	<section class="import-column-mapping" data-testid="import-column-mapping">
		<h4>{{ t('keepiq', 'Columns') }}</h4>
		<ul class="import-column-mapping__list">
			<li
				v-for="(entry, index) in store.mapping"
				:key="`${index}-${entry.column}`"
				class="import-column-mapping__row">
				<NcSelect
					:modelValue="entry.target"
					:options="targetOptions"
					:inputLabel="entry.column || t('keepiq', 'Column {number}', { number: index + 1 })"
					label="label"
					:reduce="(opt) => opt.value"
					:clearable="false"
					:data-testid="`import-mapping-${index}`"
					@update:modelValue="onChange(index, $event)" />
			</li>
		</ul>
		<NcNoteCard
			v-if="!store.mappingHasName"
			type="warning"
			data-testid="import-mapping-no-name">
			{{ t('keepiq', 'Map one column to Name. Every secret needs a name.') }}
		</NcNoteCard>
	</section>
</template>

<script>
import { NcNoteCard, NcSelect } from '@nextcloud/vue'
import { useImportStore } from '../../store/modules/import.js'

export default {
	name: 'ColumnMapping',
	components: { NcNoteCard, NcSelect },

	data() {
		return {
			store: useImportStore(),
		}
	},

	computed: {
		/**
		 * The fields a column can be imported into.
		 *
		 * @return {Array<{value: string, label: string}>}
		 */
		targetOptions() {
			return [
				{ value: 'name', label: t('keepiq', 'Name') },
				{ value: 'url', label: t('keepiq', 'URL') },
				{ value: 'login', label: t('keepiq', 'Login') },
				{ value: 'password', label: t('keepiq', 'Password') },
				{ value: 'notes', label: t('keepiq', 'Notes') },
				{ value: 'folder', label: t('keepiq', 'Folder') },
				{ value: 'type', label: t('keepiq', 'Type') },
				{ value: 'ignore', label: t('keepiq', 'Do not import') },
			]
		},
	},

	methods: {
		/**
		 * Map one column to another field and re-parse. A single-valued field
		 * moves: the column that held it before falls back to Do not import.
		 *
		 * @param {number} index The column index.
		 * @param {string} target The new target field.
		 * @return {Promise<void>}
		 * @spec openspec/specs/portability-import-mapping/spec.md#requirement-adjustable-csv-mapping
		 */
		async onChange(index, target) {
			const moves = target !== 'ignore' && target !== 'notes'
			const mapping = this.store.mapping.map((entry, i) => {
				if (i === index) {
					return { ...entry, target }
				}
				if (moves && entry.target === target) {
					return { ...entry, target: 'ignore' }
				}
				return entry
			})
			await this.store.applyMapping(mapping).catch(() => {})
		},
	},
}
</script>

<style scoped>
.import-column-mapping__list {
	list-style: none;
	padding: 0;
	margin: 0 0 12px;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 8px;
}
</style>
