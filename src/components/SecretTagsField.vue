<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The tags field of the create and edit dialogs
  (vault-favourites-tags-and-last-used). Picks from the user's existing tags
  or takes a new one. Tags are plain text by design D2, and the help text
  says so where the user types them.

  @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
-->
<template>
	<div class="secret-tags-field">
		<NcSelect
			:modelValue="modelValue"
			:options="options"
			:inputLabel="t('keepiq', 'Tags')"
			:multiple="true"
			:taggable="true"
			:disabled="disabled"
			:ariaLabelCombobox="t('keepiq', 'Tags')"
			data-testid="secret-tags-field"
			@update:modelValue="onUpdate" />
		<p class="secret-tags-field__help" data-testid="secret-tags-help">
			{{
				t(
					'keepiq',
					'Tags are not encrypted. Server administrators can read them, as they can folder names.',
				)
			}}
		</p>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { useSecretStore } from '../store/modules/secret.js'
import { normaliseTags } from '../utils/tags.js'

export default {
	name: 'SecretTagsField',

	components: {
		NcSelect,
	},

	props: {
		/** The tags currently set. */
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

	computed: {
		/**
		 * The user's existing tags, offered for picking.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
		 */
		options() {
			return useSecretStore().tags.map((entry) => entry.tag)
		},
	},

	methods: {
		t,

		/**
		 * Emit the tags in stored form: trimmed, lowercase, unique, at most
		 * 32 characters each and 20 in all.
		 *
		 * @param {Array<string>|null} value The picked and typed tags.
		 * @return {void}
		 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
		 */
		onUpdate(value) {
			this.$emit('update:modelValue', normaliseTags(value))
		},
	},
}
</script>

<style scoped>
.secret-tags-field__help {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
