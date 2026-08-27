<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Recipient list for a shared secret. Owners and delegates see every
  recipient with a revoke action; recipients themselves see the same
  list redacted to their own row (the controller already enforces this
  at the API layer).

  @spec openspec/changes/implement-user-sharing/tasks.md#task-12.2
-->
<template>
	<section class="keepiq-share-list" data-testid="share-list">
		<header class="keepiq-share-list__header">
			<h4>{{ t('keepiq', 'Shared with') }}</h4>
			<span
				v-if="store.recipientCount > 0"
				class="keepiq-share-list__count"
				data-testid="share-list-count">
				{{ store.recipientCount }}
			</span>
		</header>

		<p v-if="store.loading" class="keepiq-share-list__loading">
			{{ t('keepiq', 'Loading…') }}
		</p>

		<p
			v-else-if="store.recipientCount === 0"
			class="keepiq-share-list__empty"
			data-testid="share-list-empty">
			{{ t('keepiq', 'This secret is not shared with anyone yet.') }}
		</p>

		<ul v-else class="keepiq-share-list__rows">
			<li
				v-for="share in store.shares"
				:key="share.id"
				class="keepiq-share-list__row"
				data-testid="share-row">
				<span
					class="keepiq-share-list__user"
					data-testid="share-row-target"
					>{{ share.target_user_id }}</span
				>
				<span
					v-if="share.group_share_id"
					class="keepiq-share-list__group-badge">
					{{ t('keepiq', 'via group') }}
				</span>
				<button
					v-if="canRevoke"
					type="button"
					class="keepiq-share-list__revoke"
					data-testid="share-row-revoke"
					:disabled="store.loading"
					@click="onRevoke(share.id)">
					{{ t('keepiq', 'Revoke') }}
				</button>
			</li>
		</ul>

		<p
			v-if="store.error"
			class="keepiq-share-list__error"
			data-testid="share-list-error">
			{{ store.error }}
		</p>
	</section>
</template>

<script>
import { useShareStore } from '../../store/modules/share.js'

export default {
	name: 'ShareList',
	props: {
		secretId: {
			type: String,
			required: true,
		},

		canRevoke: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			store: useShareStore(),
		}
	},

	watch: {
		secretId(id) {
			if (id) {
				this.store.fetchShares(id).catch(() => {})
			}
		},
	},

	async created() {
		if (this.secretId) {
			await this.store.fetchShares(this.secretId).catch(() => {})
		}
	},

	methods: {
		onRevoke(shareId) {
			this.store.revokeShare(shareId).catch(() => {})
		},
	},
}
</script>

<style scoped>
.keepiq-share-list__header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.keepiq-share-list__count {
	background-color: var(--color-primary-element-light, #e2f1fb);
	color: var(--color-primary-element, #0082c9);
	border-radius: 999px;
	font-size: 12px;
	padding: 2px 8px;
}

.keepiq-share-list__rows {
	list-style: none;
	padding: 0;
	margin: 0;
}

.keepiq-share-list__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border, #eee);
}

.keepiq-share-list__user {
	flex: 1;
	font-weight: 500;
}

.keepiq-share-list__group-badge {
	background-color: var(--color-background-darker, #ddd);
	border-radius: 999px;
	font-size: 11px;
	padding: 1px 8px;
}

.keepiq-share-list__revoke {
	background: transparent;
	border: 1px solid var(--color-border-dark, #999);
	color: var(--color-error-text);
	padding: 4px 12px;
	border-radius: var(--border-radius, 4px);
	cursor: pointer;
}

.keepiq-share-list__error {
	color: var(--color-error-text);
	font-size: 13px;
}

.keepiq-share-list__empty {
	color: var(--color-text-maxcontrast, #777);
	font-size: 13px;
}
</style>
