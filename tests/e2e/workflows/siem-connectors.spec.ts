/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An administrator creates a Splunk HTTP Event Collector sink on the SIEM
 * section of the Nextcloud admin settings, test-fires it at an endpoint that
 * cannot answer, and sees the failure outcome (audit-siem-vendor-connectors
 * task 4.2). The sink is deleted again at the end.
 *
 * The admin settings page is not behind the vault lock, so no unlock is
 * needed. `.invalid` never resolves (RFC 2606), so the delivery fails without
 * touching any real host.
 *
 * @e2e openspec/specs/siem-vendor-connectors/spec.md#administrator-creates-a-splunk-connector
 */
import { expect, test } from '@playwright/test'

const ADMIN_SETTINGS = '/index.php/settings/admin/keepiq'
const ENDPOINT = 'https://splunk.invalid:8088/services/collector/event'

/**
 * The input inside an NcTextField, whichever element carries the test id.
 *
 * @param testid The data-testid of the field.
 */
function field(testid: string) {
	return `input[data-testid="${testid}"], [data-testid="${testid}"] input`
}

test('an administrator adds a Splunk HEC sink and test-fires it', async ({
	page,
}) => {
	await page.goto(ADMIN_SETTINGS, { waitUntil: 'domcontentloaded' })
	const section = page.locator('[data-testid="siem-section"]')
	await expect(section).toBeVisible({ timeout: 20_000 })

	await section.locator('[data-testid="siem-add"]').click()
	// Splunk HTTP Event Collector is the first and default connector.
	await expect(
		section.locator('[data-testid="siem-form-connector"]'),
	).toContainText('Splunk HTTP Event Collector')
	await section.locator(field('siem-form-name')).first().fill('E2E Splunk')
	await section.locator(field('siem-form-endpoint')).first().fill(ENDPOINT)
	await section
		.locator(field('siem-form-credential'))
		.first()
		.fill('e2e-hec-token-not-real')
	await section.locator(field('siem-form-index')).first().fill('e2e')
	// Fields of other connectors are not shown.
	await expect(section.locator('[data-testid="siem-form-tenant"]')).toHaveCount(0)
	await expect(section.locator('[data-testid="siem-form-secret"]')).toHaveCount(0)

	const created = page.waitForResponse(
		(r) =>
			r.url().includes('/api/v1/siem/sinks')
			&& r.request().method() === 'POST',
	)
	await section.locator('[data-testid="siem-form-save"]').click()
	const response = await created
	expect(response.status()).toBe(201)
	const sink = await response.json()
	expect(sink.type).toBe('splunk_hec')
	expect(sink.hasCredential).toBe(true)
	expect(JSON.stringify(sink)).not.toContain('e2e-hec-token-not-real')

	const row = section.locator(`[data-testid="siem-sink-${sink.id}"]`)
	await expect(row).toBeVisible()
	const testResponse = page.waitForResponse((r) =>
		r.url().endsWith(`/api/v1/siem/sinks/${sink.id}/test`),
	)
	await row.locator(`[data-testid="siem-test-${sink.id}"]`).click()
	// The outcome line is translated; the sink name and the host are not.
	const outcome = await (await testResponse).json()
	expect(outcome.ok).toBe(false)
	expect(outcome.error).toContain('splunk.invalid')
	await expect(
		section.getByText(/E2E Splunk.*splunk\.invalid/).first(),
	).toBeVisible({ timeout: 30_000 })
	await expect(section).not.toContainText('e2e-hec-token-not-real')

	await row.locator(`[data-testid="siem-delete-${sink.id}"]`).click()
	await expect(row).toHaveCount(0)
})
