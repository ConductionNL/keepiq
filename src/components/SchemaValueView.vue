<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  One custom field's value on the detail sidebar, shown the way its schema
  says: masked until revealed when hidden, a link for a web or mail address
  (any other URL as text), Yes or No, a
  date in the user's locale, and the read-only JSON viewer for an object. A
  value that does not fit its schema (written through the API, which cannot
  check) shows as plain text with a warning.

  @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
-->
<template>
	<div class="schema-value-view" :data-input="input">
		<template v-if="hidden && input === 'json'">
			<div class="schema-value-view__actions">
				<span v-if="!revealed" class="schema-value-view__masked"
					>••••••••••</span
				>
				<NcButton
					variant="tertiary"
					:aria-label="
						revealed ? t('keepiq', 'Hide') : t('keepiq', 'Show')
					"
					@click="revealed = !revealed">
					<template #icon>
						<EyeOff v-if="revealed" :size="20" />
						<Eye v-else :size="20" />
					</template>
				</NcButton>
				<CopyButton :value="value" />
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
			<CopyButton :value="value" />
		</div>
		<template v-else-if="input === 'json'">
			<CnJsonViewer :value="value" :readOnly="true" height="200px" />
			<CopyButton :value="value" />
		</template>
		<span v-else class="schema-value-view__text">{{ shown }}</span>
	</div>
</template>

<script>
import { CnJsonViewer } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import CopyButton from './CopyButton.vue'
import PasswordField from './PasswordField.vue'
import { inputOf, isHiddenSchema, schemaError } from '../utils/fieldSchema.js'
import { linkHref } from '../utils/safeLink.js'

export default {
	name: 'SchemaValueView',

	components: {
		CnJsonViewer,
		CopyButton,
		Eye,
		EyeOff,
		NcButton,
		PasswordField,
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
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
		 */
		input() {
			return inputOf(this.schema)
		},

		/**
		 * Whether the value is masked until revealed.
		 *
		 * @return {boolean} True for a hidden field.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
		 */
		hidden() {
			return isHiddenSchema(this.schema)
		},

		/**
		 * Whether the value fits its schema.
		 *
		 * @return {boolean} False for a value the API stored without a check.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
		 */
		fits() {
			return schemaError(this.value, this.schema) === ''
		},

		/**
		 * The link for a URL value: only a web or mail address, so a
		 * `javascript:` or `data:` value is never a live link.
		 *
		 * @return {string|null} The href, or null to show the value as text.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
		 */
		href() {
			return linkHref(this.value)
		},

		/**
		 * The value as read: Yes or No, a localized date, else as stored.
		 *
		 * @return {string} The text.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
		 */
		shown() {
			if (this.input === 'boolean') {
				return this.value === 'true' ? t('keepiq', 'Yes') : t('keepiq', 'No')
			}
			if (this.input === 'date') {
				return new Date(`${this.value}T00:00:00`).toLocaleDateString()
			}
			if (this.input === 'datetime') {
				return new Date(this.value).toLocaleString()
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
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
		 */
		async resolve() {
			return this.value
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
