<template>
	<NcDialog :open="open" :name="title" size="normal" @update:open="onClose">
		<!--
		  ONE COLUMN of labelled controls, in the order the requester thinks:
		  what the credential is, where it is filed, which fields the recipient
		  must fill in, and how long they have.

		  The name and folder controls used to be four bare inline elements in a
		  div with no layout at all, so both labels and both controls ran onto a
		  single line — "What are you asking for? [e.g. Supplier API key] Folder
		  (optional) [No folder]" read as one sentence rather than two fields.
		-->
		<div class="secret-request-create-dialog">
			<NcNoteCard
				v-if="error"
				type="error"
				class="secret-request-create-dialog__error">
				{{ error }}
			</NcNoteCard>

			<!--
			  What this dialog does, stated once at the top. The placeholder
			  explanation used to be the LAST child of the name/folder block,
			  which left it stranded between two form sections, reading as a
			  footnote to the folder picker rather than as the premise of the
			  whole dialog.
			-->
			<NcNoteCard v-if="isReRequest" type="warning">
				{{
					t(
						'keepiq',
						'Existing values will be overwritten when the recipient fills in this re-request.',
					)
				}}
			</NcNoteCard>
			<NcNoteCard v-else-if="isFreshRequest" type="info">
				{{
					t(
						'keepiq',
						'A placeholder is created and stays empty until the recipient fills it in — you never have to invent a value.',
					)
				}}
			</NcNoteCard>

			<template v-if="isFreshRequest">
				<NcTextField
					v-model="newName"
					:label="t('keepiq', 'What are you asking for?')"
					:placeholder="t('keepiq', 'e.g. Supplier API key')"
					:required="true"
					data-testid="secret-request-name" />

				<!--
				  The same type list the new-secret dialog offers, so the
				  placeholder is created as what is being asked for and the
				  requested fields below follow from it.
				-->
				<NcSelect
					v-model="typeId"
					:options="typeOptions"
					:inputLabel="t('keepiq', 'Type')"
					:reduce="(opt) => opt.value"
					:clearable="false"
					data-testid="secret-request-type" />

				<!--
				  Deliberately NOT DestinationSelect: that picker exists for the
				  move flows, where a destination is mandatory (it is
				  unclearable and offers no "no folder" entry) and vaults are
				  candidates too. Filing a request's placeholder is optional —
				  the endpoint takes `folderId` as nullable.

				  "No folder" is the PLACEHOLDER rather than an option, because
				  NcSelect drops an empty-string model value (`selectedValue`
				  filters '' out) — an option valued '' would render as though
				  nothing were selected, hiding the choice the user is on. It is
				  clearable for the same reason: that is how the unfiled state is
				  chosen again, and the list still shows what it produces, since
				  a null `folderId` is the whole-vault query.

				  "Folder" covers every level on purpose (team decision): the
				  first level under the root is a vault and everything below it
				  is a folder, so folder is the word for the picker as a whole.
				-->
				<NcSelect
					:modelValue="newFolderId"
					:options="folderSelectOptions"
					:inputLabel="t('keepiq', 'Folder (optional)')"
					:placeholder="t('keepiq', 'No folder')"
					:reduce="(opt) => opt.value"
					data-testid="secret-request-folder"
					@update:modelValue="newFolderId = $event ?? ''" />
			</template>

			<fieldset class="secret-request-create-dialog__fields">
				<legend class="secret-request-create-dialog__legend">
					{{ t('keepiq', 'Requested fields') }}
				</legend>

				<!--
				  NcCheckboxRadioSwitch, not a bare `<input type="checkbox">`:
				  the server's input styling applies to checkboxes as well, so
				  each hand-rolled row came out one clickable-area tall WITH
				  padding and margins on top of it — several times the height it
				  needed — and the label, being a separate inline box, sat on
				  the text baseline instead of level with the box.
				-->
				<div
					v-for="field in availableFields"
					:key="field.key"
					class="secret-request-create-dialog__field">
					<NcCheckboxRadioSwitch
						class="secret-request-create-dialog__field-check"
						:modelValue="requestedFields"
						:value="field.key"
						:disabled="field.locked"
						name="requested-fields"
						@update:modelValue="requestedFields = $event">
						{{ field.label }}
						<span
							v-if="field.custom"
							class="secret-request-create-dialog__kind"
							:data-testid="`secret-request-kind-${field.key}`">
							{{ kindLabel(customSchemas[field.key]) }}
						</span>
						<span
							v-if="field.filled && !isReRequest"
							class="secret-request-create-dialog__filled">
							{{ t('keepiq', '— already has a value') }}
						</span>
					</NcCheckboxRadioSwitch>

					<!--
					  Only a name typed in THIS dialog can be removed here.
					  The built-ins and the secret's own members are not the
					  dialog's to delete — unticking them is how you decline
					  to ask for them.
					-->
					<NcButton
						v-if="field.custom"
						variant="tertiary"
						:aria-label="t('keepiq', 'Remove this field')"
						:title="t('keepiq', 'Remove this field')"
						:data-testid="`secret-request-remove-${field.key}`"
						@click="removeCustomField(field.key)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</div>

				<!--
				  Why "Create request" is unavailable, said where the missing
				  input is rather than in a message the button cannot show.
				-->
				<p
					v-if="requestedFields.length === 0"
					class="secret-request-create-dialog__hint"
					data-testid="secret-request-no-fields">
					{{ t('keepiq', 'Pick at least one field') }}
				</p>

				<div class="secret-request-create-dialog__custom">
					<NcTextField
						v-model="customFieldInput"
						class="secret-request-create-dialog__custom-input"
						:label="t('keepiq', 'Also ask for another field')"
						:placeholder="t('keepiq', 'e.g. client-id')"
						:error="customFieldError !== ''"
						:helperText="customFieldError"
						data-testid="secret-request-custom-field"
						@keyup.enter="addCustomField" />
					<!--
					  What the recipient must enter, so the fill page can show and
					  check it. The schema of a field the secret already has comes
					  from the secret itself.
					-->
					<NcSelect
						:modelValue="presetOf(customFieldSchema)"
						class="secret-request-create-dialog__custom-kind"
						:options="presetSelectOptions"
						:inputLabel="t('keepiq', 'Kind')"
						:reduce="(opt) => opt.value"
						:clearable="false"
						data-testid="secret-request-custom-kind"
						@update:modelValue="onCustomPreset" />
					<NcButton
						class="secret-request-create-dialog__custom-add"
						variant="secondary"
						@click="addCustomField">
						{{ t('keepiq', 'Add') }}
					</NcButton>
				</div>
				<!-- The limits a preset does not set: a pattern for a PIN, a
				     maximum length, a number range, or Hidden on any type. -->
				<NcButton
					class="secret-request-create-dialog__advanced-toggle"
					variant="tertiary"
					:aria-expanded="customAdvancedOpen ? 'true' : 'false'"
					data-testid="secret-request-custom-advanced"
					@click="customAdvancedOpen = !customAdvancedOpen">
					{{ t('keepiq', 'Advanced') }}
				</NcButton>
				<SchemaAdvanced
					v-if="customAdvancedOpen"
					:schema="customFieldSchema"
					testid="secret-request-custom-schema"
					@update:schema="customFieldSchema = $event" />
			</fieldset>

			<div class="secret-request-create-dialog__row">
				<label for="expiry-input">{{
					t('keepiq', 'Expires at (optional)')
				}}</label>
				<input id="expiry-input" v-model="expiresAt" type="datetime-local" />
			</div>

			<div v-if="fillUrl" class="secret-request-create-dialog__row">
				<label for="secret-request-fill-url">{{
					t('keepiq', 'Share this link with the recipient')
				}}</label>
				<div class="secret-request-create-dialog__url-row">
					<input
						id="secret-request-fill-url"
						type="text"
						readonly
						:value="fillUrl" />
					<NcButton variant="secondary" @click="copyUrl">
						{{ copied ? t('keepiq', 'Copied!') : t('keepiq', 'Copy') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- A name typed under "Also ask for another field" but never added
		     would silently be left out of the request: ask first. -->
		<NcDialog
			:open="unaddedOpen"
			:name="t('keepiq', 'Field not added')"
			size="small"
			data-testid="secret-request-unadded"
			@update:open="unaddedOpen = $event">
			<p>
				{{
					t(
						'keepiq',
						'“{name}” has not been added yet, so the recipient will not be asked for it.',
						{ name: customFieldInput.trim() },
					)
				}}
			</p>
			<template #actions>
				<NcButton
					variant="tertiary"
					data-testid="secret-request-unadded-back"
					@click="unaddedOpen = false">
					{{ t('keepiq', 'Back') }}
				</NcButton>
				<NcButton
					variant="secondary"
					data-testid="secret-request-unadded-skip"
					@click="createWithout">
					{{ t('keepiq', 'Create without it') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="secret-request-unadded-add"
					@click="addAndCreate">
					{{ t('keepiq', 'Add and create') }}
				</NcButton>
			</template>
		</NcDialog>

		<template #actions>
			<NcButton variant="tertiary" @click="onClose">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!fillUrl"
				variant="primary"
				:disabled="!canSubmit"
				data-testid="secret-request-create"
				@click="onCreate">
				{{
					submitting
						? t('keepiq', 'Creating…')
						: t('keepiq', 'Create request')
				}}
			</NcButton>
			<NcButton v-else variant="primary" @click="onClose">
				{{ t('keepiq', 'Done') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import SchemaAdvanced from '../components/SchemaAdvanced.vue'
import { CARD_TYPE_NAME, IDENTITY_TYPE_NAME } from '../cardIdentity/cardIdentity.js'
import { PASSKEY_TYPE_NAME } from '../passkey/passkey.js'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretRequestStore } from '../store/modules/secretRequest.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import {
	resolveDefaultTypeId,
	useUserPreferencesStore,
} from '../store/modules/userPreferences.js'
import { memberNameError } from '../utils/additionalFields.js'
import {
	leavesOut,
	loginLabelOf,
	memberLabel,
	typeFieldsOf,
	valueFieldOf,
	valueLockedInRequests,
} from '../utils/builtinTypeFields.js'
import {
	cleanSchema,
	fieldsOf,
	isPlainSchema,
	presetOf,
	presetOptions,
	presetSchema,
	schemasOf,
	typeFieldSchema,
} from '../utils/fieldSchema.js'
import { fillLinkFor } from '../utils/fillLink.js'
import { isCreatableType, secretTypeLabel } from '../utils/secretTypes.js'

/**
 * Types a request is not offered as.
 *
 * Asking a stranger for their payment card or identity details through a fill
 * link is what a scam looks like, and there is no legitimate use the request
 * flow needs to serve (backend review, 2026-11-04). Their fields also live
 * packed together in the one encrypted `key`, which a fill cannot address part
 * of. A passkey holds its private key, which signs in as its owner, so the
 * server refuses it as well.
 *
 * @type {Array<string>}
 */
const NOT_REQUESTABLE_TYPE_NAMES = [
	CARD_TYPE_NAME,
	IDENTITY_TYPE_NAME,
	PASSKEY_TYPE_NAME,
]

/**
 * Whether a request may be asked for as this type.
 *
 * @param {object|null|undefined} type The type record ({ name }).
 *
 * @return {boolean} True when the type can be requested.
 *
 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
 */
function isRequestableType(type) {
	return isCreatableType(type) && !NOT_REQUESTABLE_TYPE_NAMES.includes(type.name)
}

/**
 * Days ahead the expiry field is pre-filled with.
 *
 * Long enough for a colleague or vendor to act across a weekend or a holiday,
 * short enough that an abandoned request stops being a live credential within a
 * fortnight. Confirmed with the product owner on 2026-08-19, against 7 and 30:
 * a week lapses on a recipient who was simply away for it, a month leaves a live
 * fill-link sitting in someone's inbox far longer than the task needs.
 *
 * A pre-fill is what makes expiry meaningful at all: `expires_at` has exactly one
 * source — this field — so while it defaulted to empty almost nothing expired and
 * the sweeper had nothing to act on.
 *
 * @type {number}
 */
const SUGGESTED_EXPIRY_DAYS = 14

/**
 * The pre-filled expiry, formatted for `<input type="datetime-local">`.
 *
 * Built from LOCAL date parts, not `toISOString()`: that returns UTC, so on any
 * instance east or west of Greenwich the field would display a time the user did
 * not choose — and `datetime-local` has no timezone to correct it with. The value
 * is converted to UTC on submit, where a timezone is carried explicitly.
 *
 * @return {string} `YYYY-MM-DDTHH:mm` in local time.
 */
function suggestedExpiry() {
	const when = new Date()
	when.setDate(when.getDate() + SUGGESTED_EXPIRY_DAYS)

	const pad = (n) => String(n).padStart(2, '0')

	return (
		`${when.getFullYear()}-${pad(when.getMonth() + 1)}-${pad(when.getDate())}`
		+ `T${pad(when.getHours())}:${pad(when.getMinutes())}`
	)
}

export default {
	name: 'SecretRequestCreateDialog',
	components: {
		Delete,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
		SchemaAdvanced,
	},

	props: {
		open: { type: Boolean, default: false },
		// Optional: a FRESH request has no Secret to point at — the system
		// creates the placeholder. Only a re-request needs an existing one.
		secret: { type: Object, default: null },
		isReRequest: { type: Boolean, default: false },
		/**
		 * The vault or folder being browsed, pre-selected as the destination.
		 *
		 * Asking for a credential from inside a vault says where it belongs, so
		 * the picker should not start empty and make the requester name it again.
		 * `null` (the root list, which shows every vault) leaves it unfiled,
		 * which the list still shows: a null `folderId` is the whole-vault query.
		 */
		folderId: { type: String, default: null },
	},

	emits: ['update:open', 'created'],

	data() {
		return {
			requestedFields: [],
			// Additional-field names typed here for fields the secret does not
			// carry yet. Kept separate from `requestedFields` so unticking a name
			// does not delete it from the list.
			customFields: [],
			// The schema of each name typed here, and the one being set up for
			// the next name: a preset, refined under Advanced.
			customSchemas: {},
			customFieldSchema: { type: 'string' },
			customAdvancedOpen: false,
			customFieldInput: '',
			customFieldError: '',
			// The "Field not added" confirmation on Create.
			unaddedOpen: false,
			newName: '',
			// The type a FRESH request's placeholder is created as, and the one
			// the dialog starts on (the user's default type, else Login).
			typeId: null,
			defaultTypeId: null,
			// Starts on the vault or folder the requester opened this from.
			newFolderId: this.folderId || '',
			expiresAt: suggestedExpiry(),
			fillUrl: '',
			error: '',
			submitting: false,
			copied: false,
		}
	},

	computed: {
		/**
		 * The dialog heading, naming which of the three things this is.
		 *
		 * Asking for a credential you do not have yet, asking someone to fill an
		 * existing empty Secret, and asking for replacement values are different
		 * acts with different consequences; one heading for all three is how the
		 * flow came to read as "create a secret, then request into it".
		 *
		 * @return {string} The translated heading.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
		 */
		title() {
			if (this.isReRequest) {
				return t('keepiq', 'Re-request secret values')
			}

			return this.isFreshRequest
				? t('keepiq', 'Ask someone for a credential')
				: t('keepiq', 'Request secret fill-in')
		},

		/**
		 * True when this dialog must create the Secret itself.
		 *
		 * @return {boolean} Whether no target Secret was supplied.
		 */
		isFreshRequest() {
			return !this.secret
		},

		/**
		 * Folders the requester can file a fresh request's Secret under.
		 *
		 * @return {Array<object>} The user's folders.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
		 */
		folderOptions() {
			return useFolderStore().folders || []
		},

		/**
		 * The folder picker's entries.
		 *
		 * Folders only — leaving the request unfiled is the picker's EMPTY state
		 * (see the placeholder in the template), not an entry in this list.
		 *
		 * @return {Array<{value: string, label: string}>} Select options.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
		 */
		folderSelectOptions() {
			return this.folderOptions.map((f) => ({
				value: f.id,
				label: f.name,
			}))
		},

		/**
		 * The types a fresh request can be asked as, as select options.
		 *
		 * @return {Array<{value: string, label: string}>} Select options.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
		 */
		typeOptions() {
			return useSecretTypeStore()
				.types.filter(isRequestableType)
				.map((type) => ({
					value: type.id,
					label: secretTypeLabel(type),
				}))
		},

		/**
		 * The type record picked for a fresh request.
		 *
		 * @return {object|null} The type, or null while none is picked.
		 *
		 * @spec exclude Trivial lookup: resolves the selected type id to its record.
		 */
		selectedType() {
			return useSecretTypeStore().typesById[this.typeId] || null
		},

		/**
		 * Whether the form holds enough to create a request.
		 *
		 * Both of these were previously only enforced past the button. An empty
		 * name was caught on submit but reported in a pane below the fold, and an
		 * empty field selection was not checked at all — the POST went out and
		 * the endpoint answered 400 "requestedFields cannot be empty", so a
		 * completely blank dialog looked submittable and failed at the server.
		 *
		 * @return {boolean} True when the request can be created.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
		 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
		 */
		canSubmit() {
			if (this.submitting) {
				return false
			}

			if (this.requestedFields.length === 0) {
				return false
			}

			return this.isFreshRequest === false || this.newName.trim() !== ''
		},

		/**
		 * Every field a secret can hold, so the dialog matches what the backend
		 * accepts (SecretRequestPolicy: `key`/`login` encrypted, `url` plaintext,
		 * anything else a member of the encrypted additionalFields blob).
		 *
		 * `url` used to be missing here even though the backend has always stored
		 * it, and additional fields could only be requested when the secret
		 * ALREADY carried that key — so a fresh or unfilled secret could not ask
		 * for a named extra at all, while the machine API accepted any name. The
		 * custom names below close that gap.
		 *
		 * `custom` marks the names typed in THIS dialog, which are the only ones it
		 * may offer to delete: the built-ins and the secret's own members exist
		 * whether or not this request asks for them.
		 *
		 * A fresh request follows the picked type: a Secure Note names its value
		 * "Note", and the fields an administrator defined on the type are listed by
		 * their label, which is the member name the new-secret dialog stores them
		 * under (mergeTypedValues), so a fill lands where the type's own form looks.
		 *
		 * @return {Array<{key: string, label: string, plaintext?: boolean, custom?: boolean}>} Field options.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
		 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
		 */
		availableFields() {
			const typeName = this.targetTypeName
			const fields = [
				{
					key: 'key',
					label:
						valueFieldOf(typeName)?.label
						?? t('keepiq', 'Key / password'),
				},
			]
			if (!leavesOut(typeName, 'login')) {
				fields.push({
					key: 'login',
					label: loginLabelOf(typeName) ?? t('keepiq', 'Login'),
				})
			}
			if (!leavesOut(typeName, 'url')) {
				// Flagged plaintext because it is genuinely different: `url` is
				// stored searchable, not encrypted, and the recipient deserves to
				// know that before typing something sensitive into it.
				fields.push({
					key: 'url',
					label: t('keepiq', 'URL (stored unencrypted)'),
					plaintext: true,
				})
			}

			const seen = new Set(fields.map((f) => f.key))
			// The type's own fields come first, stored under their fixed name.
			// An existing Secret adds the members it already holds: from the
			// explicit key list when the server sent one, else from the
			// decrypted blob's own keys. A fresh request has no Secret yet.
			const members = typeFieldsOf(this.targetType).map((f) => f.label)
			if (this.isFreshRequest === false) {
				members.push(
					...(this.secret?.additional_fields_keys
						|| Object.keys(fieldsOf(this.secret?.additionalFields))),
				)
			}
			for (const key of members) {
				if (seen.has(key) === false) {
					seen.add(key)
					fields.push({ key, label: memberLabel(typeName, key) })
				}
			}
			// Names typed in this dialog for fields the secret does not have yet.
			for (const key of this.customFields) {
				if (seen.has(key) === false) {
					seen.add(key)
					fields.push({ key, label: key, custom: true })
				}
			}

			// Filled-ness is decided HERE, by the requester's client, and never
			// travels to the fill recipient: telling an anonymous party which
			// fields already hold a value is vault metadata about a credential.
			// The server could not do it anyway — it never decrypts the
			// additionalFields blob (ADR-003), so only this side can see members.
			return fields.map((f) => ({
				...f,
				filled: this.isFieldFilled(f.key),
				locked: this.lockedFields.includes(f.key),
			}))
		},

		/**
		 * The type the request asks by: the picked type for a fresh request,
		 * else the Secret's own.
		 *
		 * @return {object|null} The type record.
		 *
		 * @spec exclude Trivial lookup: picks the fresh or the existing type record.
		 */
		targetType() {
			return this.isFreshRequest
				? this.selectedType
				: useSecretTypeStore().typesById[this.secret?.typeId] || null
		},

		/**
		 * @spec exclude Trivial lookup: the target type's system name.
		 */
		targetTypeName() {
			return this.targetType?.name ?? ''
		},

		/**
		 * The fields the request always asks for: the value of a type that
		 * requires one. A plain request on a Secret whose value is already
		 * there leaves it free, so the dialog never compels an overwrite.
		 *
		 * @return {Array<string>} The locked field names.
		 *
		 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-a-request-always-asks-for-the-required-fields-of-its-type
		 * @spec openspec/specs/secret-requests/spec.md#requirement-fresh-requests-do-not-re-ask-for-values-that-already-exist
		 */
		lockedFields() {
			if (!valueLockedInRequests(this.targetTypeName)) {
				return []
			}
			if (!this.isReRequest && this.isFieldFilled('key')) {
				return []
			}
			return ['key']
		},

		/**
		 * The preset options for a field typed here, plus Custom while the
		 * schema set up under Advanced matches none.
		 *
		 * @return {Array<{label: string, value: string}>} The options.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		presetSelectOptions() {
			const options = presetOptions().map((p) => ({
				label: p.label,
				value: p.id,
			}))
			if (presetOf(this.customFieldSchema) === 'custom') {
				options.push({ label: t('keepiq', 'Custom'), value: 'custom' })
			}
			return options
		},

		/**
		 * The schema of each requested custom field that is more than plain
		 * text: picked here for a name typed here, else the one the secret
		 * stores, else the one the type's own field states.
		 *
		 * @return {object} The schemas by field name.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		requestedSchemas() {
			const stored = this.isFreshRequest
				? {}
				: schemasOf(this.secret?.additionalFields)
			const typeFields = typeFieldsOf(this.targetType)
			const schemas = {}
			for (const name of this.requestedFields) {
				if (['key', 'login', 'url'].includes(name)) {
					continue
				}
				const typeField = typeFields.find((f) => f.label === name)
				const schema =
					this.customSchemas[name]
					?? stored[name]
					?? (typeField ? typeFieldSchema(typeField) : null)
				if (schema && !isPlainSchema(schema)) {
					schemas[name] = cleanSchema(schema)
				}
			}
			return schemas
		},
	},

	watch: {
		/**
		 * Start the field list over when the type changes.
		 *
		 * The previous type's fields are gone from the list, so a tick on one of
		 * them would ask for a field the dialog no longer shows. Names typed here
		 * stay ticked: they belong to the request, not to a type. The new type's
		 * locked fields are ticked.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
		 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-a-request-always-asks-for-the-required-fields-of-its-type
		 */
		typeId() {
			this.requestedFields = [
				...this.lockedFields,
				...this.requestedFields.filter(
					(name) =>
						this.customFields.includes(name)
						&& !this.lockedFields.includes(name),
				),
			]
		},
	},

	created() {
		this.requestedFields = this.defaultSelection()
	},

	/**
	 * Load the types a fresh request can be asked as, and start on the default.
	 *
	 * Only a fresh request has a type to pick; an existing Secret already has
	 * one. A failed load leaves the select empty and the request typeless, which
	 * the server files under its default type, so it is not shown as an error.
	 *
	 * @return {Promise<void>}
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
	 */
	async mounted() {
		if (this.isFreshRequest === false) {
			return
		}

		try {
			const typeStore = useSecretTypeStore()
			if (typeStore.types.length === 0) {
				await typeStore.fetchTypes()
			}
			const prefs = useUserPreferencesStore()
			await prefs.ensureLoaded()
			this.defaultTypeId = resolveDefaultTypeId(
				prefs.defaultSecretType,
				typeStore.types.filter(isRequestableType),
			)
			this.typeId = this.defaultTypeId
		} catch {
			// Typeless is a working state: the server applies its default type.
		}
	},

	methods: {
		presetOf,

		/**
		 * Set up the next field with the schema of the picked preset.
		 *
		 * @param {string} id The preset.
		 * @return {void}
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		onCustomPreset(id) {
			if (id !== 'custom') {
				this.customFieldSchema = presetSchema(id)
			}
		},

		/**
		 * The kind a field typed here is asked as: its preset's name, or Custom.
		 *
		 * @param {object|undefined} schema The field's schema.
		 * @return {string} The label.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		kindLabel(schema) {
			const id = presetOf(schema)
			return (
				presetOptions().find((p) => p.id === id)?.label
				?? t('keepiq', 'Custom')
			)
		},

		/**
		 * Create the request, or first ask about a name typed under "Also ask
		 * for another field" that was never added.
		 *
		 * @return {void}
		 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
		 */
		onCreate() {
			if ((this.customFieldInput || '').trim() !== '') {
				this.unaddedOpen = true
				return
			}
			this.submit()
		},

		/**
		 * Add the typed field, then create the request. A name the field
		 * rules refuse closes the question so its reason shows by the input.
		 *
		 * @return {void}
		 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
		 */
		addAndCreate() {
			this.unaddedOpen = false
			this.addCustomField()
			if (this.customFieldError === '') {
				this.submit()
			}
		},

		/**
		 * Drop the typed name and create the request without it.
		 *
		 * @return {void}
		 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
		 */
		createWithout() {
			this.unaddedOpen = false
			this.customFieldInput = ''
			this.submit()
		},

		/**
		 * Submit the create request to the store.
		 *
		 * @spec openspec/changes/implement-secret-requests/tasks.md#task-8.2
		 */
		async submit() {
			this.error = ''
			const problem = this.submitProblem()
			if (problem !== null) {
				this.error = problem
				return
			}

			this.submitting = true
			try {
				const request = await this.sendRequest()
				await this.prepareFields(request)
				if (request && request.token) {
					// The recipient has no Nextcloud account, so this must point at
					// the ANONYMOUS shell (`publicShell#page`) carrying the router's
					// hash route. The plain `/apps/keepiq/share/request/<token>`
					// form this used to build answers 401 for exactly the person it
					// is meant for — the link was unusable by any external
					// recipient, which is the whole purpose of a fill link.
					this.fillUrl = fillLinkFor(request.token)
				}

				this.$emit('created', request)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to create request')
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Why the form cannot be sent yet, or null when it can.
		 *
		 * The button is disabled while nothing is ticked, so the field check is
		 * the second line of defence rather than the first — but it is the one
		 * that keeps a request with no fields from reaching the endpoint, which
		 * rejects it with a message written for API callers.
		 *
		 * @return {string|null}
		 * @spec openspec/changes/implement-secret-requests/tasks.md#task-8.2
		 */
		submitProblem() {
			if (this.isFreshRequest && this.newName.trim() === '') {
				return t(
					'keepiq',
					'Give the credential a name so you can find it later.',
				)
			}
			if (this.requestedFields.length === 0) {
				return t('keepiq', 'Pick at least one field')
			}
			return null
		},

		/**
		 * Create the request, or the re-request, through the store.
		 *
		 * @return {Promise<object|null>} The created request.
		 * @spec openspec/changes/implement-secret-requests/tasks.md#task-8.2
		 */
		async sendRequest() {
			const store = useSecretRequestStore()
			const expires = this.expiresAt
				? new Date(this.expiresAt).toISOString()
				: null
			if (this.isReRequest) {
				return store.createReRequest(
					this.secret.id,
					this.secret.encryption_suite_id,
					this.requestedFields,
					expires,
					this.requestedSchemas,
				)
			}
			return store.createRequest(this.requestPayload(expires))
		},

		/**
		 * Write the requested custom fields into the secret, empty, with
		 * their schemas, so the schemas come from the owner and a fill only
		 * adds values. A type's own fields are left out: the type gives them
		 * their schema, and an empty one is not stored. Best effort: the
		 * request is made either way, and without the schemas the filled
		 * values arrive as text.
		 *
		 * @param {object|null} request The created request.
		 * @return {Promise<void>}
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		async prepareFields(request) {
			const id = request?.secretId || this.secret?.id
			const typeLabels = typeFieldsOf(this.targetType).map((f) => f.label)
			const names = this.requestedFields.filter(
				(name) =>
					!['key', 'login', 'url'].includes(name)
					&& !typeLabels.includes(name),
			)
			if (!id || names.length === 0) {
				return
			}
			const schemas = Object.fromEntries(
				Object.entries(this.requestedSchemas).filter(([name]) =>
					names.includes(name),
				),
			)
			try {
				await useSecretStore().prepareRequestedFields(id, names, schemas)
			} catch {
				// The request stands; its values then arrive without schemas.
			}
		},

		/**
		 * The create payload, in camelCase, because that is what the store
		 * forwards and what the Nextcloud router binds by parameter name. This
		 * used to send snake_case, which the store read as `undefined` across
		 * the board: the POST went out empty and the endpoint answered 400
		 * "requestedFields cannot be empty". A mocked store in the unit test
		 * hid it, so the plain create path never worked from the UI.
		 *
		 * @param {string|null} expires The expiry, ISO.
		 * @return {object}
		 * @spec openspec/changes/implement-secret-requests/tasks.md#task-8.2
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		requestPayload(expires) {
			const payload = {
				requestedFields: this.requestedFields,
				fieldSchemas: this.requestedSchemas,
				expiresAt: expires,
				isReRequest: this.isReRequest,
			}
			if (this.isFreshRequest) {
				// No secretId: the server creates the placeholder and derives
				// the suite from it, so the client never supplies either.
				payload.name = this.newName.trim()
				payload.folderId = this.newFolderId || null
				payload.typeId = this.typeId || null
			} else {
				payload.secretId = this.secret.id
				payload.encryptionSuiteId = this.secret.encryption_suite_id
			}
			return payload
		},

		/**
		 * Whether the target Secret already holds a value for this field.
		 *
		 * @param {string} key The field name.
		 *
		 * @return {boolean} True when a value is already present.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-fresh-requests-do-not-re-ask-for-values-that-already-exist
		 */
		isFieldFilled(key) {
			if (!this.secret) {
				return false
			}

			if (key === 'key' || key === 'login' || key === 'url') {
				return String(this.secret[key] || '') !== ''
			}

			const members = this.secret.additionalFields
			if (members && typeof members === 'object') {
				return String(members[key] || '') !== ''
			}

			// The blob is present but not decrypted in this context, so member
			// completeness is unknowable — treat as unfilled rather than guess.
			return false
		},

		/**
		 * The fields ticked when the dialog opens.
		 *
		 * A fresh or plain request MUST NOT pre-select a field that already holds
		 * a value: the recipient cannot decline one (every requested field must be
		 * submitted non-empty), so a filled field carried in compels an overwrite
		 * rather than merely inviting one. A re-request is exempt — replacing
		 * existing values is that flow's entire purpose.
		 *
		 * A fresh request ticks only the fields its type requires: the field list
		 * follows the picked type, and which of the others to ask for is the
		 * requester's choice.
		 *
		 * @return {Array<string>} Field names to tick initially.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-fresh-requests-do-not-re-ask-for-values-that-already-exist
		 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
		 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-a-request-always-asks-for-the-required-fields-of-its-type
		 */
		defaultSelection() {
			if (this.isReRequest) {
				return ['key']
			}

			if (this.isFreshRequest) {
				return [...this.lockedFields]
			}

			return this.isFieldFilled('key') ? [] : ['key']
		},

		/**
		 * Add a named additional field and tick it.
		 *
		 * Reserved names are refused rather than silently accepted: typing "key"
		 * here would look like a second field but the backend routes it to the
		 * ciphertext column, so the user would be requesting something other than
		 * what they typed.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		addCustomField() {
			const name = (this.customFieldInput || '').trim()
			this.customFieldError = ''

			if (name === '') {
				return
			}

			// The reserved-name and duplicate rules are SHARED with the secret
			// create/edit dialogs (src/utils/additionalFields.js). They were written
			// out here first; a second copy in those dialogs is how `url` ends up
			// accepted in one place and refused in another.
			const error = memberNameError(
				name,
				this.availableFields.map((f) => f.key),
			)
			if (error !== '') {
				this.customFieldError = error
				return
			}

			this.customFields.push(name)
			this.customSchemas = {
				...this.customSchemas,
				[name]: cleanSchema(this.customFieldSchema),
			}
			this.customFieldSchema = { type: 'string' }
			this.customAdvancedOpen = false
			if (this.requestedFields.includes(name) === false) {
				this.requestedFields.push(name)
			}
			this.customFieldInput = ''
		},

		/**
		 * Drop a named additional field that was added here.
		 *
		 * Unticking one only declines to ask for it; the name stayed in the list
		 * (deliberately, so a mis-tick is not a deletion), which left a typo with
		 * no way out of the dialog but cancelling it. Only `custom` names reach
		 * this — the built-ins and the secret's own members are not this dialog's
		 * to remove.
		 *
		 * @param {string} name The field name to drop.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
		 */
		removeCustomField(name) {
			this.customFields = this.customFields.filter((f) => f !== name)
			const kept = { ...this.customSchemas }
			delete kept[name]
			this.customSchemas = kept
			this.requestedFields = this.requestedFields.filter((f) => f !== name)
			this.customFieldError = ''
		},

		/**
		 * Copy the fill link to the clipboard.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec exclude Clipboard convenience. No requirement describes how the
		 *   link travels to the recipient, only that the requester is handed it.
		 *   Pinned by the dialog spec's copyUrl test instead.
		 */
		async copyUrl() {
			try {
				await navigator.clipboard.writeText(this.fillUrl)
				this.copied = true
				setTimeout(() => {
					this.copied = false
				}, 1500)
			} catch (e) {
				console.warn('Keepiq: clipboard write failed', e)
			}
		},

		/**
		 * Close the dialog, always leaving a clean form behind.
		 *
		 * The reset used to be conditional on `emitDone`, so cancelling kept
		 * everything — including `fillUrl`. Create a request, press Cancel, then
		 * reopen the dialog on a DIFFERENT secret and you were shown the previous
		 * secret's fill link, ready to copy and send to the wrong person. A
		 * dialog that reopens holding another secret's credential link is worse
		 * than one that forgets a few ticked checkboxes, so the reset is now
		 * unconditional.
		 *
		 * With the reset unconditional the old `emitDone` argument controlled
		 * nothing, so it is gone rather than left as a dead parameter implying a
		 * difference that does not exist. Parents already learn about a successful
		 * creation from the `created` event.
		 *
		 * @return {void}
		 *
		 * @spec exclude Dialog state lifecycle. No requirement describes when the
		 *   form resets; the specs cover what a request contains and who may fill
		 *   it, not the local widget state. The behaviour is pinned by
		 *   SecretRequestCreateDialog.spec.js instead.
		 */
		onClose() {
			this.$emit('update:open', false)

			this.requestedFields = this.defaultSelection()
			this.customFields = []
			this.customSchemas = {}
			this.customFieldSchema = { type: 'string' }
			this.customAdvancedOpen = false
			this.customFieldInput = ''
			this.customFieldError = ''
			this.unaddedOpen = false
			this.newName = ''
			this.typeId = this.defaultTypeId
			// Back to the browsed vault or folder, not to nothing: reopening
			// from the same place must offer the same destination again.
			this.newFolderId = this.folderId || ''
			this.expiresAt = suggestedExpiry()
			this.fillUrl = ''
			this.error = ''
		},
	},
}
</script>

<style scoped>
.secret-request-create-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

/*
 * The fields that are not @nextcloud/vue components — the datetime input and
 * the read-only fill link — carry their label ABOVE the control, so they read
 * as one field each in the same single column as the rest.
 */
.secret-request-create-dialog__row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.secret-request-create-dialog__row input {
	width: 100%;
}

/*
 * A bordered box, because this is a SECTION of the form and not just another
 * field: it holds the checkbox list and the add-a-field row together, and the
 * border is what says where the group starts and stops. The legend sits in the
 * top border (default fieldset behaviour), so the padding is tighter there.
 */
.secret-request-create-dialog__fields {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	margin: 0;
	padding: 4px 12px 12px;
}

.secret-request-create-dialog__legend {
	font-weight: bold;
	/* Keeps the border from running right up against the text either side. */
	padding-inline: 4px;
}

/* The checkbox takes the row and the delete button sits at its end. */
.secret-request-create-dialog__field {
	display: flex;
	align-items: center;
	gap: 4px;
}

.secret-request-create-dialog__field-check {
	flex: 1 1 auto;
	min-width: 0;
}

.secret-request-create-dialog__filled,
.secret-request-create-dialog__kind,
.secret-request-create-dialog__hint {
	color: var(--color-text-maxcontrast);
}

.secret-request-create-dialog__kind {
	margin-inline-start: 6px;
	font-size: 0.85em;
}

.secret-request-create-dialog__advanced-toggle {
	align-self: flex-start;
}

.secret-request-create-dialog__hint {
	font-size: 0.85em;
}

/* Top-aligned, not bottom: the input grows a helper line under itself when a
   name is refused, and a bottom-aligned button would ride down with it. */
.secret-request-create-dialog__custom {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	margin-top: 8px;
}

.secret-request-create-dialog__custom-input {
	/* `min-width: 0` so the input, not the button, absorbs the squeeze: a flex
	   item's automatic minimum size is its content, and without this the input
	   refused to shrink and the button was crushed to "A…" instead. */
	flex: 1 1 auto;
	min-width: 0;
}

.secret-request-create-dialog__custom-add {
	flex: 0 0 auto;
	/*
	 * NcInputField gives its own root `margin-block-start: 6px` (room for the
	 * floating label), so a top-aligned row left the input sitting 6px below
	 * the button. The button takes the same offset, which lines the two
	 * control boxes up exactly — and unlike bottom-aligning them, it keeps the
	 * button still when a refused name grows the helper line underneath.
	 */
	margin-block-start: 6px;
}

.secret-request-create-dialog__url-row {
	display: flex;
	gap: 8px;
}

.secret-request-create-dialog__url-row input {
	flex: 1;
}
</style>
