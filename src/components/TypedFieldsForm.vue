<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The fields an administrator defined on an item type (admin-18), rendered
  by kind: text, hidden (a password field), url and email. The parent keeps
  the values by field key and stores them in the encrypted additional-fields
  blob; this component only edits them.

  @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
-->
<template>
	<div class="typed-fields" data-testid="typed-fields">
		<template v-for="field in fields" :key="field.key">
			<NcPasswordField
				v-if="field.kind === 'hidden'"
				:modelValue="values[field.key] || ''"
				:label="fieldLabel(field)"
				:required="field.required"
				:disabled="disabled"
				:error="isMissing(field)"
				:helperText="
					isMissing(field) ? t('keepiq', 'This field is required') : ''
				"
				:data-testid="`typed-field-${field.key}`"
				@update:modelValue="set(field.key, $event)" />
			<NcTextField
				v-else
				:modelValue="values[field.key] || ''"
				:type="inputType(field.kind)"
				:label="fieldLabel(field)"
				:required="field.required"
				:disabled="disabled"
				:error="isMissing(field)"
				:helperText="
					isMissing(field) ? t('keepiq', 'This field is required') : ''
				"
				:data-testid="`typed-field-${field.key}`"
				@update:modelValue="set(field.key, $event)" />
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcPasswordField, NcTextField } from '@nextcloud/vue'

export default {
	name: 'TypedFieldsForm',
	components: { NcPasswordField, NcTextField },

	props: {
		/** The type's fields: {key, label, kind, required}. */
		fields: {
			type: Array,
			required: true,
		},

		/** The values by field key. */
		values: {
			type: Object,
			required: true,
		},

		/** Keys of required fields to mark as missing. */
		missing: {
			type: Array,
			default: () => [],
		},

		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:values'],

	methods: {
		t,

		/**
		 * The label shown for a field, with a marker when it is required.
		 *
		 * @param {{label: string, required: boolean}} field The field.
		 * @return {string} The label.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		fieldLabel(field) {
			return field.required
				? t('keepiq', '{label} (required)', { label: field.label })
				: field.label
		},

		/**
		 * The input type for a field kind.
		 *
		 * @param {string} kind text, url or email.
		 * @return {string} The HTML input type.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		inputType(kind) {
			return kind === 'url' || kind === 'email' ? kind : 'text'
		},

		/**
		 * Whether a field is marked as missing.
		 *
		 * @param {{key: string}} field The field.
		 * @return {boolean} True when the parent flagged it.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		isMissing(field) {
			return this.missing.includes(field.key)
		},

		/**
		 * Emit the values with one field changed.
		 *
		 * @param {string} key The field key.
		 * @param {string} value The new value.
		 * @return {void}
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-typed-fields-storage
		 */
		set(key, value) {
			this.$emit('update:values', { ...this.values, [key]: value })
		},
	},
}
</script>

<style scoped>
.typed-fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
