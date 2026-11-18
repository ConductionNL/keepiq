<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  One custom field's value on the detail sidebar, shown the way its schema
  says: masked until revealed when hidden, a link for a web or mail address
  (any other URL as text), Yes or No, a
  date in the user's locale, and the read-only JSON viewer for an object. A
  value that does not fit its schema (written through the API, which cannot
  check) shows as plain text with a warning.

  @spec openspec/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
-->
<template>
	<div class="schema-value-view" :data-input="input">
		<template v-if="hidden && input === 'json'">
			<div class="schema-value-view__actions">
				<span v-if="!revealed" class="schema-value-view__masked"
					>••••••••••</span
				>
				<RevealToggle :revealed="revealed" @click="toggle" />
				<CopyButton :value="value" :guard="guard" />
			</div>
			<CnJsonViewer
				v-if="revealed"
				:value="value"
				:readOnly="true"
				height="200px" />
		</template>
		<PasswordField
			v-else-if="hidden"
			:label="label"
			:multiline="input === 'multiline'"
			:guard="guard"
			:resolve="resolve" />
		<template v-else-if="fits === false">
			<span class="schema-value-view__text">{{ value }}</span>
			<span
				class="schema-value-view__warning"
				data-testid="schema-value-misfit">
				{{ t('keepiq', 'This value does not fit its field type.') }}
			</span>
		</template>
		<div v-else-if="input === 'url'" class="schema-value-view__actions">
			<a v-if="href" :href="href" target="_blank" rel="noopener noreferrer">{{
				value
			}}</a>
			<span v-else class="schema-value-view__text">{{ value }}</span>
			<CopyButton :value="value" :guard="guard" />
		</div>
		<template v-else-if="input === 'json'">
			<CnJsonViewer :value="value" :readOnly="true" height="200px" />
			<CopyButton :value="value" :guard="guard" />
		</template>
		<span v-else class="schema-value-view__text">{{ shown }}</span>
		<!-- A masked value keeps its mask; the warning still says it does
		     not fit. -->
		<span
			v-if="hidden && fits === false"
			class="schema-value-view__warning"
			data-testid="schema-value-misfit">
			{{ t('keepiq', 'This value does not fit its field type.') }}
		</span>
	</div>
</template>

<script>
import { CnJsonViewer } from '@conduction/nextcloud-vue'
import { getCanonicalLocale, translate as t } from '@nextcloud/l10n'
import CopyButton from './CopyButton.vue'
import PasswordField from './PasswordField.vue'
import RevealToggle from './RevealToggle.vue'
import { inputOf, isHiddenSchema, schemaError } from '../utils/fieldSchema.js'
import { linkHref } from '../utils/safeLink.js'

export default {
	name: 'SchemaValueView',

	components: {
		CnJsonViewer,
		CopyButton,
		PasswordField,
		RevealToggle,
	},

	props: {
		/** The value as stored. */
		value: {
			type: String,
			default: '',
		},

		/** The field's schema. */
		schema: {
			type: Object,
			default: () => ({ type: 'string' }),
		},

		/** The field's label, for the masked field. */
		label: {
			type: String,
			required: true,
		},

		/** Awaited before a hidden value is revealed or copied (re-prompt). */
		guard: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			revealed: false,
		}
	},

	computed: {
		/**
		 * How the value is shown.
		 *
		 * @return {string} The input.
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
		 */
		input() {
			return inputOf(this.schema)
		},

		/**
		 * Whether the value is masked until revealed.
		 *
		 * @return {boolean} True for a hidden field.
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
		 */
		hidden() {
			return isHiddenSchema(this.schema)
		},

		/**
		 * Whether the value fits its schema.
		 *
		 * @return {boolean} False for a value the API stored without a check.
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
		 */
		fits() {
			return schemaError(this.value, this.schema) === ''
		},

		/**
		 * The link for a URL value: only a web or mail address, so a
		 * `javascript:` or `data:` value is never a live link.
		 *
		 * @return {string|null} The href, or null to show the value as text.
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
		 */
		href() {
			return linkHref(this.value)
		},

		/**
		 * The value as read: Yes or No, a localized date, else as stored.
		 *
		 * @return {string} The text.
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
		 */
		shown() {
			if (this.input === 'boolean') {
				return this.value === 'true' ? t('keepiq', 'Yes') : t('keepiq', 'No')
			}
			// The Nextcloud user's locale, not the browser's.
			if (this.input === 'date') {
				return new Date(`${this.value}T00:00:00`).toLocaleDateString(
					getCanonicalLocale(),
				)
			}
			if (this.input === 'datetime') {
				return new Date(this.value).toLocaleString(getCanonicalLocale())
			}
			return this.value
		},
	},

	methods: {
		t,

		/**
		 * Hand the masked field its value.
		 *
		 * @return {Promise<string>} The value.
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
		 */
		async resolve() {
			return this.value
		},

		/**
		 * Show or hide a hidden JSON value; showing passes the guard first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
		 */
		async toggle() {
			if (!this.revealed && this.guard) {
				try {
					await this.guard()
				} catch {
					return
				}
			}
			this.revealed = !this.revealed
		},
	},
}
</script>

<style scoped>
.schema-value-view {
	min-width: 0;
}

.schema-value-view__actions {
	display: flex;
	align-items: center;
	gap: 4px;
	min-width: 0;
}

.schema-value-view__actions a {
	overflow-wrap: anywhere;
}

.schema-value-view__text {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.schema-value-view__masked {
	letter-spacing: 2px;
}

.schema-value-view__warning {
	display: block;
	margin-top: 2px;
	font-size: 13px;
	color: var(--color-warning-text);
}
</style>
