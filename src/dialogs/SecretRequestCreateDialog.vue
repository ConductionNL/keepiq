<template>
	<NcDialog :open="open" :name="title" size="normal" @update:open="onClose">
		<div class="secret-request-create-dialog">
			<p v-if="isReRequest" class="secret-request-create-dialog__note">
				{{
					t(
						'doriath',
						'Existing values will be overwritten when the recipient fills in this re-request.',
					)
				}}
			</p>

			<div v-if="isFreshRequest" class="secret-request-create-dialog__new">
				<label for="new-secret-name">{{
					t('doriath', 'What are you asking for?')
				}}</label>
				<input
					id="new-secret-name"
					v-model="newName"
					type="text"
					:placeholder="t('doriath', 'e.g. Supplier API key')" />
				<label for="new-secret-folder">{{
					t('doriath', 'Folder (optional)')
				}}</label>
				<select id="new-secret-folder" v-model="newFolderId">
					<option value="">
						{{ t('doriath', 'No folder') }}
					</option>
					<option v-for="f in folderOptions" :key="f.id" :value="f.id">
						{{ f.name }}
					</option>
				</select>
				<p class="secret-request-create-dialog__hint">
					{{
						t(
							'doriath',
							'A placeholder is created now and stays empty until the recipient fills it in — you never have to invent a value.',
						)
					}}
				</p>
			</div>

			<fieldset class="secret-request-create-dialog__fields">
				<legend>{{ t('doriath', 'Requested fields') }}</legend>
				<label
					v-for="field in availableFields"
					:key="field.key"
					class="secret-request-create-dialog__field">
					<input
						v-model="requestedFields"
						type="checkbox"
						:value="field.key" />
					{{ field.label }}
					<span
						v-if="field.filled && !isReRequest"
						class="secret-request-create-dialog__filled">
						{{ t('doriath', '— already has a value') }}
					</span>
				</label>

				<div class="secret-request-create-dialog__custom">
					<label for="custom-field-input">{{
						t('doriath', 'Also ask for another field')
					}}</label>
					<div class="secret-request-create-dialog__custom-row">
						<input
							id="custom-field-input"
							v-model="customFieldInput"
							type="text"
							:placeholder="t('doriath', 'e.g. client-id')"
							@keyup.enter="addCustomField" />
						<button type="button" @click="addCustomField">
							{{ t('doriath', 'Add') }}
						</button>
					</div>
					<p
						v-if="customFieldError"
						class="secret-request-create-dialog__custom-error">
						{{ customFieldError }}
					</p>
				</div>
			</fieldset>

			<div class="secret-request-create-dialog__expiry">
				<label for="expiry-input">{{
					t('doriath', 'Expires at (optional)')
				}}</label>
				<input id="expiry-input" v-model="expiresAt" type="datetime-local" />
			</div>

			<div v-if="fillUrl" class="secret-request-create-dialog__url">
				<label for="secret-request-fill-url">{{
					t('doriath', 'Share this link with the recipient')
				}}</label>
				<div class="secret-request-create-dialog__url-row">
					<input
						id="secret-request-fill-url"
						type="text"
						readonly
						:value="fillUrl" />
					<button @click="copyUrl">
						{{ copied ? t('doriath', 'Copied!') : t('doriath', 'Copy') }}
					</button>
				</div>
			</div>

			<div v-if="error" class="secret-request-create-dialog__error">
				{{ error }}
			</div>
		</div>

		<template #actions>
			<button @click="onClose">
				{{ t('doriath', 'Cancel') }}
			</button>
			<button
				v-if="!fillUrl"
				class="primary"
				:disabled="submitting"
				@click="submit">
				{{
					submitting
						? t('doriath', 'Creating…')
						: t('doriath', 'Create request')
				}}
			</button>
			<button v-else class="primary" @click="onClose">
				{{ t('doriath', 'Done') }}
			</button>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog } from '@nextcloud/vue'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretRequestStore } from '../store/modules/secretRequest.js'
import { fillLinkFor } from '../utils/fillLink.js'

/**
 * Days ahead the expiry field is pre-filled with.
 *
 * Long enough for a colleague or vendor to act across a weekend or a holiday,
 * short enough that an abandoned request stops being a live credential within a
 * fortnight. Confirmed with the product owner rather than chosen here.
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
	components: { NcDialog },

	props: {
		open: { type: Boolean, default: false },
		// Optional: a FRESH request has no Secret to point at — the system
		// creates the placeholder. Only a re-request needs an existing one.
		secret: { type: Object, default: null },
		isReRequest: { type: Boolean, default: false },
	},

	emits: ['update:open', 'created'],

	data() {
		return {
			requestedFields: [],
			// Additional-field names typed here for fields the secret does not
			// carry yet. Kept separate from `requestedFields` so unticking a name
			// does not delete it from the list.
			customFields: [],
			customFieldInput: '',
			customFieldError: '',
			newName: '',
			newFolderId: '',
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
		 * @spec openspec/changes/request-first-secret-requests/specs/secret-requests/spec.md#requirement-create-secret-request
		 */
		title() {
			if (this.isReRequest) {
				return t('doriath', 'Re-request secret values')
			}

			return this.isFreshRequest
				? t('doriath', 'Ask someone for a credential')
				: t('doriath', 'Request secret fill-in')
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
		 * @spec openspec/changes/request-first-secret-requests/specs/secret-requests/spec.md#requirement-create-secret-request
		 */
		folderOptions() {
			return useFolderStore().folders || []
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
		 * @return {Array<{key: string, label: string, plaintext?: boolean}>} Field options.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
		 */
		availableFields() {
			const fields = [
				{ key: 'key', label: t('doriath', 'Key / password') },
				{ key: 'login', label: t('doriath', 'Login') },
				// Flagged plaintext because it is genuinely different: `url` is
				// stored searchable, not encrypted, and the recipient deserves to
				// know that before typing something sensitive into it.
				{
					key: 'url',
					label: t('doriath', 'URL (stored unencrypted)'),
					plaintext: true,
				},
			]

			const seen = new Set(fields.map((f) => f.key))
			// Member names come from the explicit key list when the server sent
			// one, otherwise from the decrypted blob's own keys.
			const members =
				this.secret?.additional_fields_keys
				|| Object.keys(this.secret?.additionalFields || {})
			for (const key of members) {
				if (seen.has(key) === false) {
					seen.add(key)
					fields.push({ key, label: key })
				}
			}
			// Names typed in this dialog for fields the secret does not have yet.
			for (const key of this.customFields) {
				if (seen.has(key) === false) {
					seen.add(key)
					fields.push({ key, label: key })
				}
			}

			// Filled-ness is decided HERE, by the requester's client, and never
			// travels to the fill recipient: telling an anonymous party which
			// fields already hold a value is vault metadata about a credential.
			// The server could not do it anyway — it never decrypts the
			// additionalFields blob (ADR-003), so only this side can see members.
			return fields.map((f) => ({ ...f, filled: this.isFieldFilled(f.key) }))
		},
	},

	created() {
		this.requestedFields = this.defaultSelection()
	},

	methods: {
		/**
		 * Submit the create request to the store.
		 *
		 * @spec openspec/changes/implement-secret-requests/tasks.md#task-8.2
		 */
		async submit() {
			this.error = ''

			if (this.isFreshRequest && this.newName.trim() === '') {
				this.error = t(
					'doriath',
					'Give the credential a name so you can find it later.',
				)
				return
			}

			this.submitting = true
			try {
				const store = useSecretRequestStore()
				const expires = this.expiresAt
					? new Date(this.expiresAt).toISOString()
					: null

				// camelCase, because that is what the store forwards and what the
				// Nextcloud router binds by parameter name. This used to send
				// snake_case, which the store read as `undefined` across the board:
				// the POST went out empty and the endpoint answered 400
				// "requestedFields cannot be empty". A mocked store in the unit
				// test hid it, so the plain create path never worked from the UI.
				const payload = {
					requestedFields: this.requestedFields,
					expiresAt: expires,
					isReRequest: this.isReRequest,
				}

				if (this.isFreshRequest) {
					// No secretId: the server creates the placeholder and derives
					// the suite from it, so the client never supplies either.
					payload.name = this.newName.trim()
					payload.folderId = this.newFolderId || null
				} else {
					payload.secretId = this.secret.id
					payload.encryptionSuiteId = this.secret.encryption_suite_id
				}

				const request = this.isReRequest
					? await store.createReRequest(
							this.secret.id,
							this.secret.encryption_suite_id,
							this.requestedFields,
							expires,
						)
					: await store.createRequest(payload)

				if (request && request.token) {
					// The recipient has no Nextcloud account, so this must point at
					// the ANONYMOUS shell (`publicShell#page`) carrying the router's
					// hash route. The plain `/apps/doriath/share/request/<token>`
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
					|| t('doriath', 'Failed to create request')
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Whether the target Secret already holds a value for this field.
		 *
		 * @param {string} key The field name.
		 *
		 * @return {boolean} True when a value is already present.
		 *
		 * @spec openspec/changes/request-first-secret-requests/specs/secret-requests/spec.md#requirement-fresh-requests-do-not-re-ask-for-values-that-already-exist
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
		 * @return {Array<string>} Field names to tick initially.
		 *
		 * @spec openspec/changes/request-first-secret-requests/specs/secret-requests/spec.md#requirement-fresh-requests-do-not-re-ask-for-values-that-already-exist
		 */
		defaultSelection() {
			if (this.isReRequest) {
				return ['key']
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
		 */
		addCustomField() {
			const name = (this.customFieldInput || '').trim()
			this.customFieldError = ''

			if (name === '') {
				return
			}

			if (['key', 'login', 'url'].includes(name) === true) {
				this.customFieldError = t(
					'doriath',
					'That is a built-in field — tick it in the list above instead.',
				)
				return
			}

			if (this.availableFields.some((f) => f.key === name) === true) {
				this.customFieldError = t('doriath', 'That field is already listed.')
				return
			}

			this.customFields.push(name)
			if (this.requestedFields.includes(name) === false) {
				this.requestedFields.push(name)
			}
			this.customFieldInput = ''
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
				console.warn('Doriath: clipboard write failed', e)
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
			this.customFieldInput = ''
			this.customFieldError = ''
			this.newName = ''
			this.newFolderId = ''
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
}

.secret-request-create-dialog__note {
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 8px 12px;
}

.secret-request-create-dialog__fields {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 8px;
}

.secret-request-create-dialog__field {
	display: block;
	margin: 4px 0;
}

.secret-request-create-dialog__url-row {
	display: flex;
	gap: 8px;
}

.secret-request-create-dialog__url-row input {
	flex: 1;
}

.secret-request-create-dialog__error {
	color: var(--color-error-text);
}
</style>
