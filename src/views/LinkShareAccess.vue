<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Public link-share access page mounted at /share/link/:token.

  Two-phase recipient flow:

   Phase 1 — fetch the public-share metadata (ciphertext blob + Argon2id
     salt) by token. Server has NO Nextcloud session. 404 is the generic
     not-found-or-expired response.

   Phase 2 — recipient types the access password; the page derives the
     AES-GCM key via Argon2id, decrypts the snapshot client-side, and
     POSTs a /confirm so the server can increment the usage count and
     auto-delete on cap.

  The blob is decrypted in the browser; the password NEVER reaches the
  server. A wrong password records a failed attempt via the `failed=1`
  re-fetch so the brute-force counter ticks.

  @spec openspec/changes/implement-link-sharing/tasks.md#task-8.1
  @spec openspec/changes/implement-link-sharing/tasks.md#task-9.1
-->
<template>
	<div class="keepiq-link-share-access" data-testid="link-share-access">
		<header class="keepiq-link-share-access__header">
			<h1>{{ t('keepiq', 'Shared secret') }}</h1>
		</header>

		<p v-if="loading && !share" class="keepiq-link-share-access__loading">
			{{ t('keepiq', 'Loading…') }}
		</p>

		<div
			v-else-if="loadError"
			class="keepiq-link-share-access__error"
			data-testid="link-share-load-error">
			<p>{{ loadError }}</p>
		</div>

		<form
			v-else-if="share && !snapshot"
			class="keepiq-link-share-access__form"
			data-testid="link-share-form"
			@submit.prevent="onUnlock">
			<p>
				{{
					t(
						'keepiq',
						'This link is protected with a password. Enter the password you received to view the secret.',
					)
				}}
			</p>

			<label>
				<span>{{ t('keepiq', 'Access password') }}</span>
				<input
					v-model="password"
					type="password"
					autocomplete="off"
					required
					data-testid="link-share-password" />
			</label>

			<p
				v-if="unlockError"
				class="keepiq-link-share-access__error"
				data-testid="link-share-unlock-error">
				{{ unlockError }}
			</p>

			<button
				type="submit"
				:disabled="busy || !password"
				data-testid="link-share-submit">
				{{
					busy ? t('keepiq', 'Decrypting…') : t('keepiq', 'Reveal secret')
				}}
			</button>
		</form>

		<div
			v-else-if="snapshot"
			class="keepiq-link-share-access__snapshot"
			data-testid="link-share-snapshot">
			<dl>
				<template v-if="snapshot.name">
					<dt>{{ t('keepiq', 'Name') }}</dt>
					<dd>{{ snapshot.name }}</dd>
				</template>
				<template v-if="snapshot.login">
					<dt>{{ t('keepiq', 'Login') }}</dt>
					<dd>{{ snapshot.login }}</dd>
				</template>
				<template v-if="snapshot.url">
					<dt>{{ t('keepiq', 'URL') }}</dt>
					<dd>{{ snapshot.url }}</dd>
				</template>
				<dt>{{ t('keepiq', 'Secret value') }}</dt>
				<dd>
					<code data-testid="link-share-value">{{ snapshot.key }}</code>
				</dd>
			</dl>
			<p class="keepiq-link-share-access__warning">
				{{
					t(
						'keepiq',
						'You have viewed this share. It will not be reachable again once the usage cap is reached.',
					)
				}}
			</p>
		</div>
	</div>
</template>

<script>
import { useLinkShareStore } from '../store/modules/linkShare.js'

export default {
	name: 'LinkShareAccess',

	data() {
		return {
			token: '',
			share: null,
			snapshot: null,
			password: '',
			loading: false,
			busy: false,
			loadError: '',
			unlockError: '',
			priorFailure: false,
		}
	},

	/**
	 * Resolve the share token from the route (or the URL path when no router
	 * is wired) and start the public Phase-1 fetch.
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-access-via-link
	 */
	mounted() {
		// The manifest router passes :token through props. When no
		// router is wired (component-test mount), we fall back to
		// reading the URL path so the spec can drive the flow.
		this.token = this.$route?.params?.token ?? this.tokenFromUrl()
		if (this.token) {
			this.loadShare()
		} else {
			this.loadError = this.t('keepiq', 'No share token in the URL.')
		}
	},

	methods: {
		tokenFromUrl() {
			const m = String(window?.location?.pathname ?? '').match(
				/share\/link\/([^/?#]+)/,
			)
			return m ? decodeURIComponent(m[1]) : ''
		},

		/**
		 * Phase 1: fetch the public share metadata and encrypted snapshot for
		 * the token. A 404 is the generic not-found-or-expired answer.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/link-sharing/spec.md#requirement-access-via-link
		 */
		async loadShare() {
			this.loading = true
			this.loadError = ''
			try {
				const store = useLinkShareStore()
				this.share = await store.fetchPublicLinkShare(
					this.token,
					this.priorFailure,
				)
			} catch (e) {
				this.share = null
				this.loadError =
					e?.response?.data?.message
					?? this.t(
						'keepiq',
						'This share is not available. It may have expired or been used up.',
					)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Phase 2: derive the Argon2id key from the supplied password,
		 * decrypt the snapshot in the browser, then confirm the view so the
		 * server can count (and auto-delete at) the usage cap.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/link-sharing/spec.md#requirement-kdf-for-snapshot-encryption
		 * @spec openspec/specs/link-sharing/spec.md#requirement-brute-force-protection
		 * @spec openspec/specs/link-sharing/spec.md#requirement-auto-deletion
		 */
		async onUnlock() {
			if (!this.password || this.busy) {
				return
			}
			this.busy = true
			this.unlockError = ''

			const store = useLinkShareStore()
			try {
				this.snapshot = await store.decryptPublicSnapshot(
					this.share,
					this.password,
				)
				// Phase 2: confirm so the server can increment usage.
				await store.confirmPublicLinkShare(this.token)
			} catch (e) {
				this.unlockError = this.t(
					'keepiq',
					'The password does not match. Please try again.',
				)
				// Re-fetch with failed=1 so the brute-force counter ticks
				// server-side. We swallow re-fetch errors so a now-deleted
				// share simply surfaces as a load error on the next try.
				this.priorFailure = true
				try {
					await this.loadShare()
				} catch {}
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.keepiq-link-share-access {
	max-width: 560px;
	margin: 48px auto;
	padding: 24px;
	background: var(--color-main-background, #fff);
	border-radius: var(--border-radius-large, 12px);
}

.keepiq-link-share-access__header h1 {
	margin: 0 0 16px 0;
}

.keepiq-link-share-access__form label {
	display: block;
	margin: 12px 0;
}

.keepiq-link-share-access__form input {
	display: block;
	width: 100%;
	padding: 8px;
	margin-top: 4px;
}

.keepiq-link-share-access__error {
	color: var(--color-error-text);
}

.keepiq-link-share-access__snapshot dt {
	font-weight: 600;
	margin-top: 8px;
}

.keepiq-link-share-access__snapshot dd {
	margin: 0 0 4px 0;
}

.keepiq-link-share-access__warning {
	margin-top: 16px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}
</style>
