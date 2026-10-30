<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The passkeys a login carries, one PasskeyDisplay each with the private key
  row left out: site, account and creation date only, in every mode. Where
  the holder may change the login, each passkey has a Delete action behind a
  confirmation; the list then emits the remaining array for the caller to
  save. Passkeys are added by a provider or an import, never here.

  @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
-->
<template>
	<div class="passkey-list" data-testid="passkey-list">
		<!-- Focusable so focus has somewhere to go once a deleted passkey's
		     row is gone. -->
		<p
			class="passkey-list__count"
			tabindex="-1"
			data-testid="passkey-list-count">
			{{ n('keepiq', '%n passkey', '%n passkeys', entries.length) }}
		</p>
		<ul class="passkey-list__items">
			<li
				v-for="(entry, index) in entries"
				:key="`${entry.storedIndex}-${entry.credentialId || ''}`"
				class="passkey-list__item"
				data-testid="passkey-list-item">
				<PasskeyDisplay
					class="passkey-list__display"
					:credentialJson="entry.json"
					:hidePrivateKey="true" />
				<NcButton
					v-if="canDelete"
					variant="tertiary"
					:disabled="busy"
					:ariaLabel="
						t('keepiq', 'Delete passkey for {site}', {
							site: entry.rpName || entry.rpId || '',
						})
					"
					:title="t('keepiq', 'Delete passkey')"
					data-testid="passkey-list-delete"
					@click="confirmIndex = index">
					<template #icon>
						<Delete :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>

		<PasskeyDeleteDialog
			:passkey="confirmIndex === null ? null : entries[confirmIndex] || null"
			@close="confirmIndex = null"
			@confirm="onConfirm" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import PasskeyDeleteDialog from '../dialogs/PasskeyDeleteDialog.vue'
import PasskeyDisplay from './PasskeyDisplay.vue'
import { passkeyEntries, withoutPasskey } from '../passkey/loginPasskeys.js'

export default {
	name: 'PasskeyList',

	components: {
		Delete,
		NcButton,
		PasskeyDeleteDialog,
		PasskeyDisplay,
	},

	props: {
		/** The login's decrypted `passkeys` value: a JSON array of credentials. */
		passkeysJson: {
			type: String,
			default: '',
		},

		/** Whether the holder may change the login, and so delete a passkey. */
		canDelete: {
			type: Boolean,
			default: false,
		},

		/** A delete is being saved: further deletes wait for it. */
		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['delete'],

	data() {
		return {
			/** The index of the passkey awaiting delete confirmation, or null. */
			confirmIndex: null,
		}
	},

	computed: {
		/**
		 * Each stored credential, with its own JSON for PasskeyDisplay and its
		 * position in the stored array.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
		 */
		entries() {
			return passkeyEntries(this.passkeysJson).map(({ index, credential }) => {
				// The private key never leaves this list, not even as a prop.
				const { privateKey: _privateKey, ...metadata } = credential
				return {
					...metadata,
					storedIndex: index,
					json: JSON.stringify(metadata),
				}
			})
		},
	},

	methods: {
		t,
		n,

		/**
		 * Emit the `passkeys` value without the confirmed passkey.
		 *
		 * @return {void}
		 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
		 */
		onConfirm() {
			const entry =
				this.confirmIndex === null ? null : this.entries[this.confirmIndex]
			this.confirmIndex = null
			if (!entry) {
				return
			}
			this.$emit(
				'delete',
				withoutPasskey(this.passkeysJson, entry.storedIndex),
			)
		},

		/**
		 * Move focus to the list itself, for after a passkey's row is gone.
		 *
		 * @return {void}
		 * @spec exclude Focus management after a re-render.
		 */
		focus() {
			this.$el.querySelector('.passkey-list__count')?.focus()
		},
	},
}
</script>

<style scoped>
.passkey-list__count {
	margin: 0 0 4px;
	color: var(--color-text-maxcontrast);
}

.passkey-list__items {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.passkey-list__item {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.passkey-list__display {
	flex: 1 1 auto;
	min-width: 0;
}
</style>
