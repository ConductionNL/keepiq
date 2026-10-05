// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import Foundation
import KeepiqShared
import UIKit
import UniformTypeIdentifiers

/// A localized string of the AutoFill screens (Autofill.strings).
func AL(_ key: String, _ args: CVarArg...) -> String {
    let format = NSLocalizedString(key, tableName: "Autofill", bundle: .main, comment: "")
    return args.isEmpty ? format : String(format: format, arguments: args)
}

/// The vault keys the extension opened, kept in its process for the idle
/// time, so a second fill soon after needs no new unlock
/// (provideCredentialWithoutUserInteraction, design D6).
enum ExtensionSession {
    private static var keys: VaultKeys?
    private static var accountId: String?
    private static var until = Date.distantPast

    static func keep(_ keys: VaultKeys, accountId: String, minutes: Int) {
        self.keys = keys
        self.accountId = accountId
        until = Date().addingTimeInterval(Double(minutes) * 60)
    }

    static func current(_ accountId: String) -> VaultKeys? {
        guard self.accountId == accountId, Date() < until else {
            keys = nil
            return nil
        }
        return keys
    }

    static func forget() {
        keys = nil
        accountId = nil
    }
}

/// One login the AutoFill list shows: its name and site, never a password.
struct AutofillRow: Identifiable, Hashable {
    let id: String
    let name: String
    let site: String
    let serviceIdentifier: String
}

/// A filled login.
struct FilledLogin {
    let user: String
    let password: String
}

/// A passkey request the extension answers (task 5.2). iOS hands over the
/// rpId it verified and the hash of the clientDataJSON it built.
enum PasskeyRequest {
    case assertion(rpId: String, clientDataHash: Data, credentialId: Data?, allowed: [Data])
    case registration(rpId: String, userName: String, userHandle: Data, clientDataHash: Data, algorithms: [Int])

    var rpId: String {
        switch self {
        case .assertion(let rpId, _, _, _), .registration(let rpId, _, _, _, _): return rpId
        }
    }
}

/// What the extension hands back to iOS for a passkey request.
enum PasskeyResult {
    case assertion(AppleAssertion, rpId: String, clientDataHash: Data)
    case registration(AppleRegistration, rpId: String, clientDataHash: Data, userHandle: Data)
}

/// One passkey the sheet lists: its item name and user name, never a key.
struct PasskeyRow: Identifiable, Hashable {
    let id: String
    let name: String
    let user: String
}

/// The AutoFill extension's state (task 4.4): the unlock, the logins for the
/// asked sites, search over the other sites one file at a time, "Add login",
/// and the fill with the one-time code on the clipboard (task 4.5). For a
/// passkey request (task 5.2) the same unlock, then the passkey signs or is
/// created with the shared core's ES256.
@MainActor
final class AutofillModel: ObservableObject {
    enum Phase: Equatable { case noAccount, locked, list, configuration, working }

    @Published private(set) var phase: Phase = .locked
    @Published private(set) var rows: [AutofillRow] = []
    @Published var query = ""
    @Published var message: String?
    @Published private(set) var busy = false
    @Published private(set) var passkeyRows: [PasskeyRow] = []

    let client: KeepiqClient
    let biometric = BiometricKeychain()
    let accountId: String?
    let serviceIdentifiers: [String]
    private let record: String?
    private let onFill: (FilledLogin) -> Void
    let passkey: PasskeyRequest?
    private let onPasskey: (PasskeyResult) -> Void
    private var passkeyChoices: [PasskeyChoice] = []
    private var keys: VaultKeys?
    private var gate: UnlockGate?

    init(
        serviceIdentifiers: [String],
        record: String? = nil,
        configuration: Bool = false,
        passkey: PasskeyRequest? = nil,
        onPasskey: @escaping (PasskeyResult) -> Void = { _ in },
        onFill: @escaping (FilledLogin) -> Void
    ) {
        client = KeepiqClientKt.doNewKeepiqClient(storage: KeychainStorage(), clientName: "Keepiq AutoFill for iOS")
        accountId = client.accounts.activeId()
        self.serviceIdentifiers = serviceIdentifiers
        self.record = record
        self.onFill = onFill
        self.passkey = passkey
        self.onPasskey = onPasskey
        if accountId == nil {
            phase = .noAccount
        } else if configuration {
            phase = .configuration
        } else if let accountId, let kept = ExtensionSession.current(accountId) {
            keys = kept
            phase = .list
            afterUnlock()
        }
    }

    var hasPin: Bool { accountId.map { client.pins.has(accountId: $0) } ?? false }
    var hasBiometric: Bool { accountId.map { biometric.isEnabled($0) } ?? false }
    var site: String { serviceIdentifiers.first.map { AutofillSites.shared.hostOf(serviceIdentifier: $0) } ?? "" }

    // MARK: Unlock (design D4: the app's unlock, in the extension)

    func unlock(masterPassword: String) {
        open { accountId, suite in try await self.client.unlockWithMasterPassword(accountId: accountId, suite: suite, masterPassword: masterPassword) }
    }

    func unlock(pin: String) {
        open { accountId, suite in try await self.client.unlockWithPin(accountId: accountId, suite: suite, pin: pin) }
    }

    func unlockWithBiometric() {
        open { accountId, suite in
            let key = try await self.biometric.read(accountId)
            return try await self.client.unlockWithKeyBase64(accountId: accountId, suite: suite, unlockKey: key)
        }
    }

    private func open(_ unlock: @escaping (String, Suite) async throws -> UnlockedVault) {
        guard let accountId else { return }
        busy = true
        message = nil
        Task {
            defer { self.busy = false }
            do {
                let gate: UnlockGate
                if let known = self.gate {
                    gate = known
                } else {
                    gate = try await self.client.unlockGate(accountId: accountId)
                }
                self.gate = gate
                if let blocked = gate as? UnlockGate.Blocked {
                    self.message = blocked.message
                    return
                }
                guard let ready = gate as? UnlockGate.Ready else { return }
                let vault = try await unlock(accountId, ready.suite)
                let keys = try AutofillSites.shared.keysOf(vault: vault)
                vault.lock()
                self.keys = keys
                ExtensionSession.keep(keys, accountId: accountId, minutes: Int(self.client.accounts.settings(id: accountId).idleMinutes))
                self.phase = .list
                self.afterUnlock()
            } catch {
                self.message = Self.text(error)
            }
        }
    }

    private func afterUnlock() {
        if passkey != nil {
            answerPasskey()
            return
        }
        if let record, let filled = fill(record: record) {
            finish(filled)
            return
        }
        load()
    }

    // MARK: The list

    /// The logins for the asked sites, from their files only.
    func load() {
        guard let accountId else { return }
        var seen = Set<String>()
        var out: [AutofillRow] = []
        for identifier in serviceIdentifiers {
            let siteKey = AutofillSites.shared.siteKey(serviceIdentifier: identifier)
            guard let json = AutofillFiles.shared.read(accountId: accountId, site: siteKey) else { continue }
            for entry in AutofillSites.shared.matches(siteJson: json, serviceIdentifier: identifier) where seen.insert(entry.id).inserted {
                out.append(AutofillRow(id: entry.id, name: entry.name, site: siteKey, serviceIdentifier: identifier))
            }
        }
        rows = out
    }

    /// "Pick another login": every site file in turn, keeping only the matches.
    func search() {
        guard let accountId else { return }
        let needle = query.trimmingCharacters(in: .whitespaces)
        if needle.isEmpty { load(); return }
        var out: [AutofillRow] = []
        for site in AutofillFiles.shared.sites(accountId: accountId) {
            guard let json = AutofillFiles.shared.read(accountId: accountId, site: site) else { continue }
            for entry in AutofillSites.shared.search(siteJson: json, query: needle) {
                out.append(AutofillRow(id: entry.id, name: entry.name, site: site, serviceIdentifier: entry.url ?? site))
            }
            if out.count >= 50 { break }
        }
        rows = out
    }

    func choose(_ row: AutofillRow) {
        guard let filled = fill(itemId: row.id, site: row.site, serviceIdentifier: row.serviceIdentifier) else {
            message = AL("fill.failed")
            return
        }
        finish(filled)
    }

    /// The login of a record from the identity store, decrypted.
    func fill(record: String) -> FilledLogin? {
        guard let accountId, let itemId = AutofillSites.shared.itemId(recordIdentifier: record, accountId: accountId) else { return nil }
        for identifier in serviceIdentifiers {
            if let filled = fill(itemId: itemId, site: AutofillSites.shared.siteKey(serviceIdentifier: identifier), serviceIdentifier: identifier) {
                return filled
            }
        }
        return nil
    }

    private func fill(itemId: String, site: String, serviceIdentifier: String) -> FilledLogin? {
        guard let accountId, let keys,
              let json = AutofillFiles.shared.read(accountId: accountId, site: site),
              let entry = AutofillIndex.companion.fromJson(text: json).entries.first(where: { $0.id == itemId }) else { return nil }
        guard let user = try? AutofillSites.shared.decrypt(keys: keys, ciphertext: entry.login),
              let password = try? AutofillSites.shared.decrypt(keys: keys, ciphertext: entry.key) else { return nil }
        copyCode(json: json, serviceIdentifier: serviceIdentifier, keys: keys)
        return FilledLogin(user: user, password: password)
    }

    /// iOS 17 (task 4.5): the current code of the site's authenticator item
    /// on the clipboard, local only, gone after 60 seconds.
    private func copyCode(json: String, serviceIdentifier: String, keys: VaultKeys) {
        let now = Int64(Date().timeIntervalSince1970 * 1000)
        guard let entry = AutofillSites.shared.codeItems(siteJson: json, serviceIdentifier: serviceIdentifier).first,
              let code = AutofillSites.shared.code(keys: keys, entry: entry, nowMillis: now) else { return }
        UIPasteboard.general.setItems(
            [[UTType.plainText.identifier: code]],
            options: [.localOnly: true, .expirationDate: Date().addingTimeInterval(60)]
        )
    }

    private func finish(_ filled: FilledLogin) {
        phase = .working
        onFill(filled)
    }

    // MARK: Passkeys (task 5.2)

    /// After the unlock: sign at once when one passkey fits, else list them; or create one.
    private func answerPasskey() {
        guard let accountId, let keys, let passkey else { return }
        switch passkey {
        case .assertion(let rpId, _, let credentialId, let allowed):
            let json = AutofillFiles.shared.read(accountId: accountId, site: ApplePasskeys.shared.siteKey(rpId: rpId)) ?? "[]"
            let allow = credentialId.map { [$0.base64EncodedString()] } ?? allowed.map { $0.base64EncodedString() }
            passkeyChoices = ApplePasskeys.shared.choices(siteJson: json, rpId: rpId, keys: keys, allowed: allow)
            passkeyRows = passkeyChoices.map { PasskeyRow(id: $0.itemId, name: $0.name, user: $0.label) }
            if passkeyChoices.count == 1 { sign(passkeyChoices[0]) }
        case .registration(let rpId, let userName, let userHandle, let hash, let algorithms):
            register(accountId: accountId, keys: keys, rpId: rpId, userName: userName, userHandle: userHandle, hash: hash, algorithms: algorithms)
        }
    }

    func choosePasskey(_ row: PasskeyRow) {
        guard let choice = passkeyChoices.first(where: { $0.itemId == row.id }) else { return }
        sign(choice)
    }

    private func sign(_ choice: PasskeyChoice) {
        guard let accountId, let keys, let account = client.accounts.account(id: accountId),
              case .assertion(let rpId, let hash, _, _) = passkey else { return }
        busy = true
        message = nil
        phase = .working
        Task {
            defer { self.busy = false }
            do {
                let api = try self.client.api(account: account)
                let signed = try await ApplePasskeys.shared.signIn(api: api, keys: keys, choice: choice, clientDataHash: hash.base64EncodedString(), rpId: rpId)
                self.onPasskey(.assertion(signed, rpId: rpId, clientDataHash: hash))
            } catch {
                self.message = AL("passkey.failed") + " " + Self.text(error)
                self.phase = .list
            }
        }
    }

    private func register(accountId: String, keys: VaultKeys, rpId: String, userName: String, userHandle: Data, hash: Data, algorithms: [Int]) {
        guard let account = client.accounts.account(id: accountId) else { return }
        busy = true
        message = nil
        phase = .working
        Task {
            defer { self.busy = false }
            do {
                let api = try self.client.api(account: account)
                let json = AutofillFiles.shared.read(accountId: accountId, site: ApplePasskeys.shared.siteKey(rpId: rpId)) ?? "[]"
                let made = try await ApplePasskeys.shared.register(
                    api: api,
                    keys: keys,
                    siteJson: json,
                    rpId: rpId,
                    userName: userName,
                    userHandle: userHandle.base64EncodedString(),
                    clientDataHash: hash.base64EncodedString(),
                    algorithms: algorithms.map { KotlinLong(value: Int64($0)) },
                    nowMillis: Int64(Date().timeIntervalSince1970 * 1000)
                )
                self.onPasskey(.registration(made, rpId: rpId, clientDataHash: hash, userHandle: userHandle))
            } catch {
                self.message = AL("passkey.saveFailed") + " " + Self.text(error)
                self.phase = .list
            }
        }
    }

    // MARK: Add login (iOS has no save hook for a third-party provider)

    func addLogin(user: String, password: String) {
        guard let accountId, let keys, let account = client.accounts.account(id: accountId) else { return }
        let host = site
        busy = true
        message = nil
        Task {
            defer { self.busy = false }
            do {
                let api = try self.client.api(account: account)
                let json = AutofillFiles.shared.read(accountId: accountId, site: AutofillSites.shared.siteKey(serviceIdentifier: host)) ?? "[]"
                let result = try await AutofillSaver(api: api, keys: keys).save(
                    target: AutofillTarget.Web(host: host),
                    index: AutofillIndex.companion.fromJson(text: json),
                    login: user,
                    password: password,
                    appLabel: nil,
                    webScheme: nil
                )
                if result == SaveResult.refused {
                    self.message = AL("add.refused")
                } else {
                    self.finish(FilledLogin(user: user, password: password))
                }
            } catch {
                self.message = Self.text(error)
            }
        }
    }

    static func text(_ error: Error) -> String {
        if let kotlin = (error as NSError).userInfo["KotlinException"] as? KotlinThrowable, let message = kotlin.message {
            return message
        }
        return error.localizedDescription
    }
}
