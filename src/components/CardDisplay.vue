<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Payment-card presentation (card-identity-items §4): brand + last-4 are
  derived in-browser from the decrypted number (never stored); number,
  CVV, and PIN are masked by default with per-field reveal + copy;
  expiry and cardholder show directly. Renders nothing while locked —
  the parent only mounts this with a decrypted payload.

  @spec openspec/changes/card-identity-items/specs/card-identity-items/spec.md#requirement-masked-presentation
-->
<template>
	<div v-if="payload" class="card-display" data-testid="card-display">
		<div class="card-display__summary" data-testid="card-summary">
			<span class="card-display__brand">{{ brand }}</span>
			<span v-if="last4" class="card-display__last4">•••• {{ last4 }}</span>
		</div>

		<dl class="card-display__fields">
			<div v-if="payload.number">
				<dt>{{ t('doriath', 'Number') }}</dt>
				<dd class="card-display__masked">
					<span data-testid="card-number-value">{{ revealed.number ? payload.number : '•••• •••• •••• ••••' }}</span>
					<NcButton variant="tertiary" :data-testid="'card-reveal-number'" @click="toggle('number')">
						{{ revealed.number ? t('doriath', 'Hide') : t('doriath', 'Show') }}
					</NcButton>
					<CopyButton :value="payload.number" :label="t('doriath', 'Copy number')" />
				</dd>
			</div>
			<div v-if="payload.expiry">
				<dt>{{ t('doriath', 'Expiry') }}</dt>
				<dd>{{ payload.expiry }}</dd>
			</div>
			<div v-if="payload.cvv">
				<dt>{{ t('doriath', 'CVV') }}</dt>
				<dd class="card-display__masked">
					<span data-testid="card-cvv-value">{{ revealed.cvv ? payload.cvv : '•••' }}</span>
					<NcButton variant="tertiary" :data-testid="'card-reveal-cvv'" @click="toggle('cvv')">
						{{ revealed.cvv ? t('doriath', 'Hide') : t('doriath', 'Show') }}
					</NcButton>
					<CopyButton :value="payload.cvv" :label="t('doriath', 'Copy CVV')" />
				</dd>
			</div>
			<div v-if="payload.pin">
				<dt>{{ t('doriath', 'PIN') }}</dt>
				<dd class="card-display__masked">
					<span data-testid="card-pin-value">{{ revealed.pin ? payload.pin : '••••' }}</span>
					<NcButton variant="tertiary" :data-testid="'card-reveal-pin'" @click="toggle('pin')">
						{{ revealed.pin ? t('doriath', 'Hide') : t('doriath', 'Show') }}
					</NcButton>
					<CopyButton :value="payload.pin" :label="t('doriath', 'Copy PIN')" />
				</dd>
			</div>
			<div v-if="payload.cardholder">
				<dt>{{ t('doriath', 'Cardholder') }}</dt>
				<dd>{{ payload.cardholder }}</dd>
			</div>
		</dl>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import CopyButton from './CopyButton.vue'
import { parsePayload, cardBrand, cardLast4 } from '../cardIdentity/cardIdentity.js'

export default {
	name: 'CardDisplay',
	components: { NcButton, CopyButton },
	props: {
		/** The decrypted card payload JSON (the secret's `key` value). */
		payloadJson: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			revealed: { number: false, cvv: false, pin: false },
		}
	},
	computed: {
		payload() {
			return parsePayload(this.payloadJson)
		},
		brand() {
			return cardBrand(this.payload?.number ?? '')
		},
		last4() {
			return cardLast4(this.payload?.number ?? '')
		},
	},
	methods: {
		/**
		 * Toggle one masked field's reveal state.
		 *
		 * @param {string} field The field name.
		 * @return {void}
		 */
		toggle(field) {
			this.revealed = { ...this.revealed, [field]: !this.revealed[field] }
		},
	},
}
</script>

<style scoped>
.card-display {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.card-display__summary {
	display: flex;
	align-items: center;
	gap: 8px;
	font-weight: 600;
}

.card-display__fields {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.card-display__fields dt {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #777);
}

.card-display__masked {
	display: flex;
	align-items: center;
	gap: 8px;
	font-family: monospace;
}
</style>
