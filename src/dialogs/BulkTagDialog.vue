<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Add one tag to, or remove it from, the selected secrets
  (vault-favourites-tags-and-last-used). Secrets that already have the tag,
  or do not have it, are left alone.

  @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Tags for {count} secrets', { count })"
		:open="open"
		size="normal"
		data-testid="bulk-tag-dialog"
		@update:open="$emit('close')">
		<div class="bulk-tag">
			<NcSelect
				v-model="tag"
				:options="options"
				:inputLabel="t('keepiq', 'Tag')"
				:taggable="true"
				:disabled="running"
				data-testid="bulk-tag-select" />
			<p class="bulk-tag__help">
				{{
					t(
						'keepiq',
						'Tags are not encrypted. Server administrators can read them, as they can folder names.',
					)
				}}
			</p>
			<NcNoteCard v-if="error" type="error" data-testid="bulk-tag-error">
				{{ error }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton
				variant="tertiary"
				data-testid="bulk-tag-cancel"
				@click="$emit('close')">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="secondary"
				:disabled="!wanted || running"
				data-testid="bulk-tag-remove"
				@click="run(false)">
				{{ t('keepiq', 'Remove tag') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!wanted || running"
				data-testid="bulk-tag-add"
				@click="run(true)">
				{{ t('keepiq', 'Add tag') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { useBulkStore } from '../store/modules/bulk.js'
import { useSecretStore } from '../store/modules/secret.js'
import { normaliseTags } from '../utils/tags.js'

export default {
	name: 'BulkTagDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'done'],

	data() {
		return {
			/** The picked or typed tag. */
			tag: null,
			running: false,
			error: '',
		}
	},

	computed: {
		/**
		 * How many secrets are selected.
		 *
		 * @return {number}
		 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
		 */
		count() {
			return useBulkStore().selectionCount
		},

		/**
		 * The user's existing tags.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
		 */
		options() {
			return useSecretStore().tags.map((entry) => entry.tag)
		},

		/**
		 * The tag in stored form, or empty.
		 *
		 * @return {string}
		 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
		 */
		wanted() {
			return normaliseTags([this.tag])[0] || ''
		},
	},

	methods: {
		t,

		/**
		 * Add or remove the tag on every selected secret, then close.
		 *
		 * @param {boolean} add True to add, false to remove.
		 * @return {Promise<void>}
		 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
		 */
		async run(add) {
			this.running = true
			this.error = ''
			try {
				await useSecretStore().changeTagInBulk(
					useBulkStore().selectedIds,
					this.wanted,
					add,
				)
				this.$emit('done')
				this.$emit('close')
			} catch {
				this.error = t('keepiq', 'Could not change the tags. Try again.')
			} finally {
				this.running = false
			}
		},
	},
}
</script>

<style scoped>
.bulk-tag {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 4px 0;
}

.bulk-tag__help {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
