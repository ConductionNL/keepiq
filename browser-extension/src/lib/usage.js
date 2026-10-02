/**
 * Report a fill to the server as a use of the secret
 * (vault-favourites-tags-and-last-used). Only a fill that happened is
 * reported, once, and a failed report is swallowed: the sort order is not
 * worth breaking a fill for.
 *
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-sort-by-date-last-used
 */

/**
 * Post the secret id after a successful fill.
 *
 * @param {{filled?: boolean}|undefined} results What the page answered to the fill.
 * @param {string} id The filled secret's id.
 * @param {function(string): Promise<unknown>} post Sends the report.
 * @return {Promise<boolean>} Whether a report was sent and accepted.
 */
export async function reportFill(results, id, post) {
	if (!results?.filled || !id) return false
	try {
		await post(id)
		return true
	} catch {
		return false
	}
}
