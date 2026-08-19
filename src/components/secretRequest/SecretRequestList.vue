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
					<!--
					  Recovering the link is the whole point of this list. The token is
					  shown truncated on purpose, so without this the link is
					  unobtainable once the create dialog is closed and the only way
					  to get a usable request is to revoke and start again.
					-->
					<button
						v-if="canCopyLink(row)"
						type="button"
						class="doriath-secret-request-list__copy"
						:data-testid="`secret-request-row-copy-${row.id}`"
						@click="onCopyLink(row)">
						{{
							copiedId === row.id
								? t('doriath', 'Link copied')
								: t('doriath', 'Copy fill link')
						}}
					</button>
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
import { fillLinkFor } from '../../utils/fillLink.js'

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
			copiedId: null,
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

		/**
		 * Whether this row's link can still be used.
		 *
		 * Offered only where the link would actually work: a fulfilled, declined or
		 * lapsed request no longer accepts a submission, and handing someone a dead
		 * URL is worse than not offering one. `expiresAt` is checked here rather
		 * than trusting status, because expiry is not swept until the job for it
		 * lands (secret-request-expiry-lifecycle) and a lapsed request still reads
		 * as pending until then.
		 *
		 * @param {object} row The request row.
		 *
		 * @return {boolean} True when the link is still fillable.
		 *
		 * @spec openspec/changes/request-first-secret-requests/specs/secret-requests/spec.md#requirement-fill-link-recovery
		 */
		canCopyLink(row) {
			if (!row || row.status !== 'pending' || !row.token) {
				return false
			}

			const expiry = row.expiresAt || row.expires_at
			if (expiry && new Date(expiry).getTime() <= Date.now()) {
				return false
			}

			return true
		},

		/**
		 * Copy this request's fill link to the clipboard.
		 *
		 * @param {object} row The request row.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/request-first-secret-requests/specs/secret-requests/spec.md#requirement-fill-link-recovery
		 */
		async onCopyLink(row) {
			try {
				await navigator.clipboard.writeText(fillLinkFor(row.token))
				this.copiedId = row.id
				setTimeout(() => {
					if (this.copiedId === row.id) {
						this.copiedId = null
					}
				}, 1500)
			} catch (e) {
				// eslint-disable-next-line no-console
				console.warn('Doriath: clipboard write failed', e)
			}
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
	/* A badge needs its own contrast, not the page's. --color-warning-rest does
	   not exist, so this was a pale cream fallback carrying warning-coloured
	   text — low contrast in light mode and wrong in dark. Nextcloud guarantees
	   the --color-warning / --color-warning-text pairing in both themes. */
	background-color: var(--color-warning);
	color: var(--color-warning-text);
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

.doriath-secret-request-list__copy {
	cursor: pointer;
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
	color: var(--color-error-text);
	padding: 4px 12px;
	border-radius: var(--border-radius, 4px);
	cursor: pointer;
}

.doriath-secret-request-list__empty {
	color: var(--color-text-maxcontrast, #777);
	font-size: 13px;
}

.doriath-secret-request-list__error {
	color: var(--color-error-text);
	font-size: 13px;
}
</style>
