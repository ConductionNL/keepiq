<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Identity presentation (card-identity-items §4): name/address/phone/
  email show directly; the BSN is masked by default with reveal + copy.
  Renders nothing while locked — the parent only mounts this with a
  decrypted payload.

  @spec openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
-->
<template>
	<div v-if="payload" class="identity-display" data-testid="identity-display">
		<dl class="identity-display__fields">
			<div v-if="fullName">
				<dt>{{ t('keepiq', 'Name') }}</dt>
				<dd data-testid="identity-name">{{ fullName }}</dd>
			</div>
			<div v-if="payload.address">
				<dt>{{ t('keepiq', 'Address') }}</dt>
				<dd>{{ payload.address }}</dd>
			</div>
			<div v-if="payload.phone">
				<dt>{{ t('keepiq', 'Phone') }}</dt>
				<dd>{{ payload.phone }}</dd>
			</div>
			<div v-if="payload.email">
				<dt>{{ t('keepiq', 'Email') }}</dt>
				<dd>{{ payload.email }}</dd>
			</div>
			<div v-if="payload.bsn">
				<dt>{{ t('keepiq', 'BSN') }}</dt>
				<dd class="identity-display__masked">
					<span data-testid="identity-bsn-value">{{
						revealed ? payload.bsn : '•••••••••'
					}}</span>
					<NcButton
						variant="tertiary"
						data-testid="identity-reveal-bsn"
						@click="revealed = !revealed">
						{{ revealed ? t('keepiq', 'Hide') : t('keepiq', 'Show') }}
					</NcButton>
					<CopyButton
						:value="payload.bsn"
						:label="t('keepiq', 'Copy BSN')" />
				</dd>
			</div>
		</dl>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import CopyButton from './CopyButton.vue'
import { parsePayload } from '../cardIdentity/cardIdentity.js'

export default {
	name: 'IdentityDisplay',
	components: { NcButton, CopyButton },
	props: {
		/** The decrypted identity payload JSON (the secret's `key` value). */
		payloadJson: {
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
		payload() {
			return parsePayload(this.payloadJson)
		},

		fullName() {
			return [this.payload?.firstName, this.payload?.lastName]
				.filter(Boolean)
				.join(' ')
		},
	},
}
</script>

<style scoped>
.identity-display__fields {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.identity-display__fields dt {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #777);
}

.identity-display__masked {
	display: flex;
	align-items: center;
	gap: 8px;
	font-family: monospace;
}
</style>
