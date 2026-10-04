<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Share a secret with someone at a partner organisation
  (sharing-federated-recipients task 2.3). The owner types the recipient's
  cloud id; the owner's server fetches the certificate from the partner;
  this browser checks it against the pinned partner root and the cloud id
  and shows its fingerprint. Only after that does it encrypt the secret for
  the recipient and send the ciphertext. A certificate that does not verify
  is refused and nothing is encrypted.

  Rendered by SecretShareDialog only while an outbound partner exists.

  @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
-->
<template>
	<section class="federated-share" data-testid="federated-share-form">
		<h4>{{ t('keepiq', 'Share with someone at another organisation') }}</h4>
		<div class="federated-share__lookup">
			<NcTextField
				v-model="cloudId"
				:label="t('keepiq', 'Their account at the other organisation')"
				placeholder="name@cloud.example.org"
				:disabled="busy"
				data-testid="federated-share-cloud-id"
				@update:modelValue="recipient = null" />
			<NcButton
				variant="secondary"
				:disabled="busy || cloudId.trim() === ''"
				data-testid="federated-share-check"
				@click="check">
				{{ t('keepiq', 'Check account') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error" data-testid="federated-share-error">
			{{ error }}
		</NcNoteCard>

		<div
			v-if="recipient"
			class="federated-share__verified"
			data-testid="federated-share-verified">
			<p>
				{{
					t('keepiq', 'Certificate fingerprint of {account}', {
						account: recipient.cloudId,
					})
				}}
			</p>
			<code
				class="federated-share__fingerprint"
				data-testid="federated-share-fingerprint"
				>{{ recipient.fingerprint }}</code
			>
			<p class="federated-share__hint">
				{{
					t(
						'keepiq',
						'Compare it with them by phone if you want to be sure.',
					)
				}}
			</p>
			<NcButton
				variant="primary"
				:disabled="busy"
				data-testid="federated-share-submit"
				@click="share">
				{{ t('keepiq', 'Share') }}
			</NcButton>
		</div>

		<NcNoteCard
			v-if="sharedWith"
			type="success"
			data-testid="federated-share-done">
			{{
				t('keepiq', 'Shared. {account} can accept it in their own vault.', {
					account: sharedWith,
				})
			}}
		</NcNoteCard>

		<ul
			v-if="shares.length > 0"
			class="federated-share__list"
			data-testid="federated-share-list">
			<li
				v-for="row in shares"
				:key="row.id"
				class="federated-share__row"
				:data-testid="`federated-share-row-${row.id}`">
				<span class="federated-share__account">{{
					row.recipientCloudId
				}}</span>
				<span
					class="federated-share__state"
					data-testid="federated-share-state"
					>{{ stateText(row.status) }}</span
				>
				<NcButton
					v-if="row.status !== 'revoked'"
					variant="tertiary"
					:disabled="busy"
					data-testid="federated-share-revoke"
					@click="revoke(row.id)">
					{{ t('keepiq', 'Revoke') }}
				</NcButton>
			</li>
		</ul>
	</section>
</template>

<script>
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { FederatedCertificateError } from '../../crypto/federatedCertificate.js'
import { useFederatedShareStore } from '../../store/modules/federatedShare.js'
import { useSecretStore } from '../../store/modules/secret.js'

export default {
	name: 'FederatedShareForm',

	components: { NcButton, NcNoteCard, NcTextField },

	props: {
		/** The owner's secret. */
		secretId: {
			type: String,
			required: true,
		},
	},

	emits: ['shared'],

	data() {
		return {
			cloudId: '',
			recipient: null,
			busy: false,
			error: '',
			sharedWith: '',
		}
	},

	computed: {
		/**
		 * The secret's federated shares, from the store.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
		 */
		shares() {
			return useFederatedShareStore().shares
		},
	},

	/**
	 * Load the secret's federated shares.
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	async created() {
		try {
			await useFederatedShareStore().listFor(this.secretId)
		} catch {
			// The form still works without the list.
		}
	},

	methods: {
		/**
		 * Fetch the recipient's certificate and verify it in this browser.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-a-certificate-from-another-root-is-refused
		 */
		async check() {
			this.busy = true
			this.error = ''
			this.recipient = null
			this.sharedWith = ''
			try {
				this.recipient = await useFederatedShareStore().lookup(this.cloudId)
			} catch (e) {
				this.error = this.explain(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Encrypt the secret for the verified recipient and send the ciphertext.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
		 */
		async share() {
			if (this.recipient === null) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const secret = await useSecretStore().fetchSecret(this.secretId)
				const row = await useFederatedShareStore().share(
					this.secretId,
					secret,
					this.recipient,
				)
				this.sharedWith = this.recipient.cloudId
				this.recipient = null
				this.cloudId = ''
				await useFederatedShareStore().listFor(this.secretId)
				this.$emit('shared', row)
			} catch (e) {
				this.error = this.explain(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Revoke a federated share; the recipient's copy is deleted.
		 *
		 * @param {string} id The federated share.
		 * @return {Promise<void>}
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-revocation-removes-bobs-copy
		 */
		async revoke(id) {
			this.busy = true
			this.error = ''
			try {
				await useFederatedShareStore().revoke(id)
			} catch (e) {
				this.error = this.explain(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * The state of a federated share, in words the owner can act on.
		 *
		 * @param {string} status The share status.
		 * @return {string}
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
		 */
		stateText(status) {
			const texts = {
				active: t('keepiq', 'Shared'),
				suspended: t(
					'keepiq',
					'Paused: their certificate or the partnership changed. Revoke it or share again.',
				),

				failed: t(
					'keepiq',
					'Their organisation did not get the last change. Revoke it or share again.',
				),

				revoked: t('keepiq', 'Being withdrawn'),
				declined: t(
					'keepiq',
					'Declined: they removed their copy. Share again if they need it.',
				),
			}
			return texts[status] ?? status
		},

		/**
		 * A refusal, in words the owner can act on.
		 *
		 * @param {Error|object} e The failure.
		 * @return {string}
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-a-certificate-from-another-root-is-refused
		 */
		explain(e) {
			if (e instanceof FederatedCertificateError) {
				return t(
					'keepiq',
					'The certificate could not be verified. Nothing was shared.',
				)
			}
			const texts = {
				not_a_partner: t(
					'keepiq',
					'That organisation is not one of your partners.',
				),

				unknown_recipient: t(
					'keepiq',
					'No one with that account can receive secrets from you.',
				),

				partner_unreachable: t(
					'keepiq',
					'The other organisation did not answer. Try again later.',
				),

				delivery_failed: t(
					'keepiq',
					'The other organisation did not answer. Try again later.',
				),

				already_shared: t(
					'keepiq',
					'This secret is already shared with that account.',
				),
			}
			return (
				texts[e?.response?.data?.message]
				?? t('keepiq', 'Something went wrong. Try again.')
			)
		},
	},
}
</script>

<style scoped>
.federated-share {
	margin-top: 16px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.federated-share__lookup {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.federated-share__verified {
	margin-top: 12px;
}

.federated-share__fingerprint {
	display: block;
	overflow-wrap: anywhere;
	font-family: var(--font-face-monospace, monospace);
	margin: 4px 0;
}

.federated-share__list {
	list-style: none;
	padding: 0;
	margin: 12px 0 0;
}

.federated-share__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.federated-share__account {
	flex: 1;
	overflow-wrap: anywhere;
}

.federated-share__state {
	color: var(--color-text-maxcontrast);
}

.federated-share__hint {
	color: var(--color-text-maxcontrast);
}
</style>
