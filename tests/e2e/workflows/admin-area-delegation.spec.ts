import type { APIRequestContext, Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Workflow: one Keepiq admin area delegated to a group (admin-scoped-roles 3.4).
 *
 * The spec seeds its own fixture through Nextcloud's own APIs, the same way an
 * administrator builds an auditor role: a group, a member, and a delegation of
 * ONLY the "Audit and compliance" area on "Administration privileges"
 * (`AuthorizedGroup#saveSettings`). It then signs in as that member and checks
 * what the member sees and what the server refuses.
 *
 * @e2e openspec/specs/admin-scoped-roles/spec.md#administrator-builds-an-auditor-role
 * @e2e openspec/specs/admin-scoped-roles/spec.md#auditor-cannot-change-policies
 * @e2e openspec/specs/admin-scoped-roles/spec.md#an-area-write-carries-only-its-own-keys
 */
import { expect, test } from '@playwright/test'
import { BASE_URL } from '../base-url.ts'

const ADMIN_USER = process.env.ADMIN_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || 'admin'
const GROUP = 'keepiq-e2e-auditors'
const MEMBER = 'keepiq-e2e-auditor'
const MEMBER_PASS = 'Kq-e2e-auditor-2026-area'
const AUDIT_AREA = 'OCA\\Keepiq\\Settings\\AuditAdminSettings'
const SETTINGS = '/index.php/apps/keepiq/api/settings/admin'

const ADMIN_HEADERS = {
	Authorization:
		'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64'),
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
}

/**
 * Create the group, the member and the Audit-only delegation. Idempotent: a
 * leftover group or user from an earlier run is reused.
 *
 * @param request An API context (no session; Basic auth per request).
 */
async function seedAuditor(request: APIRequestContext): Promise<void> {
	await request.post(`${BASE_URL}/ocs/v2.php/cloud/groups?format=json`, {
		headers: ADMIN_HEADERS,
		form: { groupid: GROUP },
	})
	await request.post(`${BASE_URL}/ocs/v2.php/cloud/users?format=json`, {
		headers: ADMIN_HEADERS,
		form: { userid: MEMBER, password: MEMBER_PASS, 'groups[]': GROUP },
	})
	const join = await request.post(
		`${BASE_URL}/ocs/v2.php/cloud/users/${MEMBER}/groups?format=json`,
		{ headers: ADMIN_HEADERS, form: { groupid: GROUP } },
	)
	expect(join.status(), 'the member joins the auditor group').toBe(200)

	const delegate = await request.post(
		`${BASE_URL}/index.php/apps/settings/settings/authorizedgroups/saveSettings`,
		{
			headers: { ...ADMIN_HEADERS, 'Content-Type': 'application/json' },
			data: { newGroups: [{ gid: GROUP }], class: AUDIT_AREA },
		},
	)
	expect(delegate.status(), 'Nextcloud stores the Audit-only delegation').toBe(200)
}

/**
 * Read one settings area as the instance administrator.
 *
 * @param request An API context.
 * @param area The area key.
 * @return The area's settings.
 */
async function adminRead(
	request: APIRequestContext,
	area: string,
): Promise<Record<string, unknown>> {
	const res = await request.get(`${BASE_URL}${SETTINGS}/${area}`, {
		headers: ADMIN_HEADERS,
	})
	expect(res.status()).toBe(200)
	return res.json()
}

/**
 * Sign in through the Nextcloud login form.
 *
 * @param page A page with no session.
 */
async function signIn(page: Page): Promise<void> {
	await page.goto('/login')
	await page.locator('#user').fill(MEMBER)
	await page.locator('#password').fill(MEMBER_PASS)
	await page.locator('button[type="submit"]').first().click()
	await expect(page).not.toHaveURL(/\/login/, { timeout: 30_000 })
}

/**
 * Send a JSON write from inside the signed-in page, with its CSRF token.
 *
 * @param page The signed-in page.
 * @param path The Keepiq path.
 * @param body The JSON body.
 * @return The HTTP status.
 */
async function pagePut(
	page: Page,
	path: string,
	body: Record<string, unknown>,
): Promise<number> {
	return page.evaluate(
		async ({ path, body }) => {
			const token =
				document.querySelector('head')?.getAttribute('data-requesttoken')
				|| (window as unknown as { OC?: { requestToken?: string } }).OC
					?.requestToken
				|| ''
			const res = await fetch(path, {
				method: 'PUT',
				credentials: 'include',
				headers: { 'Content-Type': 'application/json', requesttoken: token },
				body: JSON.stringify(body),
			})
			return res.status
		},
		{ path, body },
	)
}

test.describe('Workflow: an auditor role holds only the Audit and compliance area', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	test.afterAll(async ({ playwright }) => {
		const request = await playwright.request.newContext()
		await request.delete(
			`${BASE_URL}/ocs/v2.php/cloud/users/${MEMBER}?format=json`,
			{ headers: ADMIN_HEADERS },
		)
		await request.delete(
			`${BASE_URL}/ocs/v2.php/cloud/groups/${GROUP}?format=json`,
			{ headers: ADMIN_HEADERS },
		)
		await request.dispose()
	})

	test('the member sees the audit sections only and is refused the policies', async ({
		page,
		request,
	}) => {
		test.setTimeout(120_000)
		await seedAuditor(request)
		const policiesBefore = await adminRead(request, 'policies')
		const auditBefore = await adminRead(request, 'audit')

		await signIn(page)
		await page.goto('/index.php/settings/admin/keepiq')

		// The Audit area mounts and renders its sections.
		await expect(page.locator('#keepiq-settings-audit')).toBeAttached({
			timeout: 30_000,
		})
		await expect(page.locator('.keepiq-settings--audit')).toBeVisible({
			timeout: 30_000,
		})
		for (const name of [
			'Audit trail',
			'Compliance reporting',
			'SIEM audit export',
		]) {
			await expect(
				page
					.locator('.keepiq-settings--audit')
					.getByRole('heading', { name }),
				`the auditor sees "${name}"`,
			).toBeVisible()
		}

		// No other area is mounted, and none of their sections render.
		for (const area of ['general', 'policies', 'applications', 'people']) {
			await expect(
				page.locator(`#keepiq-settings-${area}`),
				`no ${area} area`,
			).toHaveCount(0)
		}
		for (const name of ['Password Policy', 'Offboarding']) {
			await expect(
				page.getByRole('heading', { name, exact: true }),
			).toHaveCount(0)
		}

		// The server refuses a policies write from the auditor ...
		const policyStatus = await pagePut(page, `${SETTINGS}/policies`, {
			min_password_length: Number(policiesBefore.min_password_length) + 4,
		})
		expect(policyStatus, 'PUT /api/settings/admin/policies is refused').toBe(403)

		// ... and an audit write that smuggles a policy key.
		const mixedStatus = await pagePut(page, `${SETTINGS}/audit`, {
			audit_retention_days: Number(auditBefore.audit_retention_days) + 1,
			min_password_length: Number(policiesBefore.min_password_length) + 4,
		})
		expect(mixedStatus, 'an audit write carrying a policy key answers 400').toBe(
			400,
		)

		// Nothing changed.
		expect((await adminRead(request, 'policies')).min_password_length).toBe(
			policiesBefore.min_password_length,
		)
		expect((await adminRead(request, 'audit')).audit_retention_days).toBe(
			auditBefore.audit_retention_days,
		)
	})
})
