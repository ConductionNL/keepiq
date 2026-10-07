<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The recipient field of the user share dialogs (keepiq#37). It searches
  Nextcloud users through Nextcloud's own sharee search, so it offers exactly
  the users this instance lets the owner share with, and marks each result
  that cannot receive a share yet because that user has no vault. A marked
  user is shown but cannot be picked.

  There is no list of vault holders to pick from: the marks come from
  `share.searchRecipients`, which asks the server only about the users of
  this one search.

  @spec openspec/specs/user-sharing/spec.md#requirement-recipient-search-marks-who-cannot-receive-a-share
-->
<template>
	<NcSelect
		:modelValue="selected"
		:options="options"
		label="label"
		:inputLabel="t('keepiq', 'Recipient')"
		:selectable="(option) => option.hasSuite === true"
		:filterable="false"
		:loading="loading"
		:disabled="disabled"
		:error="error !== ''"
		:helperText="error"
		data-testid="recipient-picker"
		@update:modelValue="onPick"
		@search="onSearch">
		<template #option="option">
			<span class="recipient-picker__option">
				<span>{{ option.label }}</span>
				<span
					v-if="option.hasSuite !== true"
					class="recipient-picker__no-vault"
					data-testid="recipient-no-vault">
					{{ t('keepiq', 'No vault yet') }}
				</span>
			</span>
		</template>
		<template #no-options>
			{{ t('keepiq', 'No matching users') }}
		</template>
	</NcSelect>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { useShareStore } from '../../store/modules/share.js'

/** Wait this long after the last keystroke before searching. */
export const RECIPIENT_SEARCH_DEBOUNCE_MS = 300

export default {
	name: 'RecipientPicker',
	components: { NcSelect },

	props: {
		/** The picked user id, or '' for none. */
		modelValue: {
			type: String,
			default: '',
		},

		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			options: [],
			loading: false,
			error: '',
			timer: null,
			seq: 0,
		}
	},

	computed: {
		/**
		 * The option for the picked id, kept even after a new search drops it.
		 *
		 * @return {object|null}
		 * @spec exclude View state: maps the bound id onto an option object.
		 */
		selected() {
			if (this.modelValue === '') {
				return null
			}
			return (
				this.options.find((option) => option.id === this.modelValue) ?? {
					id: this.modelValue,
					label: this.modelValue,
					hasSuite: true,
				}
			)
		},
	},

	beforeUnmount() {
		clearTimeout(this.timer)
	},

	methods: {
		/**
		 * Search after a short pause; only the newest search may fill the list.
		 *
		 * @param {string} term What the user typed.
		 * @return {void}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-search-marks-who-cannot-receive-a-share
		 */
		onSearch(term) {
			clearTimeout(this.timer)
			// vue-select emits '' again after a pick; keep the list as it is.
			if (term === '') {
				return
			}
			this.timer = setTimeout(
				() => this.search(term),
				RECIPIENT_SEARCH_DEBOUNCE_MS,
			)
		},

		/**
		 * Run one search and show its marked results.
		 *
		 * @param {string} term The search term.
		 * @return {Promise<void>}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-search-marks-who-cannot-receive-a-share
		 */
		async search(term) {
			const seq = ++this.seq
			this.loading = true
			this.error = ''
			try {
				const found = await useShareStore().searchRecipients(term)
				if (seq === this.seq) {
					this.options = found
				}
			} catch (e) {
				if (seq === this.seq) {
					this.options = []
					this.error = e?.response?.data?.message || e?.message || ''
				}
			} finally {
				if (seq === this.seq) {
					this.loading = false
				}
			}
		},

		/**
		 * Pass the picked user id up; a user without a vault cannot be picked.
		 *
		 * @param {object|null} option The picked option.
		 * @return {void}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-search-marks-who-cannot-receive-a-share
		 */
		onPick(option) {
			if (option && option.hasSuite !== true) {
				return
			}
			this.$emit('update:modelValue', option ? option.id : '')
		},
	},
}
</script>

<style scoped>
.recipient-picker__option {
	display: flex;
	flex-direction: column;
}

.recipient-picker__no-vault {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}
</style>
