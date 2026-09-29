<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Group-share form. The owner picks a Nextcloud group from a search (a
  typo cannot target the wrong group) and confirms. The group-share store
  creates the group share, encrypts the secret in this tab for every
  member with an encryption suite, and registers the copies. The form
  emits `shared` with the group and the received / skipped counts.

  @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
-->
<template>
	<section class="keepiq-group-share-form" data-testid="group-share-form">
		<header>
			<h4>{{ t('keepiq', 'Share with a group') }}</h4>
		</header>
		<form @submit.prevent="onSubmit">
			<NcSelect
				v-model="selected"
				:options="options"
				:inputLabel="t('keepiq', 'Group')"
				:placeholder="t('keepiq', 'Search groups')"
				label="label"
				:filterable="false"
				:loading="searching"
				data-testid="group-share-form-group"
				@search="onSearch" />

			<p
				v-if="error"
				class="keepiq-group-share-form__error"
				data-testid="group-share-form-error">
				{{ error }}
			</p>

			<div class="keepiq-group-share-form__actions">
				<NcButton
					type="button"
					data-testid="group-share-form-cancel"
					@click="$emit('cancel')">
					{{ t('keepiq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="submit"
					variant="primary"
					data-testid="group-share-form-submit"
					:disabled="busy || selected === null">
					{{
						busy
							? t('keepiq', 'Sharing…')
							: t('keepiq', 'Share with group')
					}}
				</NcButton>
			</div>
		</form>
	</section>
</template>

<script>
import { NcButton, NcSelect } from '@nextcloud/vue'
import { useGroupShareStore } from '../../store/modules/groupShare.js'

export default {
	name: 'GroupShareForm',
	components: { NcButton, NcSelect },
	props: {
		secretId: {
			type: String,
			required: true,
		},
	},

	emits: ['cancel', 'shared'],
	data() {
		return {
			store: useGroupShareStore(),
			selected: null,
			options: [],
			searching: false,
			busy: false,
			error: null,
		}
	},

	methods: {
		/**
		 * Search the groups the user may share with.
		 *
		 * @param {string} term The search term.
		 * @return {Promise<void>}
		 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
		 */
		async onSearch(term) {
			this.searching = true
			try {
				this.options = await this.store.searchGroups(term ?? '')
			} catch {
				this.options = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * Share the secret with the picked group.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
		 */
		async onSubmit() {
			this.error = null
			if (this.selected === null) {
				this.error = t('keepiq', 'Group is required')
				return
			}

			this.busy = true
			try {
				const result = await this.store.shareWithGroup(
					this.secretId,
					this.selected.id,
				)
				this.$emit('shared', { group: this.selected, ...result })
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to share')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.keepiq-group-share-form__error {
	color: var(--color-error-text);
	font-size: 13px;
}

.keepiq-group-share-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 12px;
}
</style>
