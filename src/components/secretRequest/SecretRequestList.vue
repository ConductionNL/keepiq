<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Secret-request list, used in the SecretDetail sidebar and on a
  standalone "My requests" view. Lists requests for the current user
  with status, token (truncated), requested fields, and a revoke action
  for pending rows.

  @spec openspec/changes/implement-secret-requests/tasks.md#task-8.3
-->
<template>
	<section class="doriath-secret-request-list" data-testid="secret-request-list">
		<header class="doriath-secret-request-list__header">
			<h4>{{ t('doriath', 'Outstanding requests') }}</h4>
			<span
				v-if="store.pendingCount > 0"
				class="doriath-secret-request-list__count"
				data-testid="secret-request-pending-count">
				{{ store.pendingCount }}
			</span>
		</header>

		<p v-if="store.loading" class="doriath-secret-request-list__loading">
			{{ t('doriath', 'Loading…') }}
		</p>

		<p
			v-else-if="rows.length === 0"
			class="doriath-secret-request-list__empty"
			data-testid="secret-request-list-empty">
			{{ t('doriath', 'No outstanding requests.') }}
		</p>

		<ul v-else class="doriath-secret-request-list__rows">
			<li
				v-for="row in rows"
				:key="row.id"
				class="doriath-secret-request-list__row"
				:data-testid="`secret-request-row-${row.status}`">
				<div class="doriath-secret-request-list__meta">
					<strong>{{ statusLabel(row.status) }}</strong>
					<span class="doriath-secret-request-list__token">{{
						truncateToken(row.token)
					}}</span>
					<span class="doriath-secret-request-list__fields">{{
						formatFields(row.requestedFields || row.requested_fields)
					}}</span>
				</div>
				<div class="doriath-secret-request-list__actions">
					<button
						v-if="row.status === 'pending'"
						type="button"
						class="doriath-secret-request-list__revoke"
						data-testid="secret-request-row-revoke"
						:disabled="store.loading"
						@click="onRevoke(row.id)">
						{{ t('doriath', 'Revoke') }}
					</button>
				</div>
			</li>
		</ul>

		<p
			v-if="store.error"
			class="doriath-secret-request-list__error"
			data-testid="secret-request-list-error">
			{{ store.error }}
		</p>
	</section>
</template>

<script>
import { useSecretRequestStore } from '../../store/modules/secretRequest.js'

export default {
	name: 'SecretRequestList',
	props: {
		/**
		 * Optional filter by Secret ID. When unset the component renders the
		 * full list of requests created by the current user.
		 */
		secretId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			store: useSecretRequestStore(),
		}
	},
	computed: {
		rows() {
			if (this.secretId == null || this.secretId === '') {
				return this.store.secretRequests
			}
			return this.store.secretRequests.filter((r) => {
				const sid = r.secretId || r.secret_id
				return sid === this.secretId
			})
		},
	},
	mounted() {
		this.store.fetchRequests().catch(() => {})
	},
	methods: {
		truncateToken(token) {
			if (token == null || token === '') {
				return ''
			}
			return token.length <= 12 ? token : `${token.slice(0, 8)}…`
		},
		formatFields(fields) {
			if (Array.isArray(fields) === false) {
				return ''
			}
			return fields.join(', ')
		},
		statusLabel(status) {
			switch (status) {
				case 'pending':
					return t('doriath', 'Pending')
				case 'fulfilled':
					return t('doriath', 'Fulfilled')
				case 'declined':
					return t('doriath', 'Declined')
				case 'locked':
					return t('doriath', 'Locked')
				case 'expired':
					return t('doriath', 'Expired')
				default:
					return status
			}
		},
		onRevoke(id) {
			this.store.revokeRequest(id).catch(() => {})
		},
	},
}
</script>

<style scoped>
.doriath-secret-request-list__header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.doriath-secret-request-list__count {
	background-color: var(--color-warning-rest, #fdf6e3);
	color: var(--color-warning, #c08410);
	border-radius: 999px;
	font-size: 12px;
	padding: 2px 8px;
}

.doriath-secret-request-list__rows {
	list-style: none;
	padding: 0;
	margin: 0;
}

.doriath-secret-request-list__row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border, #eee);
	gap: 8px;
}

.doriath-secret-request-list__meta {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.doriath-secret-request-list__token {
	font-family: monospace;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #777);
}

.doriath-secret-request-list__fields {
	font-size: 12px;
}

.doriath-secret-request-list__revoke {
	background: transparent;
	border: 1px solid var(--color-border-dark, #999);
	color: var(--color-error, #e9322d);
	padding: 4px 12px;
	border-radius: var(--border-radius, 4px);
	cursor: pointer;
}

.doriath-secret-request-list__empty {
	color: var(--color-text-maxcontrast, #777);
	font-size: 13px;
}

.doriath-secret-request-list__error {
	color: var(--color-error, #e9322d);
	font-size: 13px;
}
</style>
