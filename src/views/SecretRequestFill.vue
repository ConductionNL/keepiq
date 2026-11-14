<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Public secret-request fill-in page mounted at /share/request/:token.

  Phase 1 — fetch the request metadata via
  `useSecretRequestStore.fetchPublicRequest(token)`. If the request is
  expired / fulfilled / locked the page renders a status message and
  stops.

  Phase 2 — render an input per requested field. On submit the store
  encrypts each value client-side (RSA-OAEP-SHA256) and POSTs the
  blobs. Plaintext NEVER hits the network.

  @spec openspec/changes/implement-secret-requests/tasks.md#task-8.1
-->
<template>
	<div class="keepiq-secret-request-fill" data-testid="secret-request-fill">
		<header class="keepiq-secret-request-fill__header">
			<h1>{{ t('keepiq', 'Fill in secret') }}</h1>
		</header>

		<!--
		  Checked before anything else, because every path below encrypts in the
		  browser. Without WebCrypto the page previously died on
		  `crypto.subtle.importKey` with "Cannot read properties of undefined",
		  which tells an external recipient nothing and gives them no reason to
		  suspect the URL's scheme. They cannot fix the instance, but they CAN open
		  the https:// form of the same link — so say that.
		-->
		<div
			v-if="cryptoUnavailable"
			class="keepiq-secret-request-fill__error"
			data-testid="fill-insecure-context">
			<p>
				{{
					t(
						'keepiq',
						'This page needs a secure (https://) connection, because your value is encrypted in your browser before it is sent.',
					)
				}}
			</p>
			<p>
				{{
					t(
						'keepiq',
						'Open the same link with https:// at the start. If it still does not work, ask the person who sent it.',
					)
				}}
			</p>
		</div>

		<p
			v-else-if="store.loading && !store.publicRequest"
			class="keepiq-secret-request-fill__loading">
			{{ t('keepiq', 'Loading…') }}
		</p>

		<div
			v-else-if="loadError"
			class="keepiq-secret-request-fill__unavailable"
			data-testid="fill-load-error">
			<div class="keepiq-secret-request-fill__unavailable-icon">
				<LinkVariantOff :size="36" />
			</div>
			<p>{{ loadMessage }}</p>
		</div>

		<div
			v-else-if="store.publicRequest && unavailableMessage"
			class="keepiq-secret-request-fill__unavailable"
			data-testid="fill-unavailable">
			<div class="keepiq-secret-request-fill__unavailable-icon">
				<LinkVariantOff :size="36" />
			</div>
			<p>{{ unavailableMessage }}</p>
		</div>

		<form
			v-else-if="
				store.publicRequest && store.publicRequest.status === 'pending'
			"
			class="keepiq-secret-request-fill__form"
			data-testid="fill-form"
			@submit.prevent="onSubmit">
			<template v-if="!submitted">
				<!-- A JSON field is a group, not a label: a click in the editor
				     would otherwise press its Format button. -->
				<component
					:is="inputType(field) === 'json' ? 'div' : 'label'"
					v-for="(field, index) in store.publicRequest.requested_fields"
					:key="field"
					:role="inputType(field) === 'json' ? 'group' : undefined"
					:aria-labelledby="
						inputType(field) === 'json'
							? `fill-label-${index}`
							: undefined
					"
					:aria-describedby="
						inputType(field) === 'json'
							? errorId(field, index)
							: undefined
					"
					class="keepiq-secret-request-fill__field">
					<span :id="`fill-label-${index}`">{{ inputLabel(field) }}</span>
					<CnJsonViewer
						v-if="inputType(field) === 'json'"
						:value="values[field] ?? ''"
						language="json"
						height="200px"
						errorText=""
						:data-testid="`fill-field-${field}`"
						@update:value="values[field] = $event" />
					<!-- A yes/no field is a box to tick; unticked is "no". -->
					<input
						v-else-if="inputType(field) === 'checkbox'"
						type="checkbox"
						:checked="values[field] === 'true'"
						:data-testid="`fill-field-${field}`"
						@change="
							values[field] = $event.target.checked ? 'true' : 'false'
						" />
					<!-- A certificate or key block is several lines,
					     which an input would collapse into one. -->
					<textarea
						v-else-if="inputType(field) === 'multiline'"
						v-model="values[field]"
						rows="6"
						autocomplete="off"
						spellcheck="false"
						required
						:aria-invalid="fieldError(field) !== '' ? 'true' : 'false'"
						:aria-describedby="errorId(field, index)"
						:data-testid="`fill-field-${field}`" />
					<input
						v-else
						v-model="values[field]"
						:type="inputType(field)"
						autocomplete="off"
						required
						:aria-invalid="fieldError(field) !== '' ? 'true' : 'false'"
						:aria-describedby="errorId(field, index)"
						:data-testid="`fill-field-${field}`" />
					<span
						v-if="fieldError(field) !== ''"
						:id="`fill-error-${index}`"
						class="keepiq-secret-request-fill__field-error"
						:data-testid="`fill-error-${field}`">
						{{ fieldError(field) }}
					</span>
				</component>

				<p
					v-if="submitError"
					class="keepiq-secret-request-fill__submit-error"
					data-testid="fill-submit-error">
					{{ submitError }}
				</p>

				<NcButton
					variant="primary"
					wide
					type="submit"
					data-testid="fill-submit"
					:disabled="busy || !allFit">
					{{ busy ? t('keepiq', 'Encrypting…') : t('keepiq', 'Submit') }}
				</NcButton>
			</template>

			<!--
			  The confirmation replaces the inputs, so the recipient sees what was
			  sent instead of an editable form they can no longer submit. Masked
			  fields stay masked, and with a fixed-length mask, so the summary
			  never reveals a value or its length to whoever looks at the screen.
			-->
			<template v-else>
				<p
					class="keepiq-secret-request-fill__success"
					data-testid="fill-success">
					{{ t('keepiq', 'Thanks — the secret has been delivered.') }}
				</p>

				<table
					class="keepiq-secret-request-fill__summary"
					data-testid="fill-summary">
					<tbody>
						<tr
							v-for="field in store.publicRequest.requested_fields"
							:key="field">
							<th scope="row">
								{{ inputLabel(field) }}
							</th>
							<td :data-testid="`fill-summary-${field}`">
								<template v-if="masksSummary(field)">
									<span aria-hidden="true">••••••••</span>
									<span class="hidden-visually">{{
										t('keepiq', 'Hidden')
									}}</span>
								</template>
								<span
									v-else
									class="keepiq-secret-request-fill__summary-value"
									>{{ values[field] }}</span
								>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="keepiq-secret-request-fill__close-hint">
					{{ t('keepiq', 'You can close this window now.') }}
				</p>
			</template>
		</form>
	</div>
</template>

<script>
import { CnJsonViewer } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import LinkVariantOff from 'vue-material-design-icons/LinkVariantOff.vue'
import { useSecretRequestStore } from '../store/modules/secretRequest.js'
import {
	builtinFieldNamed,
	loginLabelOf,
	valueFieldOf,
} from '../utils/builtinTypeFields.js'
import {
	cleanSchema,
	inputOf,
	isHiddenSchema,
	schemaError,
} from '../utils/fieldSchema.js'

/** The HTML input type for each schema input that uses a plain input. */
const SCHEMA_INPUT_TYPES = Object.freeze({
	url: 'url',
	email: 'email',
	integer: 'number',
	number: 'number',
	date: 'date',
	datetime: 'datetime-local',
	time: 'time',
	boolean: 'checkbox',
	multiline: 'multiline',
	json: 'json',
})

export default {
	name: 'SecretRequestFill',
	components: {
		CnJsonViewer,
		NcButton,
		LinkVariantOff,
	},

	props: {
		token: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			values: {},
			busy: false,
			submitted: false,
			// Set by the first Submit: only then does an empty editor say "Required".
			triedSubmit: false,
			submitError: null,
			loadError: null,
			loadReason: null,
			store: useSecretRequestStore(),
		}
	},

	computed: {
		/**
		 * Whether the browser can encrypt at all.
		 *
		 * `crypto.subtle` exists only in a secure context, so plain http on any
		 * host other than localhost leaves it undefined. Measured: on
		 * http://nextcloud.local `isSecureContext` is false and `crypto.subtle` is
		 * undefined; over https both are fine.
		 *
		 * Both are checked rather than just one — a browser could in principle
		 * report a secure context without exposing SubtleCrypto, and the failure
		 * this prevents is a raw TypeError shown to a stranger.
		 *
		 * @return {boolean} True when the fill flow cannot possibly succeed.
		 *
		 * @spec exclude Environment precondition, not a spec'd requirement: the
		 *   specs describe what the fill flow does, and this is the case where the
		 *   browser cannot run it at all. Pinned by the view's own spec instead.
		 */
		cryptoUnavailable() {
			return (
				typeof window === 'undefined'
				|| window.isSecureContext === false
				|| typeof window.crypto?.subtle === 'undefined'
			)
		},

		/**
		 * What a recipient reads when the link cannot be filled.
		 *
		 * Prefers the server's machine-readable `reason` over its `message`, and
		 * that ordering is the whole point. The endpoint REFUSES a non-pending
		 * request, so the store rejects and `publicRequest` stays null — meaning
		 * `unavailableMessage` below never fires, and this branch is what the
		 * recipient actually sees. Measured on a real expired request before this
		 * existed: the page rendered "Request has expired", the PHP exception
		 * string, in English, to a recipient whose locale is one of 36.
		 *
		 * Falls back to the server message when the reason is `unknown` or absent
		 * (an older server, or a failure with no response at all) — an untranslated
		 * sentence beats a blank page.
		 *
		 * @return {string|null} The message to render, or null while none applies.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
		 */
		loadMessage() {
			return this.messageForReason(this.loadReason) || this.loadError
		},

		/**
		 * The message for a request the server returned WITHOUT refusing.
		 *
		 * Retained for the case where a non-pending request is served with 200 —
		 * which the current endpoint never does. Kept rather than deleted because
		 * it costs one computed and it is the branch that would carry a status the
		 * server decides to describe instead of refuse.
		 *
		 * @return {string|null} The message to render, or null when none applies.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
		 */
		unavailableMessage() {
			return this.messageForReason(this.store.publicRequest?.status)
		},

		/**
		 * Whether every requested value fits its field's schema.
		 *
		 * @return {boolean} False while one does not, which blocks Submit.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		allFit() {
			return (this.store.publicRequest?.requested_fields ?? []).every(
				(field) => this.fieldError(field) === '',
			)
		},
	},

	/**
	 * Resolve the token, and capture WHY if it cannot be filled.
	 *
	 * Both the reason and the message are kept. The reason is what the page can
	 * translate; the message is the fallback for a server that sent no reason, and
	 * for anything that failed before a response existed at all.
	 *
	 * @return {Promise<void>}
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	async mounted() {
		this.loadError = null
		this.loadReason = null
		try {
			await this.store.fetchPublicRequest(this.token)
		} catch (e) {
			this.loadReason = e?.response?.data?.reason || null
			this.loadError =
				e?.response?.data?.message
				|| e?.message
				|| t('keepiq', 'Request not found')
		}
	},

	methods: {
		/**
		 * Translate a refusal reason into a sentence for the recipient.
		 *
		 * The reason slugs come from SecretRequestPolicy::REASONS. They are matched
		 * here rather than in two places so the refused case and the described case
		 * can never word the same condition differently.
		 *
		 * `unknown` deliberately returns null: the server could not explain the
		 * refusal, so inventing a confident sentence would be worse than showing
		 * whatever it did say.
		 *
		 * @param {string|null|undefined} reason The server's reason slug.
		 *
		 * @return {string|null} The translated sentence, or null when there is none.
		 *
		 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
		 */
		messageForReason(reason) {
			const messages = {
				fulfilled: t('keepiq', 'This request has already been fulfilled.'),
				declined: t('keepiq', 'This request was declined.'),
				locked: t(
					'keepiq',
					'This request is temporarily unavailable while a compromise recovery is in progress.',
				),

				expired: t('keepiq', 'This request has expired.'),
				// The requester's key was revoked (#809 review).
				unavailable: t('keepiq', 'This request is no longer available.'),
				'not-found': t('keepiq', 'This request could not be found.'),
			}
			return Object.hasOwn(messages, reason)
				? messages[reason]
				: null
		},

		/**
		 * The label a recipient reads above a requested field.
		 *
		 * The three column names get the wording the requester picked them by in
		 * SecretRequestCreateDialog, by the secret's type where it has its own;
		 * a built-in type's extra field gets its translated label, and any other
		 * member is shown by the name the requester typed, the only label it has.
		 *
		 * @param {string} field The requested field name.
		 *
		 * @return {string} The label to render.
		 *
		 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-the-fill-page-shows-the-fields-of-the-requested-type
		 */
		inputLabel(field) {
			const typeName = this.store.publicRequest?.secret_type ?? ''
			switch (field) {
				case 'key':
					return (
						valueFieldOf(typeName)?.label
						?? t('keepiq', 'Key / password')
					)
				case 'login':
					return loginLabelOf(typeName) ?? t('keepiq', 'Login')
				case 'url':
					return t('keepiq', 'URL')
				default:
					return builtinFieldNamed(typeName, field)?.displayLabel ?? field
			}
		},

		/**
		 * The input for a requested field: `json` for the code editor,
		 * `multiline` for a text area, `password` for a masked input, else
		 * `text`. A schema that says what the field is decides; the secret's
		 * type decides for its own fields; any other name is masked when it
		 * looks secret.
		 *
		 * @param {string} field The requested field name.
		 *
		 * @return {string} The input kind.
		 *
		 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-the-fill-page-shows-the-fields-of-the-requested-type
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		inputType(field) {
			const fromSchema = this.schemaInputType(field)
			if (fromSchema !== null) {
				return fromSchema
			}
			const typeName = this.store.publicRequest?.secret_type ?? ''
			const kind =
				field === 'key'
					? valueFieldOf(typeName)?.kind
					: builtinFieldNamed(typeName, field)?.kind
			if (kind === 'multiline') {
				return 'multiline'
			}
			if (kind === 'hidden') {
				return 'password'
			}
			if (kind === 'text') {
				return 'text'
			}
			const name = String(field || '').toLowerCase()
			if (
				name.includes('password')
				|| name.includes('key')
				|| name.includes('secret')
			) {
				return 'password'
			}
			return 'text'
		},

		/**
		 * The input a field's schema asks for, or null when the schema does not
		 * say: a plain string, with limits at most, is not told apart from a
		 * secret one, so its name decides as it does without a schema.
		 *
		 * @param {string} field The requested field name.
		 * @return {string|null} The input kind.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		schemaInputType(field) {
			const schema = this.schemaOf(field)
			if (schema === null) {
				return null
			}
			const input = inputOf(schema)
			if (!isHiddenSchema(schema)) {
				return input === 'text'
					? null
					: (SCHEMA_INPUT_TYPES[input] ?? 'text')
			}
			// An object is edited as code, hidden or not, as in the dialogs.
			return ['multiline', 'json'].includes(input)
				? SCHEMA_INPUT_TYPES[input]
				: 'password'
		},

		/**
		 * The id of the error under a field while it shows one, so its input
		 * names the reason and not only that it is invalid.
		 *
		 * @param {string} field The requested field name.
		 * @param {number} index The field's place in the form.
		 * @return {string|undefined} The id.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		errorId(field, index) {
			return this.fieldError(field) !== '' ? `fill-error-${index}` : undefined
		},

		/**
		 * Whether the summary after submitting masks a field: every field but
		 * a plain-text one and a certificate, which is public by design. A
		 * multi-line private key is not masked while typed, but is here.
		 *
		 * @param {string} field The requested field name.
		 *
		 * @return {boolean} True when the value is shown as a fixed mask.
		 *
		 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-the-fill-page-shows-the-fields-of-the-requested-type
		 */
		masksSummary(field) {
			const schema = this.schemaOf(field)
			if (schema !== null && this.schemaInputType(field) !== null) {
				return isHiddenSchema(schema)
			}
			const typeName = this.store.publicRequest?.secret_type ?? ''
			if (field === 'key' && valueFieldOf(typeName)?.public === true) {
				return false
			}
			return this.inputType(field) !== 'text'
		},

		/**
		 * The schema the requester gave a field, or null when it has none.
		 *
		 * @param {string} field The requested field name.
		 * @return {object|null} The schema.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		schemaOf(field) {
			const schema = this.store.publicRequest?.field_schemas?.[field]
			return schema ? cleanSchema(schema) : null
		},

		/**
		 * Why a value does not fit its field's schema, or ''. The JSON editor has
		 * no `required` of its own, so after a Submit an empty one is reported.
		 *
		 * @param {string} field The requested field name.
		 * @return {string} The reason.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		fieldError(field) {
			if (
				this.triedSubmit
				&& this.inputType(field) === 'json'
				&& !this.values[field]
			) {
				return t('keepiq', 'Required')
			}
			const schema = this.schemaOf(field)
			return schema === null ? '' : schemaError(this.values[field], schema)
		},

		/**
		 * Encrypt the filled-in values under the requester's certificate in
		 * the browser and submit them against the request token.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
		 * @spec openspec/specs/secret-requests/spec.md#requirement-field-validation
		 * @spec openspec/specs/secret-requests/spec.md#requirement-write-once
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
		 */
		async onSubmit() {
			this.triedSubmit = true
			if (!this.allFit) {
				return
			}
			// An unticked yes/no box is a "no", not a missing value.
			for (const field of this.store.publicRequest?.requested_fields ?? []) {
				if (this.inputType(field) === 'checkbox' && !this.values[field]) {
					this.values[field] = 'false'
				}
			}
			this.submitError = null
			this.busy = true
			try {
				await this.store.submitFill(this.token, this.values)
				this.submitted = true
			} catch (e) {
				this.submitError =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to submit')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.keepiq-secret-request-fill {
	box-sizing: border-box;
	width: 100%;
	max-width: 480px;
	/* Centred by the public shell's flex wrapper (App.vue). */
	margin: auto;
	padding: 32px;
	border-radius: var(--border-radius-container, 16px);
	background-color: var(--color-main-background, #fff);
	box-shadow: 0 8px 40px rgba(0, 0, 0, 0.2);
}

.keepiq-secret-request-fill__header h1 {
	margin: 0 0 16px 0;
	font-size: 20px;
	font-weight: 600;
}

.keepiq-secret-request-fill__loading {
	margin: 0;
	color: var(--color-text-maxcontrast, #666);
}

.keepiq-secret-request-fill__error {
	padding: 12px 16px;
	border-inline-start: 4px solid var(--color-warning, #c28900);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-background-dark, #ededed);
	background: color-mix(in srgb, var(--color-warning, #c28900) 12%, transparent);
}

.keepiq-secret-request-fill__error p {
	margin: 0;
}

.keepiq-secret-request-fill__error p + p {
	margin-top: 8px;
}

.keepiq-secret-request-fill__unavailable {
	padding: 16px 0 8px;
	text-align: center;
}

.keepiq-secret-request-fill__unavailable-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 72px;
	height: 72px;
	margin: 0 auto 16px;
	border-radius: 50%;
	background: var(--color-background-dark, #ededed);
	background: color-mix(in srgb, var(--color-error, #d91f2d) 12%, transparent);
	color: var(--color-error, #d91f2d);
}

.keepiq-secret-request-fill__unavailable p {
	margin: 0;
	color: var(--color-text-maxcontrast, #666);
}

.keepiq-secret-request-fill__field {
	display: block;
	margin: 0 0 16px 0;
}

.keepiq-secret-request-fill__field span {
	display: block;
	margin-bottom: 4px;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #666);
	overflow-wrap: anywhere;
}

.keepiq-secret-request-fill__field input,
.keepiq-secret-request-fill__field textarea {
	box-sizing: border-box;
	display: block;
	width: 100%;
	padding: 10px 12px;
	border: 2px solid var(--color-border-maxcontrast, #949494);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
}

.keepiq-secret-request-fill__field input[type='checkbox'] {
	width: auto;
	padding: 0;
}

.keepiq-secret-request-fill__field .keepiq-secret-request-fill__field-error {
	margin: 4px 0 0;
	color: var(--color-error-text, #c9302c);
}

.keepiq-secret-request-fill__field textarea {
	resize: vertical;
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
}

.keepiq-secret-request-fill__field input:focus-visible,
.keepiq-secret-request-fill__field textarea:focus-visible {
	border-color: var(--color-main-elevated, var(--color-primary-element, #21468b));
	outline: none;
}

.keepiq-secret-request-fill__submit-error {
	margin: 0 0 12px 0;
	color: var(--color-error-text);
}

.keepiq-secret-request-fill__success {
	margin: 0 0 12px 0;
	padding: 12px 16px;
	border-inline-start: 4px solid var(--color-success, #2d7b41);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-background-dark, #ededed);
	background: color-mix(in srgb, var(--color-success, #2d7b41) 12%, transparent);
	color: var(--color-success-text, #2d7b41);
	font-weight: 600;
}

.keepiq-secret-request-fill__form > .button-vue {
	margin-top: 8px;
}

.keepiq-secret-request-fill__summary {
	width: 100%;
	margin: 0 0 16px 0;
	border-collapse: collapse;
}

.keepiq-secret-request-fill__summary th,
.keepiq-secret-request-fill__summary td {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border, #ededed);
	text-align: start;
	vertical-align: top;
}

/* A certificate keeps its lines, and its 64-character lines wrap. */
.keepiq-secret-request-fill__summary-value {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.keepiq-secret-request-fill__summary tr:last-child th,
.keepiq-secret-request-fill__summary tr:last-child td {
	border-bottom: none;
}

.keepiq-secret-request-fill__summary th {
	width: 40%;
	padding-inline-end: 12px;
	font-size: 13px;
	font-weight: normal;
	color: var(--color-text-maxcontrast, #666);
	overflow-wrap: anywhere;
}

.keepiq-secret-request-fill__summary td {
	color: var(--color-main-text, #222);
	overflow-wrap: anywhere;
}

.keepiq-secret-request-fill__close-hint {
	margin: 0;
	color: var(--color-text-maxcontrast, #666);
}

@media (max-width: 480px) {
	.keepiq-secret-request-fill {
		padding: 24px 16px;
	}
}
</style>
