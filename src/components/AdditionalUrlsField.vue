<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The extra website addresses of a login in the create and edit dialogs
  (vault-website-addresses). One row per address, at most twenty. The
  addresses are plain text like the main address (design D1), and the help
  text says so where the user types them.

  @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
-->
<template>
	<fieldset class="additional-urls-field" data-testid="additional-urls-field">
		<legend class="additional-urls-field__legend">
			{{ t('keepiq', 'More website addresses') }}
		</legend>
		<div
			v-for="(url, index) in rows"
			:key="index"
			class="additional-urls-field__row">
			<NcTextField
				:modelValue="url"
				:label="
					t('keepiq', 'Website address {number}', { number: index + 2 })
				"
				:maxlength="maxLength"
				:disabled="disabled"
				data-testid="additional-url-input"
				@update:modelValue="(value) => onEdit(index, value)" />
			<NcButton
				variant="tertiary"
				:aria-label="t('keepiq', 'Remove this address')"
				:disabled="disabled"
				data-testid="additional-url-remove"
				@click="onRemove(index)">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</div>
		<NcButton
			variant="secondary"
			:disabled="disabled || rows.length >= maxUrls"
			data-testid="additional-url-add"
			@click="onAdd">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ t('keepiq', 'Add a website address') }}
		</NcButton>
		<p class="additional-urls-field__help" data-testid="additional-urls-help">
			{{
				t(
					'keepiq',
					'The login is suggested and found on each of these addresses. Website addresses are not encrypted. Server administrators can read them, as they can the main address.',
				)
			}}
		</p>
	</fieldset>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { MAX_LENGTH, MAX_URLS, normaliseUrls } from '../utils/additionalUrls.js'

export default {
	name: 'AdditionalUrlsField',

	components: {
		Close,
		NcButton,
		NcTextField,
		Plus,
	},

	props: {
		/** The extra addresses as stored. */
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** Whether the field is read-only for now (saving or loading). */
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			/** The rows as typed, empty ones included until the dialog saves. */
			rows: [...this.modelValue],
			maxUrls: MAX_URLS,
			maxLength: MAX_LENGTH,
		}
	},

	watch: {
		modelValue(value) {
			if (
				normaliseUrls(value).join('\n')
				!== normaliseUrls(this.rows).join('\n')
			) {
				this.rows = [...value]
			}
		},
	},

	methods: {
		t,

		/**
		 * Add an empty row to type in.
		 *
		 * @return {void}
		 */
		onAdd() {
			if (this.rows.length < MAX_URLS) {
				this.rows.push('')
			}
		},

		/**
		 * Change one row and emit the stored list.
		 *
		 * @param {number} index The row.
		 * @param {string} value The typed address.
		 * @return {void}
		 */
		onEdit(index, value) {
			this.rows.splice(index, 1, value)
			this.emitList()
		},

		/**
		 * Drop one row and emit the stored list.
		 *
		 * @param {number} index The row.
		 * @return {void}
		 */
		onRemove(index) {
			this.rows.splice(index, 1)
			this.emitList()
		},

		/**
		 * Emit the rows in stored form.
		 *
		 * @return {void}
		 */
		emitList() {
			this.$emit('update:modelValue', normaliseUrls(this.rows))
		},
	},
}
</script>

<style scoped>
.additional-urls-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	border: none;
	padding: 0;
	margin: 0;
}

.additional-urls-field__legend {
	font-weight: bold;
	padding: 0;
}

.additional-urls-field__row {
	display: flex;
	align-items: flex-end;
	gap: 4px;
}

.additional-urls-field__help {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
