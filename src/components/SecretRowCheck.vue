<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The bulk-selection checkbox of one secret row, the same in the secret
  list's list and card views. The capture-phase click listener only reports
  the shift state; update:modelValue reports the toggle exactly once (a raw
  click handler would double-fire through the label's forwarded click).
-->
<template>
	<NcCheckboxRadioSwitch
		:modelValue="bulkStore.selectedIds.includes(secret.id)"
		:aria-label="t('keepiq', 'Select {name}', { name: secret.name })"
		:data-testid="`bulk-check-${secret.id}`"
		@click.capture="$emit('shift', $event.shiftKey)"
		@update:modelValue="$emit('check')" />
</template>

<script>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { useBulkStore } from '../store/modules/bulk.js'

/**
 * Select or deselect one secret for a bulk action.
 */
export default {
	name: 'SecretRowCheck',

	components: { NcCheckboxRadioSwitch },

	props: {
		/** The row's secret ({id, name}). */
		secret: {
			type: Object,
			required: true,
		},
	},

	emits: ['shift', 'check'],

	computed: {
		/**
		 * The bulk selection store.
		 *
		 * @return {object}
		 * @spec openspec/specs/bulk-actions/spec.md#requirement-multi-select-and-bulk-action-bar
		 */
		bulkStore() {
			return useBulkStore()
		},
	},
}
</script>
