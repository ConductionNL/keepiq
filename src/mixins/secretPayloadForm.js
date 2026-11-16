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
import {
	isPublicKey,
	parsePrivateKey,
	samePublicKey,
	SSH_FINGERPRINT_MEMBER,
	SSH_KEY_TYPE_NAME,
	SSH_PUBLIC_KEY_MEMBER,
	withDerivedFingerprint,
} from '../ssh/sshKey.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { missingRequired, typedFieldsOf } from '../utils/typedFields.js'

/**
 * The form state the create and edit secret dialogs share: the value, the
 * card, identity and SSH key fields, the typed fields of the chosen type and
 * the key generator. The host provides `typeId`, `saving` and an `open` flag.
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
			/** An SSH key's public half and fingerprint; its private key is `value`. */
			sshKey: { publicKey: '', fingerprint: '' },
			/** Why a save refused the SSH key fields, by field. */
			sshErrors: { privateKey: '', publicKey: '' },
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
		 * Whether the chosen type is an SSH key.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
		 */
		isSshKey() {
			return this.selectedTypeName === SSH_KEY_TYPE_NAME
		},

		/**
		 * The props of the SecretPayloadFields in the dialog.
		 *
		 * @return {object}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-composite-payload-stored-as-ciphertext-in-the-key-field
		 */
		payloadFields() {
			return {
				value: this.value,
				card: this.card,
				identity: this.identity,
				generatorOpen: this.generatorOpen,
				isCard: this.isCard,
				isIdentity: this.isIdentity,
				isSshKey: this.isSshKey,
				sshKey: this.sshKey,
				sshErrors: this.sshErrors,
				disabled: Boolean(this.saving),
				valueLabel: this.valueLabel,
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
					this.sshErrors = { ...this.sshErrors, privateKey: '' }
				},
				'update:sshKey': (sshKey) => {
					this.sshKey = sshKey
					this.sshErrors = { ...this.sshErrors, publicKey: '' }
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
		 * Check an SSH key before it is saved and work out what to store
		 * next to the private key. The private key must be a key; its
		 * public key is derived where the format allows, and a public key
		 * typed alongside must be that key. The fingerprint is always
		 * derived from the public key, never taken from the form.
		 *
		 * On a refusal the reason is set on the field and null is returned,
		 * so the caller saves nothing.
		 *
		 * @return {Promise<object|null>} The members to put in the blob, or null.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
		 */
		async sshKeyToSave() {
			this.sshErrors = { privateKey: '', publicKey: '' }
			let derived
			try {
				derived = (await parsePrivateKey(this.value)).publicKey
			} catch {
				this.sshErrors = {
					...this.sshErrors,
					privateKey: t(
						'keepiq',
						'This is not a private key. Paste an OpenSSH or PEM private key, or generate one.',
					),
				}
				return null
			}
			const typed = (this.sshKey.publicKey || '').trim()
			if (typed !== '' && !isPublicKey(typed)) {
				this.sshErrors = {
					...this.sshErrors,
					publicKey: t('keepiq', 'This is not an OpenSSH public key.'),
				}
				return null
			}
			if (derived !== null && typed !== '' && !samePublicKey(typed, derived)) {
				this.sshErrors = {
					...this.sshErrors,
					publicKey: t(
						'keepiq',
						'This public key does not belong to the private key.',
					),
				}
				return null
			}
			// A typed key keeps its comment; otherwise the derived one is used.
			const publicKey = typed !== '' ? typed : (derived ?? '')
			if (publicKey === '') {
				return {}
			}
			return withDerivedFingerprint(SSH_KEY_TYPE_NAME, {
				[SSH_PUBLIC_KEY_MEMBER]: publicKey,
			})
		},

		/**
		 * Take the SSH key's public key and fingerprint out of a decrypted
		 * blob into the form, returning the members left for the editor.
		 *
		 * @param {object} rest The free members of the blob.
		 * @return {object} The members without the SSH key's own.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
		 */
		takeSshMembers(rest) {
			if (!this.isSshKey || !rest || typeof rest !== 'object') {
				return rest
			}
			const {
				[SSH_PUBLIC_KEY_MEMBER]: publicKey,
				[SSH_FINGERPRINT_MEMBER]: fingerprint,
				...others
			} = rest
			this.sshKey = {
				publicKey: typeof publicKey === 'string' ? publicKey : '',
				fingerprint: typeof fingerprint === 'string' ? fingerprint : '',
			}
			return others
		},

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
