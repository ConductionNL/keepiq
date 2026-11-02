<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The value part of the create and edit secret dialogs: the card or identity
  fields for those types (card-identity-items 3.1), otherwise the secret
  value with its key generator. The dialog owns the values; this component
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
				:data-testid="field.testid"
				@update:modelValue="setField(field.key, $event)" />
			<NcTextField
				v-else
				:modelValue="payload[field.key]"
				:label="field.label"
				:data-testid="field.testid"
				@update:modelValue="setField(field.key, $event)" />
			<NcNoteCard
				v-if="field.key === 'number' && luhnHint"
				type="warning"
				data-testid="card-luhn-hint">
				{{
					t(
						'keepiq',
						'This number does not pass the card checksum — double-check it (saving is not blocked).',
					)
				}}
			</NcNoteCard>
		</template>
	</template>
	<div v-else class="secret-form__value-row">
		<NcPasswordField
			:modelValue="value"
			class="secret-form__value-field"
			:label="valueLabel"
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
import { NcButton, NcNoteCard, NcPasswordField, NcTextField } from '@nextcloud/vue'
import Dice5 from 'vue-material-design-icons/Dice5.vue'
import KeyGeneratorModal from '../dialogs/KeyGeneratorModal.vue'
import { luhnValid } from '../cardIdentity/cardIdentity.js'

export default {
	name: 'SecretPayloadFields',

	components: {
		Dice5,
		KeyGeneratorModal,
		NcButton,
		NcNoteCard,
		NcPasswordField,
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

		/** Whether the key generator dialog is open. */
		generatorOpen: {
			type: Boolean,
			default: false,
		},

		/** Whether to hint at a card number that fails the checksum. */
		checkLuhn: {
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
		 * Best-effort checksum hint on a card number; it never blocks saving (3.2).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-payment-card-and-identity-system-types
		 */
		luhnHint() {
			return (
				this.checkLuhn
				&& this.card.number !== ''
				&& !luhnValid(this.card.number)
			)
		},
	},

	methods: {
		t,

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
