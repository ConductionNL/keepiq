<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  "I lost my master password" on the lock screen, for a user with a recovery
  kit (crypto-recovery-kit 2.3). The recovery code unwraps the private key in
  this browser, a new master password is set through the routine change, the
  spent kit is deleted and a new kit is offered. A wrong code opens nothing.
  Emits `recovered` once the vault is open.

  @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-the-recovery-code-reopens-the-vault-and-sets-a-new-master-password
-->
<template>
	<div v-if="hasKit" class="kit-unlock" data-testid="recovery-kit-unlock">
		<NcButton
			v-if="step === 'closed'"
			variant="tertiary"
			:wide="true"
			data-testid="recovery-kit-unlock-start"
			@click="step = 'code'">
			{{ t('keepiq', 'I lost my master password') }}
		</NcButton>

		<template v-else-if="step === 'code'">
			<p>{{ t('keepiq', 'Enter the code from your recovery kit and choose a new master password.') }}</p>
			<NcTextField
				v-model="code"
				:label="t('keepiq', 'Recovery code')"
				:disabled="busy"
				autocomplete="off"
				spellcheck="false"
				data-testid="recovery-kit-unlock-code" />
			<NcPasswordField
				v-model="newPassword"
				:label="t('keepiq', 'New master password')"
				:disabled="busy"
				autocomplete="new-password"
				data-testid="recovery-kit-unlock-password" />
			<NcPasswordField
				v-model="repeatPassword"
				:label="t('keepiq', 'Repeat the new master password')"
				:disabled="busy"
				autocomplete="new-password"
				data-testid="recovery-kit-unlock-repeat" />
			<NcButton
				variant="primary"
				:wide="true"
				:disabled="!canRecover"
				data-testid="recovery-kit-unlock-submit"
				@click="recover">
				{{ t('keepiq', 'Open my vault') }}
			</NcButton>
		</template>

		<template v-else-if="step === 'offer'">
			<NcNoteCard type="success">
				{{ t('keepiq', 'Your vault is open and your recovery kit is used up. Make a new kit now, with your new master password.') }}
			</NcNoteCard>
			<RecoveryKitPanel />
			<NcButton
				variant="primary"
				:wide="true"
				data-testid="recovery-kit-unlock-continue"
				@click="$emit('recovered')">
				{{ t('keepiq', 'Continue to my vault') }}
			</NcButton>
		</template>

		<p v-if="error" class="kit-unlock__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard, NcPasswordField, NcTextField } from '@nextcloud/vue'
import RecoveryKitPanel from './RecoveryKitPanel.vue'
import { normaliseRecoveryCode } from '../crypto/recoveryKit.js'
import { recoverWithCode, recoveryKitStatus } from '../services/recoveryKit.js'
import { useSessionStore } from '../store/modules/session.js'

export default {
	name: 'RecoveryKitUnlock',
	components: { NcButton, NcNoteCard, NcPasswordField, NcTextField, RecoveryKitPanel },
	props: {
		/** The organisation's minimum master password length. */
		minLength: {
			type: Number,
			default: 12,
		},
	},

	emits: ['recovered'],

	data() {
		return {
			hasKit: false,
			step: 'closed',
			code: '',
			newPassword: '',
			repeatPassword: '',
			busy: false,
			error: null,
		}
	},

	computed: {
		/**
		 * @return {boolean} Whether the code reads as a code and the new password is long enough and repeated.
		 */
		canRecover() {
			return !this.busy
				&& normaliseRecoveryCode(this.code) !== ''
				&& this.newPassword.length >= this.minLength
				&& this.newPassword === this.repeatPassword
		},
	},

	async created() {
		try {
			this.hasKit = (await recoveryKitStatus()) !== null
		} catch {
			this.hasKit = false
		}
	},

	methods: {
		t,

		/**
		 * Recover with the code, unlock with the new master password and offer a new kit.
		 *
		 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#scenario-a-user-who-lost-the-master-password-gets-back-in
		 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#scenario-a-wrong-code-opens-nothing
		 */
		async recover() {
			this.busy = true
			this.error = null
			try {
				await recoverWithCode(this.code, this.newPassword)
				await useSessionStore().unlock(this.newPassword)
				this.step = 'offer'
			} catch (e) {
				this.error = e?.response?.status === 429
					? t('keepiq', 'Too many attempts. Try again in an hour.')
					: t('keepiq', 'This recovery code does not open your vault.')
			} finally {
				this.code = ''
				this.newPassword = ''
				this.repeatPassword = ''
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.kit-unlock {
	margin-block-start: calc(var(--default-grid-baseline) * 3);
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.kit-unlock__error {
	color: var(--color-error-text);
	font-size: 13px;
}
</style>
