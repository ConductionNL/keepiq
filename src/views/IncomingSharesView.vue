<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  "Incoming from other organisations" (sharing-federated-recipients task
  3.2): secrets that users of partner instances shared with the current
  user. Accepting asks this server to pull the ciphertext and keep a
  read-only copy in the vault; declining drops the offer.

  @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
-->
<template>
	<div class="incoming-shares" data-testid="incoming-shares-view">
		<h2>{{ t('keepiq', 'Incoming from other organisations') }}</h2>
		<p class="incoming-shares__intro">
			{{
				t(
					'keepiq',
					'People in partner organisations can share a secret with you. Accept it to keep a read-only copy in your vault.',
				)
			}}
		</p>

		<NcNoteCard
			v-if="store.error"
			type="error"
			data-testid="incoming-shares-error">
			{{ errorText }}
		</NcNoteCard>

		<NcLoadingIcon
			v-if="store.loading && store.shares.length === 0"
			:size="24" />

		<NcEmptyContent
			v-else-if="store.shares.length === 0"
			:name="t('keepiq', 'Nothing shared with you yet')"
			:description="
				t(
					'keepiq',
					'Secrets that people in partner organisations share with you appear here.',
				)
			"
			data-testid="incoming-shares-empty" />

		<ul v-else class="incoming-shares__list">
			<li
				v-for="share in store.shares"
				:key="share.id"
				class="incoming-shares__row"
				:data-testid="`incoming-share-${share.id}`">
				<div class="incoming-shares__what">
					<span class="incoming-shares__name">{{ share.name }}</span>
					<span class="incoming-shares__from">
						{{
							t('keepiq', 'From {sender}', {
								sender: share.senderCloudId,
							})
						}}
					</span>
					<span
						class="incoming-shares__status"
						data-testid="incoming-share-status">
						{{ statusText(share.status) }}
					</span>
				</div>
				<div
					v-if="share.status === 'pending'"
					class="incoming-shares__actions">
					<NcButton
						variant="primary"
						:disabled="store.busyId !== null"
						data-testid="incoming-share-accept"
						@click="answer(share.id, 'accept')">
						{{ t('keepiq', 'Accept') }}
					</NcButton>
					<NcButton
						variant="tertiary"
						:disabled="store.busyId !== null"
						data-testid="incoming-share-decline"
						@click="answer(share.id, 'decline')">
						{{ t('keepiq', 'Decline') }}
					</NcButton>
				</div>
				<NcButton
					v-else-if="share.status === 'accepted' && share.secretId"
					variant="secondary"
					data-testid="incoming-share-open"
					@click="open(share.secretId)">
					{{ t('keepiq', 'Open in vault') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { useFederatedInboundStore } from '../store/modules/federatedInbound.js'

export default {
	name: 'IncomingSharesView',

	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard },

	data() {
		return {
			store: useFederatedInboundStore(),
		}
	},

	computed: {
		/**
		 * The error, in words the user can act on.
		 *
		 * @return {string}
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
		 */
		errorText() {
			if (this.store.error === 'pull_failed') {
				return t(
					'keepiq',
					'The other organisation did not hand over the secret. Try again later.',
				)
			}
			if (this.store.error === 'no_suite') {
				return t(
					'keepiq',
					'Set up your vault before you accept a shared secret.',
				)
			}
			return t('keepiq', 'Something went wrong. Try again.')
		},
	},

	/**
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
	 */
	async created() {
		await this.store.fetch()
	},

	methods: {
		/**
		 * The state of a share, in words.
		 *
		 * @param {string} status The share status.
		 * @return {string}
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
		 */
		statusText(status) {
			const texts = {
				pending: t('keepiq', 'Waiting for your answer'),
				accepted: t('keepiq', 'In your vault, read-only'),
				declined: t('keepiq', 'Declined'),
				revoked: t('keepiq', 'Withdrawn by the sender'),
			}
			return texts[status] ?? status
		},

		/**
		 * Accept or decline one share.
		 *
		 * @param {string} id The inbound share id.
		 * @param {'accept'|'decline'} action What to do.
		 * @return {Promise<void>}
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
		 */
		async answer(id, action) {
			try {
				await this.store.answer(id, action)
			} catch {
				// The store keeps the error; the note card shows it.
			}
		},

		/**
		 * Open the accepted copy in the vault.
		 *
		 * @param {string} secretId The local copy.
		 * @return {void}
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
		 */
		open(secretId) {
			this.$router?.push({ name: 'SecretList', params: { id: secretId } })
		},
	},
}
</script>

<style scoped>
.incoming-shares {
	padding: 16px;
	max-width: 840px;
}

.incoming-shares__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.incoming-shares__list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.incoming-shares__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.incoming-shares__what {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.incoming-shares__name {
	font-weight: bold;
}

.incoming-shares__from,
.incoming-shares__status {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.incoming-shares__actions {
	display: flex;
	gap: 8px;
}
</style>
