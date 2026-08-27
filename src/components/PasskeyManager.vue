<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Passkey vault-login management (passkey-vault-login §4.2/§4.3/§4.4):
  list enrolled passkeys with status/last-used, enroll a new one (requires
  the unlocked vault + master password to build the PRF-wrapped envelope),
  revoke per-row, and prompt re-enrollment for stale envelopes after a
  master-password change. Hidden entirely where WebAuthn is unavailable.

  @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
  @spec openspec/specs/passkey-vault-login/spec.md#requirement-envelopes-are-invalidated-when-the-unlock-key-changes
  @spec openspec/specs/passkey-vault-login/spec.md#requirement-prf-support-is-feature-detected-and-degrades-gracefully
-->
<template>
	<div
		v-if="store.supported"
		class="passkey-manager"
		data-testid="passkey-manager">
		<h4>{{ t('keepiq', 'Passkey unlock') }}</h4>
		<p class="passkey-manager__hint">
			{{
				t(
					'keepiq',
					'Unlock your vault with a passkey (Touch ID, Windows Hello, or a security key). Your master password always keeps working — losing an authenticator loses no data.',
				)
			}}
		</p>

		<NcNoteCard v-if="store.error" type="error">
			{{ store.error }}
		</NcNoteCard>
		<NcNoteCard v-if="hasStale" type="warning" data-testid="passkey-stale-note">
			{{
				t(
					'keepiq',
					'Your master password changed — re-enroll your passkeys to unlock with them again.',
				)
			}}
		</NcNoteCard>

		<ul
			v-if="store.credentials.length"
			class="passkey-manager__list"
			data-testid="passkey-list">
			<li
				v-for="cred in store.credentials"
				:key="cred.id"
				:data-testid="`passkey-${cred.id}`">
				<span class="passkey-manager__label">{{
					cred.label || t('keepiq', 'Passkey')
				}}</span>
				<span
					:class="`passkey-manager__status passkey-manager__status--${cred.status}`"
					>{{ cred.status }}</span
				>
				<span class="passkey-manager__muted">{{
					cred.lastUsedAt
						? t('keepiq', 'last used {when}', {
								when: formatDate(cred.lastUsedAt),
							})
						: t('keepiq', 'never used')
				}}</span>
				<NcButton
					variant="tertiary"
					:data-testid="`passkey-revoke-${cred.id}`"
					@click="store.revoke(cred.id)">
					{{ t('keepiq', 'Revoke') }}
				</NcButton>
			</li>
		</ul>

		<div v-if="!enrollOpen" class="passkey-manager__add">
			<NcButton
				variant="secondary"
				:disabled="vaultLocked"
				data-testid="passkey-add"
				@click="enrollOpen = true">
				<template #icon>
					<KeyIcon :size="20" />
				</template>
				{{ t('keepiq', 'Add passkey') }}
			</NcButton>
			<span v-if="vaultLocked" class="passkey-manager__muted">{{
				t('keepiq', 'Unlock your vault to add a passkey.')
			}}</span>
		</div>

		<div v-else class="passkey-manager__form" data-testid="passkey-enroll-form">
			<NcTextField
				v-model="label"
				:label="t('keepiq', 'Passkey name (e.g. MacBook Touch ID)')"
				data-testid="passkey-label" />
			<NcPasswordField
				v-model="masterPassword"
				:label="t('keepiq', 'Confirm your master password')"
				data-testid="passkey-master" />
			<div class="passkey-manager__form-actions">
				<NcButton
					variant="primary"
					:disabled="busy || !masterPassword"
					data-testid="passkey-enroll"
					@click="onEnroll">
					{{
						busy
							? t('keepiq', 'Enrolling…')
							: t('keepiq', 'Enroll passkey')
					}}
				</NcButton>
				<NcButton variant="tertiary" :disabled="busy" @click="cancel">
					{{ t('keepiq', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcNoteCard, NcPasswordField, NcTextField } from '@nextcloud/vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import { usePasskeyStore } from '../store/modules/passkey.js'
import { useSessionStore } from '../store/modules/session.js'

export default {
	name: 'PasskeyManager',

	components: {
		NcButton,
		NcNoteCard,
		NcPasswordField,
		NcTextField,
		KeyIcon,
	},

	data() {
		return {
			store: usePasskeyStore(),
			enrollOpen: false,
			label: '',
			masterPassword: '',
			busy: false,
		}
	},

	computed: {
		vaultLocked() {
			return useSessionStore().isLocked
		},

		hasStale() {
			return this.store.credentials.some((c) => c.status === 'stale')
		},
	},

	/**
	 * Load enrolled passkeys when WebAuthn is available.
	 */
	created() {
		if (this.store.supported) {
			this.store.fetchCredentials()
		}
	},

	methods: {
		/**
		 * Run the enrollment ceremony.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
		 */
		async onEnroll() {
			this.busy = true
			try {
				await this.store.enroll(this.masterPassword, this.label)
				showSuccess(t('keepiq', 'Passkey enrolled.'))
				this.cancel()
			} catch (e) {
				this.store.error = e?.message || t('keepiq', 'Enrollment failed')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Close the enroll form and clear the captured master password.
		 */
		cancel() {
			this.enrollOpen = false
			this.masterPassword = ''
			this.label = ''
		},

		/**
		 * Render an ISO date briefly.
		 *
		 * @param {string} iso The ISO timestamp.
		 * @return {string}
		 */
		formatDate(iso) {
			return new Date(iso).toLocaleDateString()
		},
	},
}
</script>

<style scoped lang="scss">
.passkey-manager {
	&__hint,
	&__muted {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__list {
		list-style: none;
		padding: 0;

		li {
			display: flex;
			align-items: center;
			gap: 10px;
			padding: 4px 0;
			border-bottom: 1px solid var(--color-border);
		}
	}

	&__label {
		font-weight: bold;
	}

	&__status--active {
		color: var(--color-success-text);
	}

	&__status--stale {
		color: var(--color-warning-text);
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: 8px;
		max-width: 380px;
	}

	&__form-actions {
		display: flex;
		gap: 8px;
	}
}
</style>
