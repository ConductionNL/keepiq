<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Renders a `passkey` secret's credential metadata from the decrypted
  canonical JSON (passkey-item-type D7): associated site (RP id / name),
  account (user name / display name), truncated credential id, transports,
  and creation date. The private key is masked and only revealed/copied
  through an explicit action, exactly like a password value. An
  unparseable credential shows an explicit invalid state and never
  fabricated fields. Everything stays in component memory — nothing is
  transmitted or persisted.

  @spec openspec/changes/passkey-item-type/specs/passkey-item-type/spec.md#requirement-passkey-listing-filtering-and-site-associated-presentation
-->
<template>
	<div class="passkey-display" data-testid="passkey-display">
		<div
			v-if="credential === null"
			class="passkey-display__invalid"
			data-testid="passkey-invalid">
			<AlertCircleOutline :size="20" />
			<span>{{ t('doriath', 'Not a valid passkey credential') }}</span>
		</div>

		<dl v-else class="passkey-display__fields">
			<div class="passkey-display__field">
				<dt>{{ t('doriath', 'Site') }}</dt>
				<dd data-testid="passkey-site">
					{{
						credential.rpName
							? `${credential.rpName} (${credential.rpId})`
							: credential.rpId
					}}
				</dd>
			</div>
			<div
				v-if="credential.userName || credential.userDisplayName"
				class="passkey-display__field">
				<dt>{{ t('doriath', 'Account') }}</dt>
				<dd data-testid="passkey-account">
					{{ credential.userDisplayName || credential.userName }}
					<span
						v-if="credential.userDisplayName && credential.userName"
						class="passkey-display__muted">
						({{ credential.userName }})
					</span>
				</dd>
			</div>
			<div class="passkey-display__field">
				<dt>{{ t('doriath', 'Credential ID') }}</dt>
				<dd data-testid="passkey-credential-id">{{ truncatedId }}</dd>
			</div>
			<div v-if="credential.transports.length" class="passkey-display__field">
				<dt>{{ t('doriath', 'Transports') }}</dt>
				<dd data-testid="passkey-transports">
					{{ credential.transports.join(', ') }}
				</dd>
			</div>
			<div v-if="credential.createdAt" class="passkey-display__field">
				<dt>{{ t('doriath', 'Created') }}</dt>
				<dd data-testid="passkey-created">{{ createdDisplay }}</dd>
			</div>
			<div class="passkey-display__field">
				<dt>{{ t('doriath', 'Private key') }}</dt>
				<dd class="passkey-display__key-row">
					<span data-testid="passkey-private-key">{{
						revealed ? credential.privateKey : maskedKey
					}}</span>
					<button
						type="button"
						class="passkey-display__reveal"
						:aria-label="
							revealed
								? t('doriath', 'Hide private key')
								: t('doriath', 'Show private key')
						"
						data-testid="passkey-reveal"
						@click="revealed = !revealed">
						<component :is="revealed ? 'EyeOff' : 'Eye'" :size="18" />
					</button>
					<CopyButton
						:value="credential.privateKey"
						:label="t('doriath', 'Copy private key')"
						data-testid="passkey-copy-key" />
				</dd>
			</div>
		</dl>
	</div>
</template>

<script>
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import CopyButton from './CopyButton.vue'
import { parsePasskey, truncateCredentialId } from '../passkey/passkey.js'

export default {
	name: 'PasskeyDisplay',
	components: {
		AlertCircleOutline,
		Eye,
		EyeOff,
		CopyButton,
	},

	props: {
		/** The decrypted `key` value: canonical passkey credential JSON. */
		credentialJson: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			revealed: false,
		}
	},

	computed: {
		credential() {
			return parsePasskey(this.credentialJson)
		},

		truncatedId() {
			return truncateCredentialId(this.credential?.credentialId)
		},

		maskedKey() {
			return '••••••••••••'
		},

		createdDisplay() {
			const parsed = Date.parse(this.credential?.createdAt ?? '')
			return Number.isNaN(parsed)
				? this.credential?.createdAt
				: new Date(parsed).toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.passkey-display__invalid {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-error-text);
}

.passkey-display__fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.passkey-display__field dt {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #777);
}

.passkey-display__key-row {
	display: flex;
	align-items: center;
	gap: 8px;
	word-break: break-all;
}

.passkey-display__reveal {
	background: none;
	border: 0;
	padding: 4px;
	cursor: pointer;
}

.passkey-display__muted {
	color: var(--color-text-maxcontrast, #777);
}
</style>
