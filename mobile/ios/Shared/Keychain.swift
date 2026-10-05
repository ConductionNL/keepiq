// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import Foundation
import KeepiqShared
import LocalAuthentication
import Security

/// The app group the app and its AutoFill extension share (design D4: one
/// unlock for both). Its id is also their shared Keychain access group.
enum SharedGroup {
    static let id = "group.nl.conduction.keepiq"

    /// The shared Keychain access group, or nil in a build without the
    /// entitlement (the unsigned compile check in CI): items then stay in
    /// the app's own group and the extension cannot read them.
    static let keychainGroup: String? = {
        let base: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: "nl.conduction.keepiq.probe",
            kSecAttrAccount as String: "probe",
            kSecAttrAccessGroup as String: id,
        ]
        SecItemDelete(base as CFDictionary)
        var add = base
        add[kSecValueData as String] = Data([1])
        add[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        let status = SecItemAdd(add as CFDictionary, nil)
        SecItemDelete(base as CFDictionary)
        return status == errSecSuccess ? id : nil
    }()

    /// Adds the shared access group to a Keychain query when there is one.
    static func scoped(_ query: [String: Any]) -> [String: Any] {
        guard let group = keychainGroup else { return query }
        var q = query
        q[kSecAttrAccessGroup as String] = group
        return q
    }

    /// The shared container, or nil without the app group entitlement.
    static var container: URL? { FileManager.default.containerURL(forSecurityApplicationGroupIdentifier: id) }
}

/// The shared core's `SecureStorage` on iOS (design D4): one Keychain item
/// per key, readable after the first unlock of the phone and never restored
/// onto another device. App and AutoFill extension share the items.
final class KeychainStorage: NSObject, SecureStorage {
    static let service = "nl.conduction.keepiq.storage"

    private func query(_ key: String) -> [String: Any] {
        SharedGroup.scoped([
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: Self.service,
            kSecAttrAccount as String: key,
        ])
    }

    func read(key: String) -> String? {
        var q = query(key)
        q[kSecReturnData as String] = true
        q[kSecMatchLimit as String] = kSecMatchLimitOne
        var out: CFTypeRef?
        guard SecItemCopyMatching(q as CFDictionary, &out) == errSecSuccess, let data = out as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }

    func write(key: String, value: String) {
        let data = Data(value.utf8)
        let update: [String: Any] = [kSecValueData as String: data]
        if SecItemUpdate(query(key) as CFDictionary, update as CFDictionary) == errSecItemNotFound {
            var add = query(key)
            add[kSecValueData as String] = data
            add[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
            SecItemAdd(add as CFDictionary, nil)
        }
    }

    func delete(key: String) {
        SecItemDelete(query(key) as CFDictionary)
    }

    /// Deletes every item of the app, for a clean start in the UI tests.
    static func deleteAll() {
        for service in [service, BiometricKeychain.service] {
            SecItemDelete(SharedGroup.scoped([kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service]) as CFDictionary)
        }
    }
}

enum BiometricError: LocalizedError {
    case unavailable
    case cancelled
    case invalidated

    var errorDescription: String? {
        switch self {
        case .unavailable: return "Set up Face ID or Touch ID in the phone settings first."
        case .cancelled: return "Biometric unlock cancelled."
        case .invalidated:
            return "A face or fingerprint was added to this phone. Unlock with your master password, then turn biometric unlock on again."
        }
    }
}

/// Biometric unlock on iOS (design D4): the 32-byte unlock key in a Keychain
/// item with `kSecAttrAccessibleWhenUnlockedThisDeviceOnly` and
/// `.biometryCurrentSet`, so enrolling a new face or finger makes it
/// unreadable.
struct BiometricKeychain {
    static let service = "nl.conduction.keepiq.biometric"

    func canUse() -> Bool {
        LAContext().canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: nil)
    }

    private func base(_ accountId: String) -> [String: Any] {
        SharedGroup.scoped([
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: Self.service,
            kSecAttrAccount as String: accountId,
        ])
    }

    /// Whether a wrap exists, without showing a prompt.
    func isEnabled(_ accountId: String) -> Bool {
        let context = LAContext()
        context.interactionNotAllowed = true
        var q = base(accountId)
        q[kSecUseAuthenticationContext as String] = context
        let status = SecItemCopyMatching(q as CFDictionary, nil)
        return status == errSecSuccess || status == errSecInteractionNotAllowed
    }

    func save(_ accountId: String, unlockKey: String) throws {
        guard canUse() else { throw BiometricError.unavailable }
        delete(accountId)
        var error: Unmanaged<CFError>?
        guard let access = SecAccessControlCreateWithFlags(
            nil, kSecAttrAccessibleWhenUnlockedThisDeviceOnly, .biometryCurrentSet, &error
        ) else { throw BiometricError.unavailable }
        var add = base(accountId)
        add[kSecValueData as String] = Data(unlockKey.utf8)
        add[kSecAttrAccessControl as String] = access
        guard SecItemAdd(add as CFDictionary, nil) == errSecSuccess else { throw BiometricError.unavailable }
    }

    /// The unlock key, after Face ID or Touch ID.
    func read(_ accountId: String) async throws -> String {
        let query = base(accountId)
        return try await Task.detached {
            let context = LAContext()
            context.localizedReason = "Unlock Keepiq"
            var q = query
            q[kSecReturnData as String] = true
            q[kSecMatchLimit as String] = kSecMatchLimitOne
            q[kSecUseAuthenticationContext as String] = context
            var out: CFTypeRef?
            let status = SecItemCopyMatching(q as CFDictionary, &out)
            if status == errSecUserCanceled { throw BiometricError.cancelled }
            guard status == errSecSuccess, let data = out as? Data, let text = String(data: data, encoding: .utf8) else {
                SecItemDelete(query as CFDictionary)
                throw BiometricError.invalidated
            }
            return text
        }.value
    }

    func delete(_ accountId: String) {
        SecItemDelete(base(accountId) as CFDictionary)
    }
}
