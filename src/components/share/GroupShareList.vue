<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The group shares of a secret, shown to its owner in the secret sidebar:
  each group with a revoke action, and a Share with group action that opens
  the GroupShareForm. After a share it says how many members received the
  secret and how many did not.

  @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
-->
<template>
	<section class="keepiq-group-share-list" data-testid="group-share-list">
		<h4>{{ t('keepiq', 'Shared with groups') }}</h4>

		<p
			v-if="!store.loading && store.groupShares.length === 0"
			class="keepiq-group-share-list__empty"
			data-testid="group-share-list-empty">
			{{ t('keepiq', 'Not shared with any group yet.') }}
		</p>

		<ul v-else class="keepiq-group-share-list__rows">
			<li
				v-for="row in store.groupShares"
				:key="row.id"
				class="keepiq-group-share-list__row"
				data-testid="group-share-row">
				<span class="keepiq-group-share-list__group">{{ row.groupId }}</span>
				<NcButton
					variant="tertiary"
					:aria-label="
						t('keepiq', 'Revoke the share with {group}', {
							group: row.groupId,
						})
					"
					data-testid="group-share-row-revoke"
					@click="onRevoke(row.id)">
					{{ t('keepiq', 'Revoke') }}
				</NcButton>
			</li>
		</ul>

		<p
			v-if="result !== null"
			class="keepiq-group-share-list__result"
			role="status"
			data-testid="group-share-result">
			{{
				t(
					'keepiq',
					'Shared with {group}: {received} members received it, {skipped} did not because they have no encryption set up yet.',
					{
						group: result.group,
						received: result.received,
						skipped: result.skipped,
					},
				)
			}}
		</p>

		<p
			v-if="store.error"
			class="keepiq-group-share-list__error"
			data-testid="group-share-list-error">
			{{ store.error }}
		</p>

		<GroupShareForm
			v-if="formOpen"
			:secretId="secretId"
			@cancel="formOpen = false"
			@shared="onShared" />
		<NcButton v-else data-testid="group-share-open-form" @click="openForm">
			{{ t('keepiq', 'Share with group') }}
		</NcButton>
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import GroupShareForm from './GroupShareForm.vue'
import { useGroupShareStore } from '../../store/modules/groupShare.js'

export default {
	name: 'GroupShareList',
	components: { GroupShareForm, NcButton },
	props: {
		secretId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			store: useGroupShareStore(),
			formOpen: false,
			result: null,
		}
	},

	watch: {
		secretId(id) {
			this.result = null
			this.formOpen = false
			if (id) {
				this.store.fetchGroupShares(id).catch(() => {})
			}
		},
	},

	created() {
		if (this.secretId) {
			this.store.fetchGroupShares(this.secretId).catch(() => {})
		}
	},

	methods: {
		/**
		 * Open the share form and clear the last result.
		 *
		 * @return {void}
		 */
		openForm() {
			this.result = null
			this.formOpen = true
		},

		/**
		 * Close the form and report how the share went.
		 *
		 * @param {{group: {id: string, label: string}, received: number, skipped: number}} payload The share outcome.
		 * @return {void}
		 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
		 */
		onShared(payload) {
			this.formOpen = false
			this.result = {
				group: payload.group?.label ?? payload.group?.id ?? '',
				received: payload.received,
				skipped: payload.skipped,
			}
		},

		/**
		 * Revoke a group share; the server revokes every member copy.
		 *
		 * @param {string} id The group share id.
		 * @return {void}
		 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
		 */
		onRevoke(id) {
			this.result = null
			this.store.revokeGroupShare(id).catch(() => {})
		},
	},
}
</script>

<style scoped>
.keepiq-group-share-list__rows {
	list-style: none;
	padding: 0;
	margin: 0;
}

.keepiq-group-share-list__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.keepiq-group-share-list__group {
	flex: 1;
	font-weight: 500;
}

.keepiq-group-share-list__empty,
.keepiq-group-share-list__result {
	color: var(--color-text-maxcontrast);
}

.keepiq-group-share-list__error {
	color: var(--color-error-text);
}
</style>
