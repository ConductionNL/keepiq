<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  A certificate's expiry date in the certificate inventory, marked critical
  under a week and warning under a month (the host view styles both
  classes); without a parsed expiry it shows its slot, a dash by default.
-->
<template>
	<span v-if="notAfter" :class="expiryClass">
		{{ formatted }}
	</span>
	<slot v-else>
		<span>—</span>
	</slot>
</template>

<script>
/**
 * Show when a certificate expires, and how soon.
 */
export default {
	name: 'CertificateExpiry',

	props: {
		/** The certificate's notAfter as an ISO timestamp, if parsed. */
		notAfter: {
			type: String,
			default: null,
		},
	},

	computed: {
		/**
		 * The urgency class: critical under a week, warning under a month.
		 *
		 * @return {string}
		 * @spec exclude Presentation-only urgency marker.
		 */
		expiryClass() {
			const daysLeft = (new Date(this.notAfter).getTime() - Date.now()) / 86400000
			if (daysLeft < 7) {
				return 'cert-inventory__expiry--critical'
			}
			if (daysLeft < 30) {
				return 'cert-inventory__expiry--warning'
			}
			return ''
		},

		/**
		 * The expiry as a locale date.
		 *
		 * @return {string}
		 * @spec exclude Presentation-only formatter: renders an ISO timestamp as a locale date string.
		 */
		formatted() {
			return new Date(this.notAfter).toLocaleDateString()
		},
	},
}
</script>
