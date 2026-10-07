// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.sync

import app.cash.sqldelight.driver.jdbc.sqlite.JdbcSqliteDriver
import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpStatusCode
import io.ktor.http.headersOf
import kotlinx.coroutines.test.runTest
import nl.conduction.keepiq.shared.api.Account
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.crypto.Primitives
import nl.conduction.keepiq.shared.store.UnlockKeySealer
import nl.conduction.keepiq.shared.store.VaultStore
import nl.conduction.keepiq.shared.store.db.KeepiqDatabase
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertIs
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** Sync without a change feed (task 1.7): every trigger, the cheap check and the epoch comparison. */
class VaultSyncTest {
    private val session = UnlockedSession("suite-9", 2)
    private var now = 10_000_000L
    private val calls = mutableListOf<String>()
    private val locks = mutableListOf<LockReason>()
    private var wrapsDeleted = 0

    /** What the fake server answers; tests change it between syncs. */
    private var manifestStatus = HttpStatusCode.OK
    private var epoch = 2
    private var suiteId = "suite-9"
    private var unlockBlocked = "null"
    private var top = "2026-10-03T09:00:00+00:00"
    private var name = "Huisbank"

    private val store = VaultStore(
        JdbcSqliteDriver(JdbcSqliteDriver.IN_MEMORY).also { KeepiqDatabase.Schema.create(it) },
        UnlockKeySealer(Primitives.randomBytes(32)),
    )

    private val engine = MockEngine { request ->
        val path = request.url.encodedPath.substringAfter("/apps/keepiq")
        val query = request.url.encodedQuery
        calls += if (query.isEmpty()) path else "$path?$query"
        val suite = """{"id":"$suiteId","status":"active","unlockKeyEpoch":$epoch,"unlockBlocked":null}"""
        val body = when {
            path == "/api/v1/offline/manifest" && manifestStatus != HttpStatusCode.OK -> """{"message":"off"}"""
            path == "/api/v1/offline/manifest" -> """{"suite":${if (unlockBlocked == "null") suite else "null"},
                "secrets":[{"id":"s1","name":"$name","url":null,"key":"CT","updatedAt":"$top"},
                           {"id":"s2","name":"Portaal","url":null,"key":"CT2","updatedAt":"2026-10-01T09:00:00+00:00"}],
                "folders":[],"types":[],"unlockBlocked":$unlockBlocked}"""
            path == "/api/v1/secrets" && query.startsWith("sort=") -> """{"items":[{"id":"s1","updatedAt":"$top"}],"total":2}"""
            path == "/api/v1/secrets" -> """{"items":[{"id":"s1","name":"$name","updatedAt":"$top"}],"total":1}"""
            path == "/api/v1/suites" -> "[$suite]"
            else -> "[]"
        }
        val status = if (path == "/api/v1/offline/manifest") manifestStatus else HttpStatusCode.OK
        respond(body, status, headersOf(HttpHeaders.ContentType, "application/json"))
    }

    private val sync = VaultSync(
        KeepiqApi(KeepiqApi.httpClient(engine), Account("a", "https://cloud.example.nl", "alice", "pw")),
        store,
        object : SyncListener {
            override fun lock(reason: LockReason) {
                locks += reason
            }

            override fun deleteUnlockWraps() {
                wrapsDeleted++
            }
        },
        clock = { now },
    )

    private val manifest = "/api/v1/offline/manifest"
    private val cheap = "/api/v1/secrets?sort=updated_at&direction=desc&limit=1"

    @Test
    fun startFetchesTheManifestAndStoresIt() = runTest {
        assertEquals(SyncOutcome.Synced(now, 2), sync.sync(SyncTrigger.START, session))
        assertEquals(listOf(manifest), calls)
        assertEquals("Huisbank", store.secret("s1")!!.name)
        assertEquals("2026-10-03T09:00:00+00:00", store.state()!!.checkTop)
    }

    @Test
    fun foregroundWithNothingNewOnlyRunsTheCheapCheck() = runTest {
        sync.sync(SyncTrigger.START, session)
        calls.clear()
        now += 60_000
        assertEquals(SyncOutcome.Fresh(now), sync.sync(SyncTrigger.FOREGROUND, session))
        assertEquals(listOf(cheap), calls)
        assertEquals(now, store.state()!!.syncedAtMillis)
    }

    @Test
    fun aChangeOnTheWebReachesThePhoneOnForeground() = runTest {
        sync.sync(SyncTrigger.START, session)
        calls.clear()
        top = "2026-10-04T12:00:00+00:00"
        name = "Huisbank (nieuw)"
        assertIs<SyncOutcome.Synced>(sync.sync(SyncTrigger.FOREGROUND, session))
        assertEquals(listOf(cheap, manifest), calls)
        assertEquals("Huisbank (nieuw)", store.secret("s1")!!.name)
    }

    @Test
    fun theTimerSyncsFullyOnceTheCopyIsOlderThan15Minutes() = runTest {
        sync.sync(SyncTrigger.START, session)
        calls.clear()
        now += VaultSync.SYNC_INTERVAL_MILLIS
        assertIs<SyncOutcome.Synced>(sync.sync(SyncTrigger.TIMER, session))
        assertEquals(listOf(cheap, manifest), calls)
    }

    @Test
    fun aWriteAndAManualRefreshAlwaysSyncFully() = runTest {
        sync.sync(SyncTrigger.START, session)
        for (trigger in listOf(SyncTrigger.AFTER_WRITE, SyncTrigger.MANUAL)) {
            calls.clear()
            assertIs<SyncOutcome.Synced>(sync.sync(trigger, session))
            assertEquals(listOf(manifest), calls, trigger.name)
        }
    }

    @Test
    fun aHigherUnlockKeyEpochLocksDeletesTheWrapsAndEmptiesTheStore() = runTest {
        sync.sync(SyncTrigger.START, session)
        epoch = 3
        assertEquals(SyncOutcome.Locked(LockReason.MASTER_PASSWORD_CHANGED), sync.sync(SyncTrigger.MANUAL, session))
        assertEquals(listOf(LockReason.MASTER_PASSWORD_CHANGED), locks)
        assertEquals(1, wrapsDeleted)
        assertTrue(store.secrets().isEmpty())
        assertNull(store.state())
    }

    @Test
    fun theSameEpochDoesNotLock() = runTest {
        sync.sync(SyncTrigger.START, session)
        sync.sync(SyncTrigger.MANUAL, session)
        assertTrue(locks.isEmpty())
        assertEquals(0, wrapsDeleted)
    }

    @Test
    fun aNewSuiteLocksAndEmptiesTheStore() = runTest {
        sync.sync(SyncTrigger.START, session)
        suiteId = "suite-10"
        assertEquals(SyncOutcome.Locked(LockReason.SUITE_CHANGED), sync.sync(SyncTrigger.MANUAL, session))
        assertTrue(store.secrets().isEmpty())
    }

    @Test
    fun theTwoFactorBlockLocksWithoutTouchingTheWraps() = runTest {
        sync.sync(SyncTrigger.START, session)
        unlockBlocked = "\"two_factor_required\""
        assertEquals(SyncOutcome.Locked(LockReason.UNLOCK_BLOCKED), sync.sync(SyncTrigger.MANUAL, session))
        assertEquals(0, wrapsDeleted)
        assertTrue(store.secrets().isEmpty())
    }

    @Test
    fun offlineCachingTurnedOffDeletesTheLocalCopyAndWorksOnline() = runTest {
        sync.sync(SyncTrigger.START, session)
        for (status in listOf(HttpStatusCode(428, "Precondition Required"), HttpStatusCode.Forbidden)) {
            manifestStatus = status
            val outcome = sync.sync(SyncTrigger.MANUAL, session)
            assertIs<SyncOutcome.OnlineOnly>(outcome)
            assertEquals(listOf("s1"), outcome.secrets.map { it["id"].toString().trim('"') })
            assertTrue(store.secrets().isEmpty(), "store emptied on $status")
            assertNull(store.state())
        }
    }

    @Test
    fun anEpochChangeIsAlsoSeenWithOfflineCachingOff() = runTest {
        manifestStatus = HttpStatusCode(428, "Precondition Required")
        epoch = 5
        assertEquals(SyncOutcome.Locked(LockReason.MASTER_PASSWORD_CHANGED), sync.sync(SyncTrigger.START, session))
        assertEquals(1, wrapsDeleted)
    }
}
