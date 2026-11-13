<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The fields an administrator defined on an item type (admin-18), rendered
  by kind: text, hidden (a password field), url and email, and the extra
  fields of a built-in type, which add multiline (a text area that keeps the
  line breaks of a key or certificate). The parent keeps
  the values by field key and stores them in the encrypted additional-fields
  blob; this component only edits them.

  @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
-->
<template>
	<div class="typed-fields" data-testid="typed-fields">
		<template v-for="field in fields" :key="field.key">
			<NcPasswordField
				v-if="field.kind === 'hidden'"
				v-bind="fieldProps(field)"
				@update:modelValue="set(field.key, $event)" />
			<NcTextArea
				v-else-if="field.kind === 'multiline'"
				v-bind="fieldProps(field)"
				rows="4"
				@update:modelValue="set(field.key, $event)" />
			<NcTextField
				v-else
				v-bind="fieldProps(field)"
				:type="inputType(field)"
				@update:modelValue="set(field.key, $event)" />
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcPasswordField, NcTextArea, NcTextField } from '@nextcloud/vue'
import { schemaError } from '../utils/fieldSchema.js'

export default {
	name: 'TypedFieldsForm',
	components: { NcPasswordField, NcTextArea, NcTextField },

	props: {
		/**
		 * The type's fields: {key, label, kind, required}, plus a translated
		 * `displayLabel` on a built-in type's fields.
		 */
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
		 * @param {{label: string, displayLabel?: string, required: boolean}} field The field.
		 * @return {string} The label.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-built-in-field-values-keep-a-fixed-name
		 */
		fieldLabel(field) {
			const label = field.displayLabel ?? field.label
			return field.required
				? t('keepiq', '{label} (required)', { label })
				: label
		},

		/**
		 * What every typed field gets, whatever its kind: the value, the
		 * label with the required marker, required, disabled, the missing
		 * error with its helper text or else the reason the value does not
		 * fit the field's schema, and the test id.
		 *
		 * @param {object} field The field definition.
		 * @return {object} The field's props and test id.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
		 */
		fieldProps(field) {
			const missing = this.isMissing(field)
			const misfit = missing ? '' : this.fitError(field)
			return {
				modelValue: this.values[field.key] || '',
				label: this.fieldLabel(field),
				required: field.required,
				disabled: this.disabled,
				error: missing || misfit !== '',
				helperText: missing ? t('keepiq', 'This field is required') : misfit,
				'data-testid': `typed-field-${field.key}`,
			}
		},

		/**
		 * The input type for a field: its kind, or a number field when its
		 * schema asks for a number.
		 *
		 * @param {{kind: string, schema?: object}} field The field.
		 * @return {string} The HTML input type.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
		 */
		inputType(field) {
			if (['integer', 'number'].includes(field.schema?.type)) {
				return 'number'
			}
			return field.kind === 'url' || field.kind === 'email'
				? field.kind
				: 'text'
		},

		/**
		 * Why a field's value does not fit the schema the field states, or ''.
		 *
		 * @param {{key: string, schema?: object}} field The field.
		 * @return {string} The reason.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
		 */
		fitError(field) {
			return field.schema
				? schemaError(this.values[field.key], field.schema)
				: ''
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
