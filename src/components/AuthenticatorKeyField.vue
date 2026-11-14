<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The optional authenticator key of a login, in the create and edit dialogs.
  The key is pasted (an otpauth://totp/ link or a base32 secret) or read from
  a QR code image in the browser. A key that is already saved is never loaded
  into the form: the field shows that one is saved and offers Replace and
  Remove, so the seed is never on screen after saving.

  @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
-->
<template>
	<div class="authenticator-key" data-testid="authenticator-key">
		<div v-if="saved && mode !== 'replace'" class="authenticator-key__saved">
			<span class="authenticator-key__label">
				{{ t('keepiq', 'Authenticator key') }}
			</span>
			<p
				class="authenticator-key__state"
				data-testid="authenticator-key-state">
				<ClockOutline :size="20" />
				{{
					mode === 'remove'
						? t(
								'keepiq',
								'The authenticator key is removed when you save.',
							)
						: t('keepiq', 'Authenticator key saved')
				}}
			</p>
			<div class="authenticator-key__actions">
				<template v-if="mode === 'keep'">
					<NcButton
						v-if="!removeOnly"
						ref="replaceButton"
						variant="secondary"
						:disabled="disabled"
						data-testid="authenticator-key-replace"
						@click="setMode('replace')">
						{{ t('keepiq', 'Replace') }}
					</NcButton>
					<NcButton
						ref="removeButton"
						variant="tertiary"
						:disabled="disabled"
						data-testid="authenticator-key-remove"
						@click="setMode('remove')">
						{{ t('keepiq', 'Remove') }}
					</NcButton>
				</template>
				<NcButton
					v-else
					ref="undoButton"
					variant="tertiary"
					:disabled="disabled"
					data-testid="authenticator-key-undo"
					@click="setMode('keep')">
					{{ t('keepiq', 'Keep the saved key') }}
				</NcButton>
			</div>
		</div>

		<template v-else>
			<div class="authenticator-key__row">
				<NcPasswordField
					ref="input"
					class="authenticator-key__input"
					:modelValue="value"
					:label="
						saved
							? t('keepiq', 'New authenticator key')
							: t('keepiq', 'Authenticator key (optional)')
					"
					:helperText="
						t(
							'keepiq',
							'Paste the otpauth://totp/ link or the secret key, or read it from a QR code image.',
						)
					"
					:error="Boolean(shownError)"
					:aria-invalid="shownError ? 'true' : undefined"
					:aria-describedby="shownError ? errorId : undefined"
					:disabled="disabled"
					autocomplete="off"
					data-testid="authenticator-key-input"
					@update:modelValue="onInput" />
				<NcButton
					variant="tertiary-no-background"
					:disabled="disabled || reading"
					:title="t('keepiq', 'Read a QR code image')"
					:ariaLabel="t('keepiq', 'Read a QR code image')"
					data-testid="authenticator-key-qr"
					@click="pickImage">
					<template #icon>
						<NcLoadingIcon v-if="reading" :size="20" />
						<QrcodeScan v-else :size="20" />
					</template>
				</NcButton>
				<input
					ref="fileInput"
					class="authenticator-key__file"
					type="file"
					accept="image/*"
					tabindex="-1"
					aria-hidden="true"
					data-testid="authenticator-key-file"
					@change="onFile" />
			</div>
			<p
				v-if="shownError"
				:id="errorId"
				class="authenticator-key__error"
				role="alert"
				data-testid="authenticator-key-error">
				{{ shownError }}
			</p>
			<NcButton
				v-if="saved"
				ref="cancelButton"
				variant="tertiary"
				:disabled="disabled"
				data-testid="authenticator-key-cancel"
				@click="setMode('keep')">
				{{ t('keepiq', 'Keep the saved key') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcPasswordField } from '@nextcloud/vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import QrcodeScan from 'vue-material-design-icons/QrcodeScan.vue'
import { authenticatorKeyError } from '../totp/authenticatorKey.js'
import { decodeQrImage, QrImageError } from '../totp/qrImage.js'

/** Makes each field's error id unique on the page. */
let instanceCount = 0

/** Where focus goes once a saved-key choice re-renders the field. */
const FOCUS_AFTER = {
	replace: ['input'],
	remove: ['undoButton'],
	keep: ['replaceButton', 'removeButton'],
}

export default {
	name: 'AuthenticatorKeyField',

	components: {
		ClockOutline,
		NcButton,
		NcLoadingIcon,
		NcPasswordField,
		QrcodeScan,
	},

	props: {
		/** Whether the login already holds a saved key (edit dialog only). */
		saved: {
			type: Boolean,
			default: false,
		},

		/**
		 * What happens to a saved key: `keep` it, `replace` it with `value`,
		 * or `remove` it. Without a saved key the field is always an entry.
		 */
		mode: {
			type: String,
			default: 'keep',
			validator: (mode) => ['keep', 'replace', 'remove'].includes(mode),
		},

		/** The key being entered. */
		value: {
			type: String,
			default: '',
		},

		/**
		 * A saved key can only be removed, not replaced: where no key can be
		 * entered (a type other than login).
		 */
		removeOnly: {
			type: Boolean,
			default: false,
		},

		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:mode', 'update:value'],

	data() {
		instanceCount += 1
		return {
			reading: false,
			/** Why the last QR image could not be used, '' when it could. */
			qrError: '',
			errorId: `authenticator-key-error-${instanceCount}`,
			/** Whether the next mode change came from this field's own buttons. */
			focusPending: false,
		}
	},

	computed: {
		/**
		 * Why the entered key cannot be saved, '' when it can.
		 *
		 * @return {string}
		 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
		 */
		inputError() {
			return authenticatorKeyError(this.value)
		},

		/**
		 * The error under the field: the last QR read's, else the entry's.
		 *
		 * @spec exclude Presentation-only selection between two messages.
		 */
		shownError() {
			return this.qrError || this.inputError
		},
	},

	watch: {
		/**
		 * The button that was pressed is gone once the mode re-renders, so
		 * focus moves to what replaced it instead of falling to the page.
		 *
		 * @param {string} mode The new mode.
		 * @return {void}
		 * @spec exclude Focus management after a re-render.
		 */
		mode(mode) {
			if (!this.focusPending) {
				return
			}
			this.focusPending = false
			this.$nextTick(() => this.focusFirst(FOCUS_AFTER[mode] ?? []))
		},
	},

	methods: {
		t,

		/**
		 * Focus the first of these refs that is rendered.
		 *
		 * @param {Array<string>} names Ref names, in order of preference.
		 * @return {void}
		 * @spec exclude Focus management after a re-render.
		 */
		focusFirst(names) {
			for (const name of names) {
				const ref = this.$refs[name]
				const root = ref?.$el ?? ref
				if (!(root instanceof HTMLElement)) {
					continue
				}
				const target = root.matches('input, button')
					? root
					: root.querySelector('input, button')
				if (target) {
					target.focus()
					return
				}
			}
		},

		/**
		 * Choose what happens to the saved key; leaving the entry clears it.
		 *
		 * @param {string} mode keep, replace or remove.
		 * @return {void}
		 * @spec exclude Event re-emitter for the saved-key choice.
		 */
		setMode(mode) {
			this.focusPending = true
			this.qrError = ''
			if (mode !== 'replace') {
				this.$emit('update:value', '')
			}
			this.$emit('update:mode', mode)
		},

		/**
		 * @param {string} value The typed key.
		 * @return {void}
		 * @spec exclude Event re-emitter for the typed key.
		 */
		onInput(value) {
			this.qrError = ''
			this.$emit('update:value', value ?? '')
		},

		/**
		 * @return {void}
		 * @spec exclude Opens the hidden file picker.
		 */
		pickImage() {
			this.$refs.fileInput?.click()
		},

		/**
		 * Read the key from the picked QR image and put it in the field.
		 *
		 * @param {Event} event The file input change.
		 * @return {Promise<void>}
		 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
		 */
		async onFile(event) {
			const input = event.target
			const file = input?.files?.[0]
			if (!file) {
				return
			}
			this.reading = true
			this.qrError = ''
			try {
				this.$emit('update:value', await decodeQrImage(file))
			} catch (e) {
				// Only the reader's own refusals are worded for the user.
				this.qrError =
					e instanceof QrImageError
						? e.message
						: t('keepiq', 'This image could not be read.')
			} finally {
				this.reading = false
				// The same image can be picked again after a failure.
				input.value = ''
			}
		},
	},
}
</script>

<style scoped>
.authenticator-key {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.authenticator-key__row {
	display: flex;
	align-items: flex-start;
	gap: 8px;
}

.authenticator-key__input {
	flex: 1 1 auto;
}

.authenticator-key__file {
	display: none;
}

.authenticator-key__label {
	color: var(--color-text-maxcontrast);
}

.authenticator-key__state {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
}

.authenticator-key__actions {
	display: flex;
	gap: 8px;
}

.authenticator-key__error {
	margin: 0;
	color: var(--color-text-error);
}
</style>
