<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The value part of the create and edit secret dialogs: the card or identity
  fields for those types (card-identity-items 3.1), a text area for a value
  that spans several lines, otherwise the secret value with its key
  generator. The dialog owns the values; this component
  only edits them through update events.

  @spec openspec/specs/card-identity-items/spec.md#requirement-composite-payload-stored-as-ciphertext-in-the-key-field
  @spec openspec/specs/key-generator/spec.md#requirement-frontend-integration
-->
<template>
	<template v-if="isCard || isIdentity">
		<template v-for="field in fields" :key="field.key">
			<NcPasswordField
				v-if="field.secret"
				:modelValue="payload[field.key]"
				:label="field.label"
				:helperText="hints[field.key]"
				:data-testid="field.testid"
				@update:modelValue="setField(field.key, $event)" />
			<NcTextField
				v-else
				:modelValue="payload[field.key]"
				:label="field.label"
				:helperText="hints[field.key]"
				:data-testid="field.testid"
				@update:modelValue="setField(field.key, $event)" />
		</template>
	</template>
	<!-- A certificate or note is several lines: a password input would
	     drop the line breaks of a PEM block. -->
	<NcTextArea
		v-else-if="valueMultiline"
		:modelValue="value"
		:label="valueLabel"
		:required="valueRequired"
		rows="6"
		data-testid="secret-value-multiline"
		@update:modelValue="$emit('update:value', $event)" />
	<div v-else class="secret-form__value-row">
		<NcPasswordField
			:modelValue="value"
			class="secret-form__value-field"
			:label="valueLabel"
			:required="valueRequired"
			@update:modelValue="$emit('update:value', $event)" />
		<NcButton
			variant="tertiary-no-background"
			:title="t('keepiq', 'Generate a strong key')"
			:aria-label="t('keepiq', 'Generate a strong key')"
			@click="$emit('generate')">
			<template #icon>
				<Dice5 :size="20" />
			</template>
		</NcButton>
	</div>

	<KeyGeneratorModal
		v-if="generatorOpen"
		:open="generatorOpen"
		@update:open="$emit('update:generatorOpen', $event)"
		@generated="$emit('generated', $event)" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcPasswordField, NcTextArea, NcTextField } from '@nextcloud/vue'
import Dice5 from 'vue-material-design-icons/Dice5.vue'
import KeyGeneratorModal from '../dialogs/KeyGeneratorModal.vue'
import { cardNumberHint, securityCodeHint } from '../cardIdentity/cardIdentity.js'

export default {
	name: 'SecretPayloadFields',

	components: {
		Dice5,
		KeyGeneratorModal,
		NcButton,
		NcPasswordField,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** Whether the secret is a payment card. */
		isCard: {
			type: Boolean,
			default: false,
		},

		/** Whether the secret is an identity. */
		isIdentity: {
			type: Boolean,
			default: false,
		},

		/** The card fields, by name. */
		card: {
			type: Object,
			required: true,
		},

		/** The identity fields, by name. */
		identity: {
			type: Object,
			required: true,
		},

		/** The secret value of the other types. */
		value: {
			type: String,
			default: '',
		},

		/** The label of the value field. */
		valueLabel: {
			type: String,
			required: true,
		},

		/** Whether the value field is marked as required. */
		valueRequired: {
			type: Boolean,
			default: false,
		},

		/** Whether the value spans several lines, such as a certificate. */
		valueMultiline: {
			type: Boolean,
			default: false,
		},

		/** Whether the key generator dialog is open. */
		generatorOpen: {
			type: Boolean,
			default: false,
		},
	},

	emits: [
		'update:value',
		'update:card',
		'update:identity',
		'update:generatorOpen',
		'generate',
		'generated',
	],

	computed: {
		/**
		 * The card fields as [name, label, secret], in form order.
		 *
		 * @return {Array<Array>}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-payment-card-and-identity-system-types
		 */
		cardFields() {
			return [
				['number', t('keepiq', 'Card number'), true],
				['expiry', t('keepiq', 'Expiry (MM/YY)'), false],
				['cvv', t('keepiq', 'CVV'), true],
				['pin', t('keepiq', 'PIN (optional)'), true],
				['cardholder', t('keepiq', 'Cardholder name'), false],
			]
		},

		/**
		 * The identity fields as [name, label, secret], in form order.
		 *
		 * @return {Array<Array>}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-payment-card-and-identity-system-types
		 */
		identityFields() {
			return [
				['firstName', t('keepiq', 'First name'), false],
				['lastName', t('keepiq', 'Last name'), false],
				['address', t('keepiq', 'Address'), false],
				['phone', t('keepiq', 'Phone'), false],
				['email', t('keepiq', 'Email'), false],
				['bsn', t('keepiq', 'BSN'), true],
			]
		},

		/**
		 * The fields of the card or identity, in form order, with their test
		 * ids (card-number, identity-first-name, ...).
		 *
		 * @return {Array<{key: string, label: string, testid: string, secret: boolean}>}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-payment-card-and-identity-system-types
		 */
		fields() {
			const prefix = this.isCard ? 'card' : 'identity'
			const list = this.isCard ? this.cardFields : this.identityFields
			return list.map(([key, label, secret]) => ({
				key,
				label,
				secret,
				testid: `${prefix}-${key.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())}`,
			}))
		},

		/**
		 * The card or the identity being edited.
		 *
		 * @return {object}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-payment-card-and-identity-system-types
		 */
		payload() {
			return this.isCard ? this.card : this.identity
		},

		/**
		 * The non-blocking hints under the card number and security code,
		 * by field name; empty for every other field.
		 *
		 * @return {{number?: string, cvv?: string}}
		 * @spec openspec/changes/vault-card-brand-formats/specs/card-identity-items/spec.md#requirement-non-blocking-card-format-hints
		 */
		hints() {
			if (!this.isCard) {
				return {}
			}
			const number = cardNumberHint(this.card.number)
			const code = securityCodeHint(this.card.number, this.card.cvv)
			return {
				number: number ? this.numberHintText(number) : '',
				cvv: code
					? t('keepiq', '{brand} cards use a {size}-digit {name}. Saving is not blocked.', code)
					: '',
			}
		},
	},

	methods: {
		t,

		/**
		 * The text of a card number hint.
		 *
		 * @param {{code: string, brand?: string}} hint From `cardNumberHint`.
		 * @return {string}
		 * @spec openspec/changes/vault-card-brand-formats/specs/card-identity-items/spec.md#requirement-non-blocking-card-format-hints
		 */
		numberHintText(hint) {
			if (hint.code === 'length') {
				return t('keepiq', '{brand} does not issue card numbers of this length. Saving is not blocked.', { brand: hint.brand })
			}
			if (hint.code === 'lengthUnknown') {
				return t('keepiq', 'Card numbers have 12 to 19 digits. Saving is not blocked.')
			}
			return t('keepiq', 'This number does not pass the card checksum — double-check it (saving is not blocked).')
		},

		/**
		 * Emit the card or identity with one field changed.
		 *
		 * @param {string} key The field name.
		 * @param {string} text The new text.
		 * @return {void}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-payment-card-and-identity-system-types
		 */
		setField(key, text) {
			this.$emit(this.isCard ? 'update:card' : 'update:identity', {
				...this.payload,
				[key]: text,
			})
		},
	},
}
</script>

<style scoped>
.secret-form__value-row {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.secret-form__value-field {
	flex: 1 1 auto;
}
</style>
