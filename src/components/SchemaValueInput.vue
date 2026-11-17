<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  One custom field's value, entered the way its schema says: a password field
  when hidden, a text area for multi-line text, a URL, email, number, date or
  time field, a switch for yes/no, and the JSON editor for an object. A hidden
  multi-line or JSON value stays out of the page until Show. The value stays a
  string either way; the parent stores it.

  @spec openspec/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
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
			<div class="schema-value-input__json">
				<CnJsonViewer
					v-if="jsonShown"
					class="schema-value-input__json-field"
					:value="modelValue"
					:readOnly="disabled"
					height="160px"
					:errorText="error"
					:data-testid="testid"
					@update:value="onJsonInput" />
				<NcTextField
					v-else
					class="schema-value-input__json-field"
					modelValue="••••••••••"
					:label="label"
					:error="error !== ''"
					:helperText="error"
					readonly
					data-testid="schema-value-input-json-mask" />
				<NcButton
					v-if="hidden"
					variant="tertiary"
					:aria-label="jsonShown ? t('keepiq', 'Hide') : t('keepiq', 'Show')"
					:title="jsonShown ? t('keepiq', 'Hide') : t('keepiq', 'Show')"
					data-testid="schema-value-input-json-toggle"
					@click="jsonRevealed = !jsonShown">
					<template #icon>
						<EyeOff v-if="jsonShown" :size="20" />
						<Eye v-else :size="20" />
					</template>
				</NcButton>
			</div>
		</template>
		<MaskedTextArea
			v-else-if="hidden && input === 'multiline'"
			v-bind="fieldAttrs"
			:label="label"
			rows="4"
			@update:modelValue="$emit('update:modelValue', $event)" />
		<NcPasswordField
			v-else-if="hidden"
			v-bind="fieldAttrs"
			:label="label"
			autocomplete="off"
			@update:modelValue="$emit('update:modelValue', $event)" />
		<NcTextArea
			v-else-if="input === 'multiline'"
			v-bind="fieldAttrs"
			:label="label"
			rows="4"
			@update:modelValue="$emit('update:modelValue', $event)" />
		<NcTextField
			v-else
			v-bind="fieldAttrs"
			:label="label"
			:type="htmlType"
			:step="input === 'number' ? 'any' : undefined"
			@update:modelValue="$emit('update:modelValue', $event)" />
	</div>
</template>

<script>
import { CnJsonViewer } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcPasswordField,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import MaskedTextArea from './MaskedTextArea.vue'
import { inputOf, inputTypeFor, isHiddenSchema } from '../utils/fieldSchema.js'

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
		Eye,
		EyeOff,
		MaskedTextArea,
		NcButton,
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

	data() {
		return {
			// A hidden JSON value is not in the page until Show; an empty one
			// starts open so it can be typed at once.
			jsonRevealed: this.modelValue === '',
			jsonTyped: false,
		}
	},

	computed: {
		/**
		 * Whether the JSON editor shows: always for a visible field, after
		 * Show for a hidden one that holds a value.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
		 */
		jsonShown() {
			return !this.hidden || this.jsonRevealed
		},

		/**
		 * How the value is entered.
		 *
		 * @return {string} The input.
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
		 */
		input() {
			return inputOf(this.schema)
		},

		/**
		 * Whether the value is masked while it is typed.
		 *
		 * @return {boolean} True for a hidden field.
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
		 */
		hidden() {
			return isHiddenSchema(this.schema)
		},

		/**
		 * The HTML input type of the plain text field; text while the value is
		 * one that input could not show, so a stored value is never hidden.
		 *
		 * @return {string} The type.
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
		 */
		htmlType() {
			return inputTypeFor(HTML_TYPES[this.input] ?? 'text', this.modelValue)
		},

		/**
		 * What the password, text area and text inputs share: the value, the
		 * disabled state, the error and the test id.
		 *
		 * @return {object} The attributes.
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
		 */
		fieldAttrs() {
			return {
				modelValue: this.modelValue,
				disabled: this.disabled,
				error: this.error !== '',
				helperText: this.error,
				'data-testid': this.testid,
			}
		},
	},

	watch: {
		/**
		 * Mask a hidden JSON value that arrives after the field opened
		 * empty, as a loaded secret does, unless the user typed it.
		 *
		 * @param {string} value The new value.
		 * @param {string} previous The old value.
		 * @return {void}
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
		 */
		modelValue(value, previous) {
			if (!this.jsonTyped && previous === '' && value !== '') {
				this.jsonRevealed = false
			}
		},
	},

	methods: {
		t,

		/**
		 * Pass a typed JSON value on.
		 *
		 * @param {string} value The value.
		 * @return {void}
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
		 */
		onJsonInput(value) {
			this.jsonTyped = true
			this.$emit('update:modelValue', value)
		},
	},
}
</script>

<style scoped>
.schema-value-input {
	min-width: 0;
}

.schema-value-input__json {
	display: flex;
	align-items: flex-start;
	gap: 4px;
}

.schema-value-input__json-field {
	flex: 1 1 auto;
	min-width: 0;
}

.schema-value-input__label {
	display: block;
	margin-block-end: 4px;
	color: var(--color-text-maxcontrast);
}
</style>
