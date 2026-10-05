// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import AuthenticationServices
import Combine
import CryptoKit
import Foundation
import KeepiqShared

/// Whether Keepiq is the AutoFill provider. UI tests that show the
/// extension's screens inside the app count as on, since a simulator test
/// cannot switch the provider on in Settings.
enum AutofillState {
    static func isEnabled() async -> Bool {
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("-keepiq-autofill-preview") { return true }
        #endif
        return await withCheckedContinuation { continuation in
            ASCredentialIdentityStore.shared.getState { state in continuation.resume(returning: state.isEnabled) }
        }
    }
}

/// The per-site index files the AutoFill extension reads (task 4.4): one
/// small file per site in the shared container, sealed with AES-GCM under
/// a key in the shared Keychain, so the extension reads only the sites it
/// is asked about and never the whole vault. They hold names, addresses
/// and the item ciphertext the server sent, never a plaintext secret.
final class AutofillFiles {
    static let shared = AutofillFiles()
    private let storage = KeychainStorage()
    private let keyName = "autofill:files-key"

    /// True when the files are in the shared container, so the extension
    /// reads them. A build without the app group entitlement (an unsigned
    /// simulator build) keeps them in the app's own Application Support:
    /// the app's in-app preview still works, the extension sees nothing.
    var isShared: Bool { SharedGroup.container != nil }

    private var dir: URL? {
        let base = SharedGroup.container
            ?? FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first
        guard let base else { return nil }
        let url = base.appendingPathComponent("autofill", isDirectory: true)
        try? FileManager.default.createDirectory(at: url, withIntermediateDirectories: true)
        return url
    }

    private func key() -> SymmetricKey {
        if let text = storage.read(key: keyName), let data = Data(base64Encoded: text) { return SymmetricKey(data: data) }
        let key = SymmetricKey(size: .bits256)
        storage.write(key: keyName, value: key.withUnsafeBytes { Data($0) }.base64EncodedString())
        return key
    }

    private static func hex(_ text: String) -> String { Data(text.utf8).map { String(format: "%02x", $0) }.joined() }

    private static func unhex(_ text: String) -> String? {
        var bytes = [UInt8]()
        var index = text.startIndex
        while index < text.endIndex {
            let next = text.index(index, offsetBy: 2, limitedBy: text.endIndex) ?? text.endIndex
            guard let byte = UInt8(text[index..<next], radix: 16) else { return nil }
            bytes.append(byte)
            index = next
        }
        return String(bytes: bytes, encoding: .utf8)
    }

    private func prefix(_ accountId: String) -> String { Self.hex(accountId) + "-" }

    /// Replaces the files of an account with [files] (site key to entries JSON).
    func write(accountId: String, files: [String: String]) {
        clear(accountId: accountId)
        guard let dir else { return }
        let key = key()
        for (site, json) in files {
            guard let sealed = try? AES.GCM.seal(Data(json.utf8), using: key).combined else { continue }
            let url = dir.appendingPathComponent(prefix(accountId) + Self.hex(site) + ".bin")
            try? sealed.write(to: url, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
        }
    }

    /// The entries JSON of one site, or nil.
    func read(accountId: String, site: String) -> String? {
        guard let dir, !site.isEmpty else { return nil }
        let url = dir.appendingPathComponent(prefix(accountId) + Self.hex(site) + ".bin")
        guard let data = try? Data(contentsOf: url),
              let box = try? AES.GCM.SealedBox(combined: data),
              let plain = try? AES.GCM.open(box, using: key()) else { return nil }
        return String(data: plain, encoding: .utf8)
    }

    /// The site keys an account has files for.
    func sites(accountId: String) -> [String] {
        guard let dir, let names = try? FileManager.default.contentsOfDirectory(atPath: dir.path) else { return [] }
        let p = prefix(accountId)
        return names.filter { $0.hasPrefix(p) && $0.hasSuffix(".bin") }
            .compactMap { Self.unhex(String($0.dropFirst(p.count).dropLast(4))) }
            .sorted()
    }

    func clear(accountId: String) {
        guard let dir, let names = try? FileManager.default.contentsOfDirectory(atPath: dir.path) else { return }
        let p = prefix(accountId)
        for name in names where name.hasPrefix(p) { try? FileManager.default.removeItem(at: dir.appendingPathComponent(name)) }
    }

    func clearAll() {
        guard let dir else { return }
        try? FileManager.default.removeItem(at: dir)
    }
}

/// The autofill index on iOS (tasks 4.4 and 4.6): the per-site files for
/// the extension, and ASCredentialIdentityStore with the site and user name
/// of each login, never a password, and each passkey's rpId, user name,
/// credential id and user handle (task 5.2), never its key. Rebuilt after every sync, cleared on
/// unpair, on a suite change and when Keepiq is not the provider. With
/// offline caching off nothing is written: the extension cannot see the
/// app's memory, so it offers nothing then.
final class IOSAutofillIndex: NSObject, AutofillIndexSink, ObservableObject {
    static let shared = IOSAutofillIndex()

    /// What the last rebuild wrote, for the app's own check in UI tests.
    @Published private(set) var lastIdentityCount = 0
    @Published private(set) var lastSiteCount = 0
    @Published private(set) var lastPasskeyCount = 0

    private func publish(sites: Int, identities: Int, passkeys: Int = 0) {
        DispatchQueue.main.async {
            self.lastSiteCount = sites
            self.lastIdentityCount = identities
            self.lastPasskeyCount = passkeys
        }
    }

    func replace(accountId: String, index: AutofillIndex, persist: Bool, keys: VaultKeys) {
        guard persist else { clear(accountId: accountId); return }
        let files = AutofillSites.shared.split(index: index)
        let identities = AutofillSites.shared.identities(accountId: accountId, index: index, keys: keys)
        // Passkeys (task 5.2): rpId, user name, credential id and user handle, never the key.
        let passkeys = ApplePasskeys.shared.identities(accountId: accountId, index: index, keys: keys)
        AutofillFiles.shared.write(accountId: accountId, files: files)
        publish(sites: files.count, identities: identities.count, passkeys: passkeys.count)
        var store: [ASCredentialIdentity] = identities.map {
            ASPasswordCredentialIdentity(
                serviceIdentifier: ASCredentialServiceIdentifier(identifier: $0.host, type: .domain),
                user: $0.user,
                recordIdentifier: $0.recordIdentifier
            )
        }
        store += passkeys.compactMap { p -> ASCredentialIdentity? in
            guard let credentialId = Data(base64Encoded: p.credentialId) else { return nil }
            return ASPasskeyCredentialIdentity(
                relyingPartyIdentifier: p.rpId,
                userName: p.userName,
                credentialID: credentialId,
                userHandle: Data(base64Encoded: p.userHandle) ?? Data(),
                recordIdentifier: p.recordIdentifier
            )
        }
        Task {
            guard await AutofillState.isEnabled() else {
                AutofillFiles.shared.clear(accountId: accountId)
                return
            }
            ASCredentialIdentityStore.shared.replaceCredentialIdentities(store) { _, _ in }
        }
    }

    func clear(accountId: String) {
        AutofillFiles.shared.clear(accountId: accountId)
        publish(sites: 0, identities: 0)
        ASCredentialIdentityStore.shared.removeAllCredentialIdentities { _, _ in }
    }
}
