<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The Advanced part of a custom field: its type, the format of a string, the
  limits that fit the type, and the Hidden switch. The type and format names
  are JSON Schema's, as in OpenRegister's property editor, so they are not
  translated. Emits the whole schema on every change.

  @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
-->
<template>
	<div class="schema-advanced" :data-testid="testid">
		<div class="schema-advanced__line">
			<NcSelect
				:modelValue="schema.type"
				:options="typeOptions"
				:inputLabel="t('keepiq', 'Type')"
				:reduce="(opt) => opt.value"
				:clearable="false"
				:disabled="disabled"
				data-testid="schema-type"
				@update:modelValue="set('type', $event)" />
			<!--
			  "None" is the placeholder rather than an option: NcSelect drops an
			  empty-string model value, so an option valued '' would look as
			  though nothing were selected. Clearing it removes the format.
			-->
			<NcSelect
				v-if="schema.type === 'string'"
				:modelValue="schema.format ?? null"
				:options="formatOptions"
				:inputLabel="t('keepiq', 'Format')"
				:placeholder="t('keepiq', 'None')"
				:reduce="(opt) => opt.value"
				:disabled="disabled"
				data-testid="schema-format"
				@update:modelValue="set('format', $event)" />
		</div>
		<div v-if="schema.type === 'string'" class="schema-advanced__line">
			<NcTextField
				:modelValue="numberText(schema.minLength)"
				type="number"
				min="0"
				:label="t('keepiq', 'Minimum length')"
				:disabled="disabled"
				data-testid="schema-min-length"
				@update:modelValue="set('minLength', $event)" />
			<NcTextField
				:modelValue="numberText(schema.maxLength)"
				type="number"
				min="0"
				:label="t('keepiq', 'Maximum length')"
				:disabled="disabled"
				data-testid="schema-max-length"
				@update:modelValue="set('maxLength', $event)" />
			<NcTextField
				:modelValue="schema.pattern ?? ''"
				:label="t('keepiq', 'Pattern')"
				:disabled="disabled"
				data-testid="schema-pattern"
				@update:modelValue="set('pattern', $event)" />
		</div>
		<div v-if="isNumeric" class="schema-advanced__line">
			<NcTextField
				:modelValue="numberText(schema.minimum)"
				type="number"
				:label="t('keepiq', 'Minimum')"
				:disabled="disabled"
				data-testid="schema-minimum"
				@update:modelValue="set('minimum', $event)" />
			<NcTextField
				:modelValue="numberText(schema.maximum)"
				type="number"
				:label="t('keepiq', 'Maximum')"
				:disabled="disabled"
				data-testid="schema-maximum"
				@update:modelValue="set('maximum', $event)" />
			<NcTextField
				v-if="schema.type === 'number'"
				:modelValue="numberText(schema.multipleOf)"
				type="number"
				min="0"
				:label="t('keepiq', 'Multiple of')"
				:disabled="disabled"
				data-testid="schema-multiple-of"
				@update:modelValue="set('multipleOf', $event)" />
		</div>
		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="schema.hidden === true"
			:disabled="disabled"
			data-testid="schema-hidden"
			@update:modelValue="set('hidden', $event === true)">
			{{ t('keepiq', 'Hidden') }}
		</NcCheckboxRadioSwitch>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import { cleanSchema, SCHEMA_TYPES, STRING_FORMATS } from '../utils/fieldSchema.js'

export default {
	name: 'SchemaAdvanced',

	components: {
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The field's schema, clean. */
		schema: {
			type: Object,
			required: true,
		},

		/** Whether editing is blocked. */
		disabled: {
			type: Boolean,
			default: false,
		},

		/** The test id the part carries. */
		testid: {
			type: String,
			default: undefined,
		},
	},

	emits: ['update:schema'],

	computed: {
		/**
		 * The type options, by their JSON Schema names.
		 *
		 * @return {Array<{label: string, value: string}>} The options.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
		 */
		typeOptions() {
			return SCHEMA_TYPES.map((type) => ({ label: type, value: type }))
		},

		/**
		 * The format options of a string, by their JSON Schema names.
		 *
		 * @return {Array<{label: string, value: string}>} The options.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
		 */
		formatOptions() {
			return STRING_FORMATS.map((format) => ({ label: format, value: format }))
		},

		/**
		 * Whether the type takes a minimum and maximum.
		 *
		 * @return {boolean} True for integer and number.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
		 */
		isNumeric() {
			return this.schema.type === 'integer' || this.schema.type === 'number'
		},
	},

	methods: {
		t,

		/**
		 * A limit as the text its field shows.
		 *
		 * @param {number|undefined} value The limit.
		 * @return {string} The text; '' when unset.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
		 */
		numberText(value) {
			return value === undefined ? '' : String(value)
		},

		/**
		 * Emit the schema with one key changed; an empty value removes the key.
		 *
		 * @param {string} key The key.
		 * @param {string|boolean|null|undefined} value Its new value.
		 * @return {void}
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
		 */
		set(key, value) {
			const next = { ...this.schema }
			if (
				value === ''
				|| value === null
				|| value === undefined
				|| value === false
			) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:schema', cleanSchema(next))
		},
	},
}
</script>

<style scoped>
.schema-advanced {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding: 0.5rem 0.75rem;
	border-inline-start: 2px solid var(--color-border);
}

.schema-advanced__line {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 0.5rem;
}

.schema-advanced__line > * {
	flex: 1 1 10rem;
	min-width: 0;
}
</style>
