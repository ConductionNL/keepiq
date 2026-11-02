/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { translate as t } from '@nextcloud/l10n'
import {
	CARD_TYPE_NAME,
	IDENTITY_TYPE_NAME,
	serializeCard,
	serializeIdentity,
} from '../cardIdentity/cardIdentity.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { missingRequired, typedFieldsOf } from '../utils/typedFields.js'

/**
 * The form state the create and edit secret dialogs share: the value, the
 * card and identity fields, the typed fields of the chosen type and the key
 * generator. The host provides `typeId` and an `open` flag.
 */
export const secretPayloadForm = {
	data() {
		return {
			value: '',
			typedValues: {},
			typedMissing: [],
			generatorOpen: false,
			card: { number: '', expiry: '', cvv: '', pin: '', cardholder: '' },
			identity: {
				firstName: '',
				lastName: '',
				address: '',
				phone: '',
				email: '',
				bsn: '',
			},
		}
	},

	computed: {
		/**
		 * The label for the secret-value field, which reads "Note" for the
		 * `note` system type and "Secret value" otherwise.
		 *
		 * @return {string}
		 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
		 */
		valueLabel() {
			const type = useSecretTypeStore().typesById[this.typeId]
			return type && type.name === 'note'
				? t('keepiq', 'Note')
				: t('keepiq', 'Secret value')
		},

		/**
		 * The fields an administrator defined on the chosen type; empty for
		 * the built-in types, which keep their own forms.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		typedFields() {
			return typedFieldsOf(useSecretTypeStore().typesById[this.typeId])
		},

		/**
		 * The selected type's system name (card-identity-items §3.1).
		 *
		 * @spec exclude Trivial lookup: resolves the selected type id to its name.
		 */
		selectedTypeName() {
			return useSecretTypeStore().typesById[this.typeId]?.name ?? ''
		},

		isCard() {
			return this.selectedTypeName === CARD_TYPE_NAME
		},

		isIdentity() {
			return this.selectedTypeName === IDENTITY_TYPE_NAME
		},

		/**
		 * The value serialized for the encrypted key field.
		 *
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-composite-payload-stored-as-ciphertext-in-the-key-field
		 */
		effectiveValue() {
			if (this.isCard) {
				return serializeCard(this.card)
			}
			if (this.isIdentity) {
				return serializeIdentity(this.identity)
			}
			return this.value
		},
	},

	methods: {
		/**
		 * Take the typed values and clear the marks of fields now filled.
		 *
		 * @param {object} values The values by field key.
		 * @return {void}
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		onTypedValues(values) {
			this.typedValues = values
			this.typedMissing = this.typedMissing.filter((key) =>
				missingRequired(this.typedFields, values).includes(key),
			)
		},

		/**
		 * Forward the open-state change; emit `close` when dismissed.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 *
		 * @spec exclude Event re-emitter: syncs the open flag and emits close to the parent.
		 */
		onUpdateOpen(value) {
			this.open = value
			if (!value) {
				this.$emit('close')
			}
		},

		/**
		 * Open the key generator dialog.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/key-generator/spec.md#requirement-frontend-integration
		 */
		openGenerator() {
			this.generatorOpen = true
		},

		/**
		 * Receive the generated key from the modal and copy it into the value field.
		 *
		 * @param {string} key The generated key.
		 * @return {void}
		 *
		 * @spec openspec/specs/key-generator/spec.md#requirement-frontend-integration
		 */
		onGenerated(key) {
			if (typeof key === 'string' && key.length > 0) {
				this.value = key
			}
		},
	},
}
