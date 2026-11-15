<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Merge one duplicate group (vault-duplicate-finder): the person picks the
  item to keep, sees the additional fields it will carry and how many people
  lose access through shares of the items being removed, and confirms. The
  kept item is saved with the others' fields folded in and the others move to
  the trash. Likely duplicates have no item picked in advance.

  @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Merge duplicates')"
		:open="open"
		size="normal"
		data-testid="duplicate-merge-modal"
		@update:open="$emit('close')">
		<div class="duplicate-merge">
			<NcLoadingIcon v-if="loading" :size="32" />
			<template v-else>
				<fieldset class="duplicate-merge__keep">
					<legend>{{ t('keepiq', 'Item to keep') }}</legend>
					<NcCheckboxRadioSwitch
						v-for="item in secrets"
						:key="item.id"
						v-model="keepId"
						type="radio"
						name="duplicate-keep"
						:value="item.id"
						:data-testid="`duplicate-keep-${item.id}`">
						{{ item.name }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<template v-if="kept">
					<h4>{{ t('keepiq', 'Additional fields after the merge') }}</h4>
					<p v-if="fieldNames.length === 0" data-testid="duplicate-merge-no-fields">
						{{ t('keepiq', 'No additional fields yet.') }}
					</p>
					<ul v-else class="duplicate-merge__fields" data-testid="duplicate-merge-fields">
						<li v-for="name in fieldNames" :key="name">
							{{ name }}
						</li>
					</ul>

					<p
v-if="peopleLosingAccess !== null"
						class="duplicate-merge__access"
						data-testid="duplicate-merge-access">
						{{ t('keepiq', 'People who lose access: {count}', { count: peopleLosingAccess }) }}
					</p>
					<p class="duplicate-merge__trash">
						{{ t('keepiq', 'The other items move to the trash, where you can restore them.') }}
					</p>
				</template>

				<p v-if="error" class="duplicate-merge__error" role="alert">
					{{ error }}
				</p>
			</template>
		</div>
		<template #actions>
			<NcButton variant="tertiary" :disabled="merging" @click="$emit('close')">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!kept || merging || peopleLosingAccess === null"
				data-testid="duplicate-merge-confirm"
				@click="merge">
				{{ t('keepiq', 'Merge') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import { loadGroup, mergedFields, mergeGroup, peopleLosingAccess } from '../health/merge.js'

export default {
	name: 'DuplicateMergeModal',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon },
	props: {
		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			default: false,
		},

		/** The duplicate group: kind (exact or likely) and its items. */
		group: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'merged'],

	data() {
		return {
			loading: true,
			secrets: [],
			keepId: null,
			peopleLosingAccess: null,
			merging: false,
			error: '',
		}
	},

	computed: {
		/**
		 * @spec exclude Display glue — the decrypted secret picked to keep.
		 */
		kept() {
			return this.secrets.find((s) => s.id === this.keepId) ?? null
		},

		/**
		 * @spec exclude Display glue — the decrypted secrets that will be removed.
		 */
		others() {
			return this.secrets.filter((s) => s.id !== this.keepId)
		},

		/**
		 * The names of the additional fields the kept item will carry; values stay hidden.
		 *
		 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
		 */
		fieldNames() {
			return this.kept ? Object.keys(mergedFields(this.kept, this.others)) : []
		},
	},

	watch: {
		/**
		 * @spec exclude Display glue — recounts who loses access when another item is kept.
		 */
		keepId() {
			this.countPeople()
		},
	},

	/**
	 * Decrypt the group's items; an exact group starts with the first one picked.
	 *
	 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
	 */
	async mounted() {
		try {
			this.secrets = await loadGroup(this.group.items.map((item) => item.id))
			if (this.group.kind === 'exact' && this.secrets.length) {
				this.keepId = this.secrets[0].id
			}
		} catch {
			this.error = this.t('keepiq', 'The merge failed. Nothing was removed.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Count the people who lose access through shares of the items being removed.
		 *
		 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
		 */
		async countPeople() {
			this.peopleLosingAccess = null
			const keepId = this.keepId
			try {
				const count = await peopleLosingAccess(this.others.map((s) => s.id))
				if (keepId === this.keepId) {
					this.peopleLosingAccess = count
				}
			} catch {
				this.error = this.t('keepiq', 'The merge failed. Nothing was removed.')
			}
		},

		/**
		 * Merge: save the kept item with the folded fields, then move the others to the trash.
		 *
		 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
		 */
		async merge() {
			this.merging = true
			this.error = ''
			try {
				const { removed } = await mergeGroup(this.kept, this.others)
				this.$emit('merged', { kept: this.kept.id, removed })
			} catch {
				this.error = this.t('keepiq', 'The merge did not complete. Check the items of this group in your vault.')
			} finally {
				this.merging = false
			}
		},
	},
}
</script>

<style scoped>
.duplicate-merge {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 4px 12px 12px;
}

.duplicate-merge__keep {
	border: none;
	padding: 0;
}

.duplicate-merge__fields {
	margin: 0;
	padding-inline-start: 20px;
}

.duplicate-merge__access {
	font-weight: bold;
}

.duplicate-merge__error {
	color: var(--color-error-text);
}
</style>
