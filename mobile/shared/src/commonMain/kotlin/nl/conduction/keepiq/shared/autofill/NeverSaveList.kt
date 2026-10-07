// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.autofill

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import nl.conduction.keepiq.shared.account.SecureStorage

/**
 * The sites the user never wants a save offer on, kept on this device only,
 * as the extension keeps its `capture-never` list
 * (browser-extension/src/background/router.js neverSites and setNever): an
 * exact host, so "never" on login.example.com still offers on
 * www.example.com. An app is listed as `androidapp://<package>`.
 */
class NeverSaveList(private val storage: SecureStorage) {
    fun sites(): List<String> {
        val text = storage.read(KEY) ?: return emptyList()
        val array = runCatching { Json.parseToJsonElement(text) as? JsonArray }.getOrNull() ?: return emptyList()
        return array.mapNotNull { (it as? JsonPrimitive)?.contentOrNull }
    }

    fun contains(site: String): Boolean = site.isNotEmpty() && site in sites()

    /** setNever: add or remove a site; the list stays sorted and without duplicates. */
    fun set(site: String, on: Boolean): List<String> {
        val list = sites().filter { it != site }.toMutableList()
        if (on && site.isNotEmpty()) list.add(site)
        list.sort()
        storage.write(KEY, JsonArray(list.map { JsonPrimitive(it) }).toString())
        return list
    }

    companion object {
        const val KEY = "autofill:never-save"
    }
}
