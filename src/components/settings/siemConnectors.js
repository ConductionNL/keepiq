/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The connectors an administrator can pick on the SIEM section, the sink
 * type and format each one stores, and the form fields each one needs.
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-named-siem-connectors-on-a-sink
 */

/** Connector key → the sink type and format it stores, and its form fields. */
export const CONNECTORS = {
	splunk_hec: {
		type: 'splunk_hec',
		format: 'json',
		fields: ['endpoint', 'credential', 'index', 'sourcetype'],
	},
	sentinel: {
		type: 'sentinel',
		format: 'json',
		fields: ['endpoint', 'tenantId', 'clientId', 'dcrImmutableId', 'streamName', 'credential'],
	},
	syslog_cef: {
		type: 'syslog',
		format: 'cef',
		fields: ['endpoint', 'tls'],
	},
	syslog_json: {
		type: 'syslog',
		format: 'json',
		fields: ['endpoint', 'tls'],
	},
	webhook: {
		type: 'webhook',
		format: 'json',
		fields: ['endpoint', 'hmacSecret'],
	},
}

/** Connector settings stored in connectorOptions, per connector. */
export const OPTION_FIELDS = {
	splunk_hec: ['index', 'sourcetype'],
	sentinel: ['tenantId', 'clientId', 'dcrImmutableId', 'streamName'],
}

/**
 * The form fields a connector shows.
 *
 * @param {string} connector The connector key.
 * @return {string[]}
 */
export function fieldsFor(connector) {
	return CONNECTORS[connector]?.fields ?? []
}

/**
 * The connector key of a stored sink.
 *
 * @param {object} sink The sink as the API returns it.
 * @return {string}
 */
export function connectorOf(sink) {
	if (sink.type === 'syslog') {
		return sink.format === 'cef' ? 'syslog_cef' : 'syslog_json'
	}
	return sink.type in CONNECTORS ? sink.type : 'webhook'
}

/**
 * Whether the form can be saved: an endpoint, https for every HTTP
 * connector, the Sentinel settings, and on create the connector credential.
 *
 * @param {object} form The form state.
 * @param {boolean} creating Whether this is a new sink.
 * @return {boolean}
 */
export function formIsValid(form, creating) {
	const connector = CONNECTORS[form.connector]
	if (!connector || form.endpoint === '') {
		return false
	}
	if (connector.type !== 'syslog' && !form.endpoint.startsWith('https://')) {
		return false
	}
	if (form.connector === 'sentinel' && ['tenantId', 'clientId', 'dcrImmutableId'].some((f) => !form[f])) {
		return false
	}
	if (creating && connector.fields.includes('credential') && !form.credential) {
		return false
	}
	return true
}

/**
 * The request body for a create or an update. The credential and the HMAC
 * secret are sent as typed; blank keeps the stored one on an update.
 *
 * @param {object} form The form state.
 * @return {object}
 */
export function requestBody(form) {
	const connector = CONNECTORS[form.connector]
	const body = {
		name: form.name || form.connector,
		type: connector.type,
		format: connector.format,
		endpoint: form.endpoint,
		tls: form.tls,
		hmacSecret: connector.fields.includes('hmacSecret') ? form.hmacSecret : '',
		credential: connector.fields.includes('credential') ? form.credential : '',
		categoryFilter: form.categoryFilter,
		queueCap: parseInt(form.queueCap, 10) || 1000,
		enabled: form.enabled,
	}
	const options = OPTION_FIELDS[form.connector]
	if (options) {
		body.connectorOptions = Object.fromEntries(options.filter((f) => form[f]).map((f) => [f, form[f]]))
		if (form.connector === 'sentinel') {
			body.connectorOptions.dataCollectionEndpoint = form.endpoint
		}
	}
	return body
}
