<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The recovery kit in the user's settings and beside the encrypted backup
  (crypto-recovery-kit 2.2): when the current kit was made, and making a new
  one. Making a kit asks for the master password, wraps the private key with
  a new recovery code in this browser and shows the code once, to print or
  download. A new kit replaces the old one.

  @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
-->
<template>
	<div class="recovery-kit" data-testid="recovery-kit">
		<h3 class="recovery-kit__title">
			{{ t('keepiq', 'Recovery kit') }}
		</h3>
		<p class="recovery-kit__hint">
			{{ t('keepiq', 'A recovery kit lets you back into your vault if you lose your master password. Keep it offline.') }}
		</p>
		<p data-testid="recovery-kit-status">
			{{ statusText }}
		</p>

		<template v-if="code">
			<NcNoteCard type="warning">
				{{ t('keepiq', 'Write down or save this code now. It is shown only once.') }}
			</NcNoteCard>
			<p class="recovery-kit__code" data-testid="recovery-kit-code">
				{{ code }}
			</p>
			<div class="recovery-kit__actions">
				<NcButton variant="primary" data-testid="recovery-kit-download" @click="download">
					{{ t('keepiq', 'Download') }}
				</NcButton>
				<NcButton variant="secondary" data-testid="recovery-kit-print" @click="print">
					{{ t('keepiq', 'Print') }}
				</NcButton>
				<NcButton variant="tertiary" data-testid="recovery-kit-done" @click="code = ''">
					{{ t('keepiq', 'Done') }}
				</NcButton>
			</div>
		</template>

		<template v-else>
			<NcPasswordField
				v-if="asking"
				v-model="masterPassword"
				:label="t('keepiq', 'Master password')"
				:disabled="busy"
				autocomplete="current-password"
				data-testid="recovery-kit-password"
				@keyup.enter="create" />
			<div class="recovery-kit__actions">
				<NcButton
					:variant="asking ? 'primary' : 'secondary'"
					:disabled="busy || (asking && !masterPassword)"
					data-testid="recovery-kit-create"
					@click="asking ? create() : (asking = true)">
					{{ kit ? t('keepiq', 'Make a new recovery kit') : t('keepiq', 'Make a recovery kit') }}
				</NcButton>
			</div>
		</template>

		<p v-if="error" class="recovery-kit__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import { recoveryKitText } from '../crypto/recoveryKit.js'
import { createRecoveryKit, recoveryKitStatus } from '../services/recoveryKit.js'
import { useSessionStore } from '../store/modules/session.js'

export default {
	name: 'RecoveryKitPanel',
	components: { NcButton, NcNoteCard, NcPasswordField },

	data() {
		return {
			kit: null,
			asking: false,
			masterPassword: '',
			code: '',
			madeOn: '',
			busy: false,
			error: null,
		}
	},

	computed: {
		/**
		 * @return {string} When the current kit was made, or that there is none.
		 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
		 */
		statusText() {
			if (!this.kit) {
				return t('keepiq', 'You have no recovery kit.')
			}
			return t('keepiq', 'Your recovery kit was made on {date}.', { date: String(this.kit.createdAt ?? '').slice(0, 10) })
		},
	},

	async created() {
		try {
			this.kit = await recoveryKitStatus()
		} catch {
			this.kit = null
		}
	},

	methods: {
		t,

		/**
		 * Make a kit: wrap the private key with a new code; only the wrapped copy is sent.
		 *
		 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#scenario-a-kit-is-created-without-the-code-leaving-the-browser
		 */
		async create() {
			this.busy = true
			this.error = null
			try {
				const { code, kit } = await createRecoveryKit(useSessionStore().encryptedPrivateKey, this.masterPassword)
				this.code = code
				this.kit = kit
				this.madeOn = new Date().toISOString().slice(0, 10)
				this.asking = false
			} catch (e) {
				this.error = e?.response?.data?.message || t('keepiq', 'The recovery kit could not be made. Check your master password.')
			} finally {
				this.masterPassword = ''
				this.busy = false
			}
		},

		/**
		 * @return {string} The kit as text.
		 */
		kitText() {
			return recoveryKitText({ code: this.code, account: getCurrentUser()?.uid ?? '', createdAt: this.madeOn }, t)
		},

		/**
		 * Download the kit as a text file.
		 *
		 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#scenario-a-kit-is-created-without-the-code-leaving-the-browser
		 */
		download() {
			const link = document.createElement('a')
			link.href = URL.createObjectURL(new Blob([this.kitText()], { type: 'text/plain' }))
			link.download = 'keepiq-recovery-kit.txt'
			link.click()
			URL.revokeObjectURL(link.href)
		},

		/**
		 * Print the kit on a page of its own.
		 *
		 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#scenario-a-kit-is-created-without-the-code-leaving-the-browser
		 */
		print() {
			const page = window.open('', '_blank', 'noopener=no')
			if (!page) {
				this.error = t('keepiq', 'The print page was blocked. Download the kit instead.')
				return
			}
			const pre = page.document.createElement('pre')
			pre.textContent = this.kitText()
			page.document.body.appendChild(pre)
			page.document.title = t('keepiq', 'Keepiq recovery kit')
			page.print()
		},
	},
}
</script>

<style scoped>
.recovery-kit__title {
	margin-block: 0 var(--default-grid-baseline);
	font-size: 16px;
}

.recovery-kit__hint {
	color: var(--color-text-maxcontrast);
}

.recovery-kit__code {
	font-family: var(--font-face-monospace, monospace);
	font-size: 18px;
	font-weight: 600;
	letter-spacing: 1px;
	word-break: break-all;
}

.recovery-kit__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
	margin-block-start: var(--default-grid-baseline);
}

.recovery-kit__error {
	color: var(--color-error-text);
}
</style>
