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
import {
	loginLabelOf,
	typeFieldsOf,
	valueFieldOf,
} from '../utils/builtinTypeFields.js'
import { missingRequired } from '../utils/typedFields.js'

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
		 * The label for the secret-value field: the type's own, such as
		 * "Note" or "Certificate", else "Secret value".
		 *
		 * @return {string}
		 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
		 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
		 */
		valueLabel() {
			return (
				valueFieldOf(this.selectedTypeName)?.label
				?? t('keepiq', 'Secret value')
			)
		},

		/**
		 * Whether the value is entered in a text area, as a certificate or a
		 * note is: a password input would drop the line breaks of a PEM block.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
		 */
		valueMultiline() {
			return valueFieldOf(this.selectedTypeName)?.kind === 'multiline'
		},

		/**
		 * The login field's label: "Username" on a database, else "Login".
		 *
		 * @return {string}
		 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
		 */
		loginLabel() {
			return (
				loginLabelOf(this.selectedTypeName, true)
				?? t('keepiq', 'Login (optional)')
			)
		},

		/**
		 * The extra fields of the chosen type: a built-in type's own, or the
		 * fields an administrator defined on it.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
		 */
		typedFields() {
			return typeFieldsOf(useSecretTypeStore().typesById[this.typeId])
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
		 * The props of the SecretPayloadFields in the dialog. A host whose
		 * `valueRequired` is true marks the value as required.
		 *
		 * @return {object}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-composite-payload-stored-as-ciphertext-in-the-key-field
		 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-the-create-dialog-marks-the-value-as-required
		 */
		payloadFields() {
			const required = this.valueRequired === true
			return {
				value: this.value,
				card: this.card,
				identity: this.identity,
				generatorOpen: this.generatorOpen,
				isCard: this.isCard,
				isIdentity: this.isIdentity,
				valueLabel: required
					? t('keepiq', '{label} (required)', { label: this.valueLabel })
					: this.valueLabel,
				valueRequired: required,
				valueMultiline: this.valueMultiline,
			}
		},

		/**
		 * The listeners of the SecretPayloadFields in the dialog.
		 *
		 * @return {object}
		 * @spec openspec/specs/key-generator/spec.md#requirement-frontend-integration
		 */
		payloadFieldEvents() {
			return {
				'update:value': (value) => {
					this.value = value
				},
				'update:card': (card) => {
					this.card = card
				},
				'update:identity': (identity) => {
					this.identity = identity
				},
				'update:generatorOpen': (open) => {
					this.generatorOpen = open
				},
				generate: this.openGenerator,
				generated: this.onGenerated,
			}
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
