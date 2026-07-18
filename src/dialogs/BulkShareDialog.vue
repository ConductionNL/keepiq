<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Bulk-share dialog (bulk-actions §6.1): share the selected secrets
  with one recipient via the per-secret RSA fan-out — decrypt with the
  owner's in-session CryptoKey, re-encrypt under the recipient's
  certificate (WebCrypto, ADR-003), and register through the idempotent
  batch endpoint. No plaintext ever leaves the browser; a recipient
  without an active suite fails fast before any work runs.

  @spec openspec/changes/bulk-actions/specs/bulk-actions/spec.md#requirement-bulk-share
-->
<template>
	<NcDialog :name="t('doriath', 'Share {count} secrets', { count: bulk.selectionCount })"
		:open="open"
		size="normal"
		data-testid="bulk-share-dialog"
		@update:open="$emit('close')">
		<div class="bulk-share">
			<label class="bulk-share__field">
				<span>{{ t('doriath', 'Recipient user ID') }}</span>
				<input v-model="targetUserId" type="text" data-testid="bulk-share-recipient">
			</label>
			<p v-if="error" class="bulk-share__error" data-testid="bulk-share-error">
				{{ error }}
			</p>
			<BulkRunPanel @retry="onRetry" />
		</div>
		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('doriath', 'Close') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="targetUserId === '' || bulk.progress.running"
				data-testid="bulk-share-run"
				@click="onRun">
				{{ t('doriath', 'Share') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useBulkStore } from '../store/modules/bulk.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useShareStore } from '../store/modules/share.js'
import BulkRunPanel from '../components/BulkRunPanel.vue'

export default {
	name: 'BulkShareDialog',
	components: {
		NcButton,
		NcDialog,
		BulkRunPanel,
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
			targetUserId: '',
			certificate: '',
			error: null,
		}
	},
	computed: {
		bulk() {
			return useBulkStore()
		},
	},
	methods: {
		/**
		 * The per-item fan-out: decrypt the owner's copy in-browser,
		 * re-encrypt for the recipient, register idempotently.
		 *
		 * @param {string} secretId The secret id.
		 * @return {Promise<object>}
		 */
		async shareOne(secretId) {
			const secretStore = useSecretStore()
			const shareStore = useShareStore()

			// fetchSecret decrypts with the session CryptoKey and returns
			// the PLAINTEXT secret — plaintext stays in this browser tab.
			const plain = await secretStore.fetchSecret(secretId)
			const snapshot = {
				key: plain.key ?? '',
				login: plain.login ?? '',
				additionalFields: typeof plain.additionalFields === 'object' && plain.additionalFields !== null
					? JSON.stringify(plain.additionalFields)
					: (plain.additionalFields ?? ''),
			}
			const blob = await shareStore.encryptForRecipient(snapshot, this.certificate)

			const response = await axios.post(generateUrl('/apps/doriath/api/v1/shares/register-batch'), {
				shares: [{
					sourceSecretId: secretId,
					targetUserId: this.targetUserId,
					encryptedKey: blob.key ?? '',
					encryptedLogin: blob.login ?? null,
					encryptedAdditionalFields: blob.additionalFields ?? null,
				}],
			})
			const item = response.data?.items?.[0]
			if (item?.status === 'created' || item?.status === 'exists') {
				return { status: 'ok', reason: item.status === 'exists' ? 'already shared' : undefined }
			}
			return { status: 'skipped', reason: item?.status || 'not registered' }
		},

		/**
		 * Resolve the recipient certificate once, then run the fan-out.
		 *
		 * @return {Promise<void>}
		 */
		async onRun() {
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/shares/recipient-certificate'),
					{ params: { userId: this.targetUserId } },
				)
				this.certificate = response.data?.certificate ?? ''
			} catch (e) {
				this.error = e?.response?.status === 404
					? this.t('doriath', 'Recipient has no active encryption suite')
					: (e?.response?.data?.message || e?.message)
				return
			}

			await this.bulk.run(
				this.bulk.selectedIds,
				(id) => this.shareOne(id),
				this.t('doriath', 'Sharing secrets'),
			)
			this.$emit('done')
		},

		/**
		 * Retry only the failed subset (idempotent server writes).
		 *
		 * @return {Promise<void>}
		 */
		async onRetry() {
			await this.bulk.retryFailed((id) => this.shareOne(id), this.t('doriath', 'Retrying share'))
			this.$emit('done')
		},
	},
}
</script>

<style scoped>
.bulk-share {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}

.bulk-share__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.bulk-share__field input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.bulk-share__error {
	color: var(--color-error, #e9322d);
	font-size: 13px;
}
</style>
