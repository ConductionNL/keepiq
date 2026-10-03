<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  "Forgot your master password?" on the lock screen, for an enrolled user
  (crypto-organisation-account-recovery D3, D4). Files a request with a
  one-time key kept in this browser, shows the words to read to the
  officers, and once an officer handed the key over, sets a new master
  password. Emits `recovered` with the officer who handled it.

  @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
-->
<template>
	<div v-if="enrolled" class="forgot-password" data-testid="forgot-password">
		<NcButton
			v-if="
				!request
				|| ['declined', 'expired', 'fulfilled'].includes(request.status)
			"
			variant="tertiary"
			:wide="true"
			:disabled="busy"
			data-testid="forgot-password-start"
			@click="start">
			{{ t('keepiq', 'Forgot your master password?') }}
		</NcButton>

		<template v-else>
			<template v-if="request.fromThisBrowser !== false">
				<p>
					{{
						t(
							'keepiq',
							'Your recovery officers have been told. Read them these words when they call or meet you:',
						)
					}}
				</p>
				<p
					class="forgot-password__phrase"
					data-testid="forgot-password-phrase">
					{{ request.phrase }}
				</p>
			</template>
			<NcNoteCard v-else type="warning">
				{{
					t('keepiq', 'Finish the recovery in the browser you asked from.')
				}}
			</NcNoteCard>

			<template
				v-if="request.sealedResult && request.fromThisBrowser !== false">
				<p>
					{{
						t(
							'keepiq',
							'Your key is back. Choose a new master password.',
						)
					}}
				</p>
				<NcPasswordField
					v-model="newPassword"
					:label="t('keepiq', 'New master password')"
					autocomplete="new-password"
					:disabled="busy" />
				<NcPasswordField
					v-model="repeatPassword"
					:label="t('keepiq', 'Repeat the new master password')"
					autocomplete="new-password"
					:disabled="busy" />
				<NcButton
					variant="primary"
					:wide="true"
					:disabled="
						busy
						|| newPassword.length < minLength
						|| newPassword !== repeatPassword
					"
					data-testid="forgot-password-complete"
					@click="complete">
					{{ t('keepiq', 'Set the new master password') }}
				</NcButton>
			</template>
			<NcButton
				v-else
				variant="tertiary"
				:wide="true"
				:disabled="busy"
				data-testid="forgot-password-check"
				@click="refresh">
				{{ t('keepiq', 'Check again') }}
			</NcButton>
		</template>

		<p v-if="error" class="forgot-password__error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import { useAccountRecoveryStore } from '../store/modules/accountRecovery.js'

export default {
	name: 'ForgotPasswordRecovery',
	components: { NcButton, NcNoteCard, NcPasswordField },
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
			enrolled: false,
			newPassword: '',
			repeatPassword: '',
			busy: false,
			error: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
		 */
		store() {
			return useAccountRecoveryStore()
		},

		/**
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
		 */
		request() {
			// A device-purpose request belongs to "Ask your organisation instead".
			const request = this.store.myRequest
			return request && request.purpose !== 'device' ? request : null
		},
	},

	/**
	 * Offer the option only to an enrolled user, and resume an open request.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
	 */
	async created() {
		try {
			const status = await this.store.fetchStatus()
			this.enrolled = status?.enrolled === true && status?.policy !== 'off'
			if (this.enrolled) {
				await this.store.fetchMyRequest()
			}
		} catch {
			this.enrolled = false
		}
	},

	methods: {
		/**
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
		 */
		async start() {
			await this.guard(async () => {
				await this.store.startRequest()
				await this.store.fetchMyRequest()
			})
		},

		/**
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
		 */
		async refresh() {
			await this.guard(() => this.store.fetchMyRequest())
		},

		/**
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
		 */
		async complete() {
			await this.guard(async () => {
				const handledBy = await this.store.complete(this.newPassword)
				this.newPassword = ''
				this.repeatPassword = ''
				this.$emit('recovered', handledBy)
			})
		},

		/**
		 * @param {Function} action The action.
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
		 */
		async guard(action) {
			this.busy = true
			this.error = null
			try {
				await action()
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.forgot-password {
	margin-top: 12px;
}

.forgot-password__phrase {
	font-size: 18px;
	font-weight: 600;
	text-align: center;
}

.forgot-password__error {
	color: var(--color-error-text);
	font-size: 13px;
}
</style>
