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
import { generateUrl } from '@nextcloud/router'
import { NcDialog } from '@nextcloud/vue'
import { useSecretRequestStore } from '../store/modules/secretRequest.js'

export default {
	name: 'SecretRequestCreateDialog',
	components: { NcDialog },

	props: {
		open: { type: Boolean, default: false },
		secret: { type: Object, required: true },
		isReRequest: { type: Boolean, default: false },
	},

	emits: ['update:open', 'created'],

	data() {
		return {
			requestedFields: ['key'],
			// Additional-field names typed here for fields the secret does not
			// carry yet. Kept separate from `requestedFields` so unticking a name
			// does not delete it from the list.
			customFields: [],
			customFieldInput: '',
			customFieldError: '',
			expiresAt: '',
			fillUrl: '',
			error: '',
			submitting: false,
			copied: false,
		}
	},

	computed: {
		title() {
			return this.isReRequest
				? t('doriath', 'Re-request secret values')
				: t('doriath', 'Request secret fill-in')
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
			for (const key of this.secret?.additional_fields_keys || []) {
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

			return fields
		},
	},

	methods: {
		/**
		 * Submit the create request to the store.
		 *
		 * @spec openspec/changes/implement-secret-requests/tasks.md#task-8.2
		 */
		async submit() {
			this.error = ''
			this.submitting = true
			try {
				const store = useSecretRequestStore()
				const expires = this.expiresAt
					? new Date(this.expiresAt).toISOString()
					: null

				const payload = {
					secret_id: this.secret.id,
					requested_fields: this.requestedFields,
					expires_at: expires,
					is_re_request: this.isReRequest,
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
					this.fillUrl =
						generateUrl('/apps/doriath/public', {}, { absolute: true })
						+ `#/share/request/${request.token}`
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
		 * Add a named additional field and tick it.
		 *
		 * Reserved names are refused rather than silently accepted: typing "key"
		 * here would look like a second field but the backend routes it to the
		 * ciphertext column, so the user would be requesting something other than
		 * what they typed.
		 *
		 * @return {void}
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
		 */
		onClose() {
			this.$emit('update:open', false)

			this.requestedFields = ['key']
			this.customFields = []
			this.customFieldInput = ''
			this.customFieldError = ''
			this.expiresAt = ''
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
