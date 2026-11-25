<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  SecretQrModal — a login's password as a QR code, to hand it to a device
  without Keepiq (vault-item-clone-preview-and-print D3). The code is shown
  only after the master password is entered again and verified in the
  browser, with the same proof of knowledge the plaintext export uses. The
  code is generated in the browser; nothing is fetched or sent. The one-time
  code seed is never encoded (D4).

  @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Show as QR code')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="secret-qr" data-testid="secret-qr">
			<template v-if="!verified">
				<NcNoteCard type="warning">
					{{
						t(
							'keepiq',
							'Anyone who can see the screen can scan this password. Enter your master password to show it.',
						)
					}}
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error" data-testid="secret-qr-error">
					{{ error }}
				</NcNoteCard>
				<form @submit.prevent="onVerify">
					<NcPasswordField
						v-model="masterPassword"
						:label="t('keepiq', 'Re-enter your master password')"
						autocomplete="current-password"
						data-testid="secret-qr-password" />
				</form>
			</template>
			<template v-else>
				<svg
					class="secret-qr__code"
					:viewBox="`0 0 ${box} ${box}`"
					role="img"
					:aria-label="
						t('keepiq', 'QR code of the password of {name}', {
							name: secretName,
						})
					"
					shape-rendering="crispEdges"
					data-testid="secret-qr-code">
					<rect width="100%" height="100%" fill="#ffffff" />
					<path :d="path" fill="#000000" />
				</svg>
				<p class="secret-qr__name">
					{{ secretName }}
				</p>
			</template>
		</div>
		<template #actions>
			<NcButton data-testid="secret-qr-close" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="!verified"
				variant="primary"
				:disabled="masterPasswordPending"
				data-testid="secret-qr-show"
				@click="onVerify">
				{{ t('keepiq', 'Show QR code') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import masterPasswordCheck from '../mixins/masterPasswordCheck.js'
import { useSessionStore } from '../store/modules/session.js'
import { qrMatrix, qrPath, QUIET_ZONE } from '../utils/qrCode.js'

/**
 * The QR code of one login's password, behind a master password check.
 * Emits `close` when dismissed.
 */
export default {
	name: 'SecretQrModal',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcPasswordField,
	},

	mixins: [masterPasswordCheck],

	props: {
		/** The login's name, shown under the code. */
		secretName: {
			type: String,
			default: '',
		},

		/** The decrypted password the code encodes. */
		password: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			open: true,
			masterPassword: '',
			error: null,
			busy: false,
			verified: false,
			matrix: null,
		}
	},

	computed: {
		/**
		 * The session store, read by the master password check.
		 *
		 * @return {object}
		 * @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
		 */
		sessionStore() {
			return useSessionStore()
		},

		/**
		 * The SVG's side in modules, quiet zone included.
		 *
		 * @return {number}
		 * @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
		 */
		box() {
			return this.matrix ? this.matrix.size + 2 * QUIET_ZONE : 0
		},

		/**
		 * The dark modules as one SVG path.
		 *
		 * @return {string}
		 * @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
		 */
		path() {
			return this.matrix ? qrPath(this.matrix) : ''
		},
	},

	methods: {
		t,

		/**
		 * Check the master password; build the code only when it opens the vault key.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
		 */
		async onVerify() {
			if (await this.masterPasswordChecked()) {
				this.matrix = qrMatrix(this.password)
				this.verified = true
			}
		},

		/**
		 * Forward the open state; emit `close` when dismissed, and drop the code.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 * @spec exclude Dialog open-state plumbing; no domain behaviour.
		 */
		onUpdateOpen(value) {
			this.open = value
			if (!value) {
				this.matrix = null
				this.verified = false
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.secret-qr {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	align-items: stretch;
}

.secret-qr__code {
	align-self: center;
	width: min(320px, 100%);
	height: auto;
	/* The code stays black on white in a dark theme: scanners need the contrast. */
	border: 1px solid var(--color-border);
}

.secret-qr__name {
	align-self: center;
	color: var(--color-main-text);
	font-weight: bold;
}
</style>
