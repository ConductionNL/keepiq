// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNull

/** The vault index rules (task 3.1) on every target, the iOS simulator included. */
class VaultIndexTest {
    private fun row(id: String, name: String, folderId: String? = null, url: String? = null, trashed: Boolean = false) = VaultRow(
        id = id, name = name, url = url, typeId = null, folderId = folderId, key = null, login = null,
        additionalFields = null, updatedAt = null, useOnly = false, readOnly = false, blocked = false,
        blockedReason = null, trashed = trashed,
    )

    @Test
    fun namesSortWithoutCaseOrAccentsThenById() {
        val index = VaultIndex.build(
            listOf(row("3", "zeeland"), row("2", "Énergie"), row("1", "energie"), row("4", "Bank"), row("5", "oud", trashed = true)),
            emptyList(),
            emptyList(),
        )
        assertEquals(listOf("4", "1", "2", "3"), index.map { it.id })
        assertEquals("login", index.first().typeName)
    }

    @Test
    fun theTreeIsDepthFirstAndSurvivesAMissingParentAndACycle() {
        val folders = listOf(
            VaultFolder("a", "Werk", null),
            VaultFolder("b", "Klanten", "a"),
            VaultFolder("c", "Archief", "a"),
            VaultFolder("d", "Wees", "weg"),
            VaultFolder("x", "Lus 1", "y"),
            VaultFolder("y", "Lus 2", "x"),
        )
        assertEquals(
            listOf("Wees" to 0, "Werk" to 0, "Archief" to 1, "Klanten" to 1),
            VaultIndex.folderTree(folders).map { it.name to it.depth },
        )
        assertEquals(listOf("Archief", "Klanten"), VaultIndex.subfolders(folders, "a").map { it.name })
        assertEquals("Werk / Klanten", VaultIndex.folderPath(folders, "b"))
        assertNull(VaultIndex.folderPath(folders, null))
    }

    @Test
    fun searchIgnoresCaseAndMatchesTheAddress() {
        val index = VaultIndex.build(listOf(row("1", "Huisbank", url = "https://Mijn.Bank.example"), row("2", "Portaal")), emptyList(), emptyList())
        assertEquals(listOf("1"), VaultIndex.filter(index, " bank.EX ", null, null).map { it.id })
        assertEquals(ListState.ITEMS, VaultIndex.listState(index, index))
        assertEquals(ListState.LOADING, VaultIndex.listState(null, emptyList()))
    }
}
