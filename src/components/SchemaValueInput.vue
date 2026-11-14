<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  One custom field's value, entered the way its schema says: a password field
  when hidden, a text area for multi-line text, a URL, email, number, date or
  time field, a switch for yes/no, and the JSON editor for an object. The value
  stays a string either way; the parent stores it.

  @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
-->
<template>
	<div class="schema-value-input" :data-input="input">
		<NcCheckboxRadioSwitch
			v-if="input === 'boolean'"
			type="switch"
			:modelValue="modelValue === 'true'"
			:disabled="disabled"
			:data-testid="testid"
			@update:modelValue="
				$emit('update:modelValue', $event ? 'true' : 'false')
			">
			{{ label }}
		</NcCheckboxRadioSwitch>
		<template v-else-if="input === 'json'">
			<span class="schema-value-input__label">{{ label }}</span>
			<CnJsonViewer
				:value="modelValue"
				:readOnly="disabled"
				height="160px"
				:errorText="error"
				:data-testid="testid"
				@update:value="$emit('update:modelValue', $event)" />
		</template>
		<NcPasswordField
			v-else-if="hidden && input !== 'multiline'"
			:modelValue="modelValue"
			:label="label"
			:disabled="disabled"
			:error="error !== ''"
			:helperText="error"
			:data-testid="testid"
			@update:modelValue="$emit('update:modelValue', $event)" />
		<NcTextArea
			v-else-if="input === 'multiline'"
			:modelValue="modelValue"
			:label="label"
			:disabled="disabled"
			:error="error !== ''"
			:helperText="error"
			rows="4"
			:data-testid="testid"
			@update:modelValue="$emit('update:modelValue', $event)" />
		<NcTextField
			v-else
			:modelValue="modelValue"
			:type="htmlType"
			:label="label"
			:disabled="disabled"
			:error="error !== ''"
			:helperText="error"
			:data-testid="testid"
			@update:modelValue="$emit('update:modelValue', $event)" />
	</div>
</template>

<script>
import { CnJsonViewer } from '@conduction/nextcloud-vue'
import {
	NcCheckboxRadioSwitch,
	NcPasswordField,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { inputOf, isHiddenSchema } from '../utils/fieldSchema.js'

/** The HTML input type for each input that uses a plain text field. */
const HTML_TYPES = Object.freeze({
	url: 'url',
	email: 'email',
	integer: 'number',
	number: 'number',
	date: 'date',
	datetime: 'datetime-local',
	time: 'time',
})

export default {
	name: 'SchemaValueInput',

	components: {
		CnJsonViewer,
		NcCheckboxRadioSwitch,
		NcPasswordField,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The value as stored. */
		modelValue: {
			type: String,
			default: '',
		},

		/** The field's schema. */
		schema: {
			type: Object,
			default: () => ({ type: 'string' }),
		},

		/** The label the input carries. */
		label: {
			type: String,
			required: true,
		},

		/** Why the value does not fit, or ''. */
		error: {
			type: String,
			default: '',
		},

		/** Whether editing is blocked. */
		disabled: {
			type: Boolean,
			default: false,
		},

		/** The test id the input carries. */
		testid: {
			type: String,
			default: undefined,
		},
	},

	emits: ['update:modelValue'],

	computed: {
		/**
		 * How the value is entered.
		 *
		 * @return {string} The input.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
		 */
		input() {
			return inputOf(this.schema)
		},

		/**
		 * Whether the value is masked while it is typed.
		 *
		 * @return {boolean} True for a hidden field.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
		 */
		hidden() {
			return isHiddenSchema(this.schema)
		},

		/**
		 * The HTML input type of the plain text field.
		 *
		 * @return {string} The type.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
		 */
		htmlType() {
			return HTML_TYPES[this.input] ?? 'text'
		},
	},
}
</script>

<style scoped>
.schema-value-input {
	min-width: 0;
}

.schema-value-input__label {
	display: block;
	margin-block-end: 4px;
	color: var(--color-text-maxcontrast);
}
</style>
