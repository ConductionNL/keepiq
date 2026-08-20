<template>
	<div class="lock-screen">
		<div class="lock-screen__card">
			<!--
			  SYSTEMIC error banners sit above icon and title, matching the
			  Nextcloud login screen (its session-error banner tops the card).
			  Field-level mistakes (wrong password) render inline under the
			  input instead — also the login-screen pattern.
			-->
			<NcNoteCard v-if="!isSecureContext" type="error">
				{{
					t(
						'doriath',
						'Doriath requires a secure connection (HTTPS) to function. Please access this instance over HTTPS.',
					)
				}}
			</NcNoteCard>
			<!--
			  Suite check failed while online: fail closed. Showing the setup
			  form here would offer to create a NEW suite to a user who most
			  likely already has one (the check failed, so we cannot know).
			  Offline is the exception: the fetch always fails without a
			  network, but the unlock form is safe — offline unlock only
			  decrypts the local snapshot and can never create anything
			  server-side.
			-->
			<NcNoteCard
				v-else-if="checkFailed && offlineStore.online"
				type="error">
				{{
					t(
						'doriath',
						'Could not determine whether your vault is already set up. To protect your existing vault, setup and unlock are unavailable until this check succeeds.',
					)
				}}
			</NcNoteCard>

			<div class="lock-screen__icon">
				<LockIcon :size="48" />
			</div>
			<!--
			  No title while setup-vs-unlock is unknown — pending, or failed
			  while online (the error card explains itself). Either title
			  would be a claim we cannot back yet: "Unlock Doriath" reads
			  wrong to a user who has never set a password. Failed-while-
			  offline keeps the title: that state renders the unlock form.
			-->
			<h1
				v-if="!checking && !(checkFailed && offlineStore.online)"
				class="lock-screen__title">
				{{
					isFirstSetup
						? t('doriath', 'Set up your master password')
						: t('doriath', 'Unlock Doriath')
				}}
			</h1>

			<!-- Insecure context: banner above says it all, no form renders. -->
			<template v-if="!isSecureContext" />

			<!--
			  Suite check in flight. The setup-vs-unlock decision MUST wait for
			  the server answer: `currentSuite` starts null, so rendering on it
			  immediately flashes the "set up your master password" form at
			  every reload — a surface that can POST a brand-new suite — before
			  flipping to the unlock form. Nothing but a spinner renders until
			  the check settles.
			-->
			<template v-else-if="checking">
				<div class="lock-screen__checking">
					<NcLoadingIcon :size="32" />
					<p>{{ t('doriath', 'Checking your vault…') }}</p>
				</div>
			</template>

			<!-- Failed while online: banner above explains, only retry here. -->
			<template v-else-if="checkFailed && offlineStore.online">
				<NcButton variant="primary" :wide="true" @click="checkSuite">
					{{ t('doriath', 'Try again') }}
				</NcButton>
			</template>

			<template v-else>
				<!--
				  Migration paused. The copy here used to say "enter your master
				  password to resume", which conflated two different passwords:
				  unlocking uses the CURRENT one and does not resume anything,
				  while resuming needs the PREVIOUS one. Unlocking is step one;
				  MigrationResumeBanner then asks for the old password and does
				  the actual resuming.
				-->
				<NcNoteCard v-if="hasPausedMigration" type="warning">
					{{
						t(
							'doriath',
							'Key rotation is unfinished and your vault is read-only. Unlock to continue — you will then be asked for your previous master password to resume it.',
						)
					}}
				</NcNoteCard>

				<!-- First-time setup mode -->
				<template v-if="isFirstSetup">
					<NcPasswordField
						v-model="masterPassword"
						:label="t('doriath', 'Master password')"
						:disabled="loading"
						@blur="revealMismatch"
						@keyup.enter="handleSetup" />
					<PasswordStrengthMeter
						v-if="masterPassword"
						:password="masterPassword"
						@strengthChange="onStrengthChange" />
					<!--
					  Live mismatch feedback and setup failures render inline
					  under the field, matching the Nextcloud login screen's
					  wrong-password feedback (red outline + red helper text).
					  The inline text is the ACCESSIBLE channel: NcInputField
					  wires helperText to the input via aria-describedby, so
					  screen readers announce it — the button tooltip below
					  never reaches them (a disabled button is not focusable).
					-->
					<NcPasswordField
						v-model="confirmPassword"
						class="lock-screen__confirm"
						:label="t('doriath', 'Confirm master password')"
						:disabled="loading"
						:error="showMismatch || !!error"
						:helperText="confirmHelperText"
						@blur="revealMismatch"
						@keyup.enter="handleSetup" />
					<!--
					  Tooltip is progressive enhancement for mouse users
					  hovering the disabled button; it sits on a wrapper span
					  because browsers don't reliably fire hover on disabled
					  buttons. Never the only channel (WCAG 1.4.13) — the
					  helper text above carries the same message persistently.
					-->
					<span
						class="lock-screen__submit"
						:title="
							showMismatch
								? t(
									'doriath',
									'The password does not match. Please try again.',
								)
								: null
						">
						<NcButton
							variant="primary"
							:disabled="!canSubmitSetup || loading"
							:wide="true"
							@click="handleSetup">
							{{
								loading
									? t('doriath', 'Setting up…')
									: t('doriath', 'Set up vault')
							}}
						</NcButton>
					</span>
				</template>

				<!-- Normal unlock mode -->
				<template v-else>
					<NcButton
						v-if="passkeyOffered"
						variant="primary"
						:disabled="loading"
						:wide="true"
						data-testid="unlock-with-passkey"
						@click="handlePasskeyUnlock">
						<template #icon>
							<KeyIcon :size="20" />
						</template>
						{{
							loading
								? t('doriath', 'Unlocking…')
								: t('doriath', 'Unlock with passkey')
						}}
					</NcButton>

					<!--
					  Wrong-password (and passkey-fallback) errors render
					  inline under the field, matching the Nextcloud login
					  screen's wrong-password feedback (red outline + red
					  helper text) instead of a banner.
					-->
					<NcPasswordField
						v-model="masterPassword"
						:label="t('doriath', 'Master password')"
						:disabled="loading"
						:error="!!error"
						:helperText="error || ''"
						@keyup.enter="handleUnlock" />
					<NcButton
						:variant="passkeyOffered ? 'secondary' : 'primary'"
						:disabled="!masterPassword || loading"
						:wide="true"
						@click="handleUnlock">
						{{
							loading
								? t('doriath', 'Unlocking…')
								: t('doriath', 'Unlock')
						}}
					</NcButton>
				</template>
			</template>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import PasswordStrengthMeter from '../components/PasswordStrengthMeter.vue'
import { useEncryptionSuiteStore } from '../store/modules/encryptionSuite.js'
import { useOfflineStore } from '../store/modules/offline.js'
import { usePasskeyStore } from '../store/modules/passkey.js'
import { useSessionStore } from '../store/modules/session.js'

export default {
	name: 'LockScreen',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		LockIcon,
		KeyIcon,
		PasswordStrengthMeter,
	},

	data() {
		return {
			masterPassword: '',
			confirmPassword: '',
			loading: false,
			error: null,
			strengthValid: false,
			passkeyOffered: false,
			/**
			 * Suite-check state machine: 'pending' (spinner, no form),
			 * 'resolved' (server answered — setup or unlock is now KNOWN),
			 * 'failed' (fetch rejected — fail closed, never show setup).
			 */
			suiteCheck: 'pending',
			/**
			 * Whether the user is "done typing" in the setup fields: set by
			 * a pause (debounce) or by leaving a field, cleared on every
			 * keystroke. Gates the mismatch message so it never flashes
			 * mid-word.
			 */
			mismatchSettled: false,
			/** @type {number|null} Debounce timer for mismatchSettled. */
			mismatchTimer: null,
		}
	},

	computed: {
		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia offline store with no domain logic.
		 */
		offlineStore() {
			return useOfflineStore()
		},

		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia session store with no domain logic.
		 */
		sessionStore() {
			return useSessionStore()
		},

		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia encryption-suite store with no domain logic.
		 */
		suiteStore() {
			return useEncryptionSuiteStore()
		},

		/**
		 * First-time setup ONLY when the server has positively confirmed no
		 * active suite exists. While the check is pending or failed this is
		 * false, so the create-a-new-suite surface can never render on a
		 * guess — reloading used to flash it before the fetch resolved.
		 *
		 * @return {boolean} True when the server confirmed there is no suite.
		 */
		isFirstSetup() {
			return this.suiteCheck === 'resolved' && !this.suiteStore.currentSuite
		},

		/**
		 * @return {boolean} True while the suite check is in flight.
		 */
		checking() {
			return this.suiteCheck === 'pending'
		},

		/**
		 * @return {boolean} True when the suite check failed.
		 */
		checkFailed() {
			return this.suiteCheck === 'failed'
		},

		hasPausedMigration() {
			return this.suiteStore.migrationStatus?.status === 'in_progress'
		},

		isSecureContext() {
			return window.isSecureContext
		},

		/**
		 * Raw mismatch between the two setup fields — only once both are
		 * filled, so the user isn't scolded while still typing the first one.
		 *
		 * @return {boolean} True when both fields are filled and differ.
		 */
		passwordsMismatch() {
			return (
				!!this.masterPassword
				&& !!this.confirmPassword
				&& this.masterPassword !== this.confirmPassword
			)
		},

		/**
		 * Whether the mismatch message may be SHOWN: the mismatch is real
		 * AND the user is done typing (paused or left the field). Keystrokes
		 * hide it again instantly, so it never nags mid-correction.
		 *
		 * @return {boolean} True when the mismatch feedback should render.
		 */
		showMismatch() {
			return this.mismatchSettled && this.passwordsMismatch
		},

		/**
		 * Helper text under the confirm field: the settled mismatch
		 * explanation, then a server-side setup error. Matching passwords
		 * deliberately get NO feedback of their own — the submit button
		 * enabling is the success signal.
		 *
		 * @return {string} The helper text ('' when there is nothing to say).
		 */
		confirmHelperText() {
			if (this.showMismatch) {
				return t(
					'doriath',
					'The password does not match. Please try again.',
				)
			}
			return this.error || ''
		},

		/**
		 * Gate the first-time setup submit on matching, strength-valid passwords.
		 *
		 * @return {boolean} True when setup may be submitted.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		canSubmitSetup() {
			return (
				this.masterPassword
				&& this.confirmPassword
				&& this.masterPassword === this.confirmPassword
				&& this.strengthValid
			)
		},
	},

	watch: {
		/** Every keystroke restarts the "done typing" debounce. */
		masterPassword: 'restartMismatchDebounce',
		confirmPassword: 'restartMismatchDebounce',
	},

	/**
	 * Load the user's suite and migration status to decide between the
	 * unlock, first-setup, and paused-migration screens.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
	 */
	async created() {
		await this.checkSuite()
	},

	beforeUnmount() {
		clearTimeout(this.mismatchTimer)
	},

	methods: {
		/**
		 * Hide the mismatch message while typing and re-arm the pause timer:
		 * one second of idle counts as "done typing". Leaving a field
		 * (revealMismatch) short-circuits the wait.
		 */
		restartMismatchDebounce() {
			this.mismatchSettled = false
			clearTimeout(this.mismatchTimer)
			this.mismatchTimer = setTimeout(() => {
				this.mismatchSettled = true
			}, 1000)
		},

		/**
		 * Blur = done typing: reveal the mismatch feedback immediately
		 * instead of waiting out the pause timer.
		 */
		revealMismatch() {
			clearTimeout(this.mismatchTimer)
			this.mismatchSettled = true
		},

		/**
		 * Resolve the setup-vs-unlock decision from the server, driving the
		 * `suiteCheck` state machine. Extracted from `created()` so the
		 * failed state's "Try again" button can re-run it.
		 *
		 * Only `fetchSuite()` gates the decision. The migration-status and
		 * passkey-offer lookups are best-effort extras: a failure there
		 * degrades a banner or hides the passkey button, and must not push
		 * an answered setup-vs-unlock decision back into the failed state.
		 *
		 * @return {Promise<void>}
		 */
		async checkSuite() {
			this.suiteCheck = 'pending'
			try {
				await this.suiteStore.fetchSuite()
				this.suiteCheck = 'resolved'
			} catch {
				this.suiteCheck = 'failed'
				return
			}
			try {
				await this.suiteStore.fetchMigrationStatus()
				// Offer passkey unlock only when WebAuthn is present AND the
				// caller has an active enrolled passkey (feature-detected,
				// never assumed).
				if (this.offlineStore.online) {
					this.passkeyOffered = await usePasskeyStore().isUnlockOffered()
				}
			} catch {
				// Best-effort extras — the unlock/setup form still renders.
			}
		},

		/**
		 * Unlock the vault with a passkey (passkey-vault-login §4.1). On any
		 * failure, fall back to the master-password field without leaving the
		 * lock screen — the master password always works.
		 *
		 * @return {Promise<void>}
		 */
		async handlePasskeyUnlock() {
			this.loading = true
			this.error = null
			try {
				await usePasskeyStore().unlockWithPasskey()
				this.$router.push(this.$route.query.returnUrl || '/')
			} catch (e) {
				this.error =
					e?.message
					|| t(
						'doriath',
						'Passkey unlock failed — use your master password',
					)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Derive the AES key from the master password, unlock the vault,
		 * and redirect to the return URL (or root).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async handleUnlock() {
			this.loading = true
			this.error = null

			try {
				if (this.offlineStore.online) {
					await this.sessionStore.unlock(this.masterPassword)
				} else {
					// Offline unlock from the cached snapshot — no server request;
					// the master password never leaves the browser (offline §4.1).
					await this.offlineStore.unlockOffline(this.masterPassword)
				}
				const returnUrl = this.$route.query.returnUrl || '/'
				this.$router.push(returnUrl)
			} catch (e) {
				// When an online unlock fails on a network error, fall back to the
				// offline snapshot (covers "online but server unreachable").
				if (this.offlineStore.online && this.isNetworkError(e)) {
					try {
						await this.offlineStore.unlockOffline(this.masterPassword)
						this.$router.push(this.$route.query.returnUrl || '/')
						return
					} catch {
						// fall through to the generic error below
					}
				}
				this.error = t(
					'doriath',
					'Wrong master password or decryption failed',
				)
			} finally {
				this.loading = false
				this.masterPassword = ''
			}
		},

		/**
		 * Whether an unlock error is a network failure (server unreachable)
		 * rather than a wrong password — used to trigger the offline fallback.
		 *
		 * @param {Error} e The unlock error.
		 * @return {boolean}
		 */
		isNetworkError(e) {
			return (
				!!e
				&& (e.message === 'Network Error'
					|| e.code === 'ERR_NETWORK'
					|| (e.request && !e.response))
			)
		},

		/**
		 * First-time suite setup: create the encryption suite from the
		 * new master password and navigate into the vault.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async handleSetup() {
			if (!this.canSubmitSetup) return

			this.loading = true
			this.error = null

			try {
				await this.suiteStore.createSuite(this.masterPassword)
				this.$router.push('/')
			} catch (e) {
				this.error = e.message || t('doriath', 'Setup failed')
			} finally {
				this.loading = false
				this.masterPassword = ''
				this.confirmPassword = ''
			}
		},

		/**
		 * Track password-strength validity emitted by the strength meter.
		 *
		 * @param {object} root0 Strength event.
		 * @param {boolean} root0.isValid Whether the password meets the floor.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		onStrengthChange({ isValid }) {
			this.strengthValid = isValid
		},
	},
}
</script>

<style scoped>
.lock-screen {
	/*
	 * Fixed overlay, not an in-column page. This component renders inside
	 * CnAppRoot's content area, so as a normal page the app sidebar stays
	 * visible and clickable beside it. The unlock/setup prompt must be the
	 * only interactive surface, so cover the whole viewport below the
	 * Nextcloud global header (which stays reachable — locking the vault
	 * must not trap the user inside Doriath). z-index sits above
	 * NcAppNavigation (~1800) but below the header (2000) and modals.
	 */
	position: fixed;
	top: var(--header-height, 50px);
	right: 0;
	bottom: 0;
	left: 0;
	z-index: 1999;
	display: flex;
	/*
	 * Centering: the card uses `margin: auto` instead of justify/align
	 * center — identical result, but when the card is TALLER than a small
	 * viewport, flex-centering clips its top unreachably while margin:auto
	 * degrades to a scrollable top-aligned card.
	 *
	 * The overlay's box starts BELOW the global header, so its geometric
	 * center sits half a header too low on every screen size; the extra
	 * bottom padding re-centers the card on the true viewport
	 * (header-height / 2 each side of `auto`) plus a 3vh optical lift —
	 * dialogs read as centered when slightly above the geometric middle.
	 */
	overflow-y: auto;
	padding: 1rem;
	padding-bottom: calc(1rem + var(--header-height, 50px) + 6vh);
	/*
	 * Login-page look, not app-content look: the instance's primary color
	 * with the admin-themed background image on top (the same tokens the
	 * Nextcloud login screen uses). Falls back to the stock Nextcloud
	 * blue when theming variables are absent.
	 */
	background-color: var(--color-primary, #0082c9);
	background-image: var(--image-background, var(--gradient-primary-background));
	background-size: cover;
	background-position: center;
}

.lock-screen__card {
	margin: auto;
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 2rem;
	/* 400px matches Nextcloud's own login-form card width. */
	max-width: 400px;
	width: 100%;
	/* Theme shadow token, so dark mode gets its calibrated shadow. */
	box-shadow: 0 2px 8px var(--color-box-shadow, rgba(0, 0, 0, 0.1));
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.lock-screen__icon {
	text-align: center;
}

/* Block, so the tooltip wrapper doesn't shrink the wide submit button. */
.lock-screen__submit {
	display: block;
}

/*
 * Reserve the helper-text line up front: without this, the mismatch
 * message appearing grows the field and shoves the submit button down —
 * the "button moves" jolt. One reserved line keeps the card geometry
 * fixed whether feedback is showing or not.
 */
.lock-screen__confirm {
	min-height: calc(var(--default-clickable-area, 44px) + 2em);
}

/* Let the error state fade in rather than snap. */
.lock-screen__confirm :deep(.input-field__input) {
	transition: border-color 0.2s ease-out;
}

.lock-screen__confirm :deep(.input-field__helper-text-message) {
	animation: lock-screen-feedback-in 0.25s ease-out;
}

@keyframes lock-screen-feedback-in {
	from {
		opacity: 0;
	}

	to {
		opacity: 1;
	}
}

.lock-screen__checking {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.5rem;
	padding: 1rem 0;
}

.lock-screen__title {
	text-align: center;
	font-size: 1.25rem;
	margin: 0;
}
</style>
