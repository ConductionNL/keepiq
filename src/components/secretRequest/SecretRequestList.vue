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
					<strong>{{ statusLabel(effectiveStatus(row)) }}</strong>
					<span
						class="doriath-secret-request-list__expiry"
						:data-testid="`secret-request-row-expiry-${row.id}`">{{
							expiryLabel(row)
						}}</span>
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

		<ApplicationRequestRevokeDialog
			v-if="revokeTarget"
			:open="revokeTarget !== null"
			:requestedFields="revokeTargetFields()"
			@close="revokeTarget = null"
			@confirm="onRevokeConfirmed" />
	</section>
</template>

<script>
import ApplicationRequestRevokeDialog from '../../dialogs/ApplicationRequestRevokeDialog.vue'
import { useSecretRequestStore } from '../../store/modules/secretRequest.js'
import { fillLinkFor } from '../../utils/fillLink.js'

export default {
	name: 'SecretRequestList',

	components: {
		ApplicationRequestRevokeDialog,
	},

	props: {
		/**
		 * Optional filter by Secret ID. When unset the component renders the
		 * full list of requests created by the current user.
		 */
		secretId: {
			type: String,
			default: null,
		},

		/**
		 * Render one APPLICATION's requests instead of the current user's.
		 *
		 * This prop selects which endpoint is called; it does not grant anything.
		 * The admin-scoped endpoint refuses a non-administrator with 403, so the
		 * authority stays on the server. If the scope were a flag this component
		 * trusted, the component would become the place an access decision is
		 * accidentally made — the one real risk in reusing it for two authorities.
		 */
		applicationId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			copiedId: null,
			revokeTarget: null,
			store: useSecretRequestStore(),
		}
	},

	computed: {
		/**
		 * Whether this list is showing an application's requests.
		 *
		 * @return {boolean} True in the administrator's application-scoped view.
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
		isApplicationScope() {
			return typeof this.applicationId === 'string' && this.applicationId !== ''
		},

		/**
		 * The rows to render, from the collection this list's scope owns.
		 *
		 * @return {Array<object>} The request rows.
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
		rows() {
			// Two collections, not one filtered array: `secretRequests` means
			// "requests I created" and `applicationRequests` means "requests this
			// application created". Reading the wrong one would render plausible
			// rows under the wrong authority.
			const source = this.isApplicationScope
				? this.store.applicationRequests
				: this.store.secretRequests

			if (typeof this.secretId !== 'string' || this.secretId === '') {
				return source
			}

			return source.filter((r) => {
				const sid = r.secretId || r.secret_id
				return sid === this.secretId
			})
		},
	},

	/**
	 * Load the requests this list is scoped to.
	 *
	 * The endpoint is chosen here, and the server enforces the authority behind it:
	 * the admin-scoped URL refuses a non-administrator, so nothing about who may
	 * read what is decided in this component.
	 *
	 * @return {void}
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	mounted() {
		if (this.isApplicationScope) {
			this.store
				.fetchApplicationRequests(this.applicationId)
				.catch(() => {})
			return
		}

		this.store.fetchRequests().catch(() => {})
	},

	methods: {
		/**
		 * The token, shortened for display.
		 *
		 * A fill token is a bearer credential: whoever reads it can submit against
		 * the request, and a list row travels into screenshots and over shoulders.
		 * So the row shows a stub and the full value reaches the clipboard only
		 * through an explicit copy action. Required on the administrator's listing
		 * ("each row MUST show the token truncated") and the same rule the user's
		 * own listing follows.
		 *
		 * @param {string} token The full token.
		 *
		 * @return {string} A truncated form safe to display.
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
		truncateToken(token) {
			if (typeof token !== 'string' || token === '') {
				return ''
			}
			return token.length <= 12 ? token : `${token.slice(0, 8)}…`
		},

		/**
		 * The requested field names, as one readable string.
		 *
		 * Field names are plaintext metadata by design: the Requestable Fields
		 * requirement puts them on the request and deliberately never on the Secret,
		 * so showing them discloses nothing the audit trail does not already hold.
		 *
		 * @param {Array<string>} fields The requested field names.
		 *
		 * @return {string} A comma-separated list.
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
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

		/**
		 * The status to SHOW, judged on the expiry timestamp.
		 *
		 * A request whose expiry has passed is stored as `pending` until the hourly
		 * sweeper reaches it, but the access gate already refuses it — a recipient
		 * opening the link is told it expired. Labelling such a row "Pending" would
		 * therefore show an administrator a state that no longer exists anywhere the
		 * link is actually used, and leave the missing copy button unexplained.
		 *
		 * The row's `data-testid` deliberately keeps the STORED status: that is a
		 * fact about the database, and tests asserting stored state should not have
		 * to reason about the clock.
		 *
		 * @param {object} row The request row.
		 *
		 * @return {string} The status to label the row with.
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
		effectiveStatus(row) {
			if (row?.status !== 'pending') {
				return row?.status
			}

			const expiry = row.expiresAt || row.expires_at
			if (expiry && new Date(expiry).getTime() <= Date.now()) {
				return 'expired'
			}

			return 'pending'
		},

		/**
		 * The expiry, as a phrase for the row.
		 *
		 * The spec requires the expiry to be listed alongside status and requested
		 * fields, and for an administrator it is the most consequential column: a
		 * request with NO expiry is a fill link that works forever, which is exactly
		 * what someone auditing an application wants to notice. So "No expiry" is
		 * stated rather than left blank.
		 *
		 * @param {object} row The request row.
		 *
		 * @return {string} A short phrase describing the expiry.
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
		expiryLabel(row) {
			const expiry = row?.expiresAt || row?.expires_at
			if (!expiry) {
				return t('doriath', 'No expiry')
			}

			const when = this.formatDate(expiry)
			if (new Date(expiry).getTime() <= Date.now()) {
				return t('doriath', 'Expired {when}', { when })
			}

			return t('doriath', 'Expires {when}', { when })
		},

		/**
		 * Format an ISO timestamp for display.
		 *
		 * Matches ApplicationDetail's helper: the browser's locale formatting, and
		 * the raw value rather than an empty cell if it cannot be parsed — a
		 * malformed date is information, a blank is not.
		 *
		 * @param {string} iso The ISO timestamp.
		 *
		 * @return {string} A locale-formatted date, or the input unchanged.
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
		formatDate(iso) {
			if (!iso) {
				return ''
			}

			try {
				return new Date(iso).toLocaleString()
			} catch {
				return iso
			}
		},

		/**
		 * A human label for a request status.
		 *
		 * Includes `expired`, the terminal status the sweeper sets — without an arm
		 * for it the raw string would be shown to an administrator.
		 *
		 * @param {string} status The status to label.
		 *
		 * @return {string} The translated label.
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
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

		/**
		 * Begin a revoke.
		 *
		 * A user revoking their own request goes straight through: they created it
		 * and they know what it was for. An administrator revoking an APPLICATION's
		 * request is interrupting software they did not write, whose fill link may
		 * already be in someone's inbox, so that path asks first.
		 *
		 * @param {string} id The request id.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
		onRevoke(id) {
			if (this.isApplicationScope) {
				this.revokeTarget = this.rows.find((r) => r.id === id) || { id }
				return
			}

			this.store.revokeRequest(id).catch(() => {})
		},

		/**
		 * Carry out a confirmed application-scoped revoke.
		 *
		 * The application id travels with the call so the server can enforce that
		 * the request really is that application's, rather than trusting an id.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
		async onRevokeConfirmed() {
			const target = this.revokeTarget
			this.revokeTarget = null
			if (!target) {
				return
			}

			try {
				await this.store.revokeApplicationRequest(
					this.applicationId,
					target.id,
				)
			} catch {
				// The store already surfaced the message; the row stays put so the
				// administrator can see the revoke did not take effect.
			}
		},

		/**
		 * The requested field names of the request awaiting confirmation.
		 *
		 * Passed to the confirmation dialog so it can say what is being interrupted.
		 *
		 * @return {Array<string>} Field names, or an empty array.
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
		revokeTargetFields() {
			const target = this.revokeTarget
			if (!target) {
				return []
			}

			const fields = target.requestedFields || target.requested_fields
			return Array.isArray(fields) ? fields : []
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
