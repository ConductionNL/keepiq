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
				<label
					v-for="field in store.publicRequest.requested_fields"
					:key="field"
					class="keepiq-secret-request-fill__field">
					<span>{{ inputLabel(field) }}</span>
					<input
						v-model="values[field]"
						:type="inputType(field)"
						autocomplete="off"
						required
						:data-testid="`fill-field-${field}`" />
				</label>

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
					:disabled="busy">
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
								<template v-if="inputType(field) === 'password'">
									<span aria-hidden="true">••••••••</span>
									<span class="hidden-visually">{{
										t('keepiq', 'Hidden')
									}}</span>
								</template>
								<template v-else>
									{{ values[field] }}
								</template>
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
import { NcButton } from '@nextcloud/vue'
import LinkVariantOff from 'vue-material-design-icons/LinkVariantOff.vue'
import { useSecretRequestStore } from '../store/modules/secretRequest.js'

export default {
	name: 'SecretRequestFill',
	components: {
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
			switch (reason) {
				case 'fulfilled':
					return t('keepiq', 'This request has already been fulfilled.')
				case 'declined':
					return t('keepiq', 'This request was declined.')
				case 'locked':
					return t(
						'keepiq',
						'This request is temporarily unavailable while a compromise recovery is in progress.',
					)
				case 'expired':
					return t('keepiq', 'This request has expired.')
				case 'unavailable':
					// The requester's key was revoked (#809 review).
					return t('keepiq', 'This request is no longer available.')
				case 'not-found':
					return t('keepiq', 'This request could not be found.')
				default:
					return null
			}
		},

		/**
		 * The label a recipient reads above a requested field.
		 *
		 * The three column names get the wording the requester picked them by in
		 * SecretRequestCreateDialog; an additional member is shown by the name the
		 * requester typed, which is the only label it has.
		 *
		 * @param {string} field The requested field name.
		 *
		 * @return {string} The label to render.
		 *
		 * @spec exclude Presentation-only: labels the requested field names.
		 */
		inputLabel(field) {
			switch (field) {
				case 'key':
					return t('keepiq', 'Key / password')
				case 'login':
					return t('keepiq', 'Login')
				case 'url':
					return t('keepiq', 'URL')
				default:
					return field
			}
		},

		/**
		 * @param {object} field The field descriptor.
		 *
		 * @spec exclude Presentation-only: masks inputs whose field name looks secret.
		 */
		inputType(field) {
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
		 * Encrypt the filled-in values under the requester's certificate in
		 * the browser and submit them against the request token.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
		 * @spec openspec/specs/secret-requests/spec.md#requirement-field-validation
		 * @spec openspec/specs/secret-requests/spec.md#requirement-write-once
		 */
		async onSubmit() {
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

.keepiq-secret-request-fill__field input {
	box-sizing: border-box;
	display: block;
	width: 100%;
	padding: 10px 12px;
	border: 2px solid var(--color-border-maxcontrast, #949494);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
}

.keepiq-secret-request-fill__field input:focus-visible {
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
