// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import CryptoKit
import KeepiqShared
import SwiftUI

@main
struct KeepiqApp: App {
    @StateObject private var model = AppModel()
    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(model)
        }
        .onChange(of: scenePhase) { _, phase in
            if phase == .active { model.cameToForeground() }
        }
    }
}

/// One screen at a time (tasks 2.1 to 2.6).
struct RootView: View {
    @EnvironmentObject private var model: AppModel

    var body: some View {
        screens
        #if DEBUG
        .overlay(alignment: .bottom) { AutofillPreviewButton() }
        #endif
    }

    @ViewBuilder private var screens: some View {
        switch model.screen {
        case .unlocked(let vault, let session):
            // The vault brings its own tabs and navigation stacks.
            VaultAppView(
                session: session,
                accounts: model.accounts,
                onSwitchAccount: { model.switchAccount($0.accountId) },
                onAddAccount: { model.addAccount() },
                onLock: { model.lock() },
                onSettings: { model.openSettings(vault) },
                onLocked: { model.lock(reason: $0) }
            )
            .id(ObjectIdentifier(session))
        default:
            NavigationStack {
                switch model.screen {
                case .pair: PairView()
                case .unlock(let accountId): UnlockView(accountId: accountId)
                case .settings(let vault): SettingsView(vault: vault)
                case .unlocked: EmptyView()
                }
            }
        }
    }
}

#if DEBUG
/// UI tests only (-keepiq-autofill-preview): the AutoFill extension's screens
/// inside the app, since a simulator test cannot pick Keepiq as the
/// provider in Settings. The model, files and Keychain are the extension's.
struct AutofillPreviewButton: View {
    @EnvironmentObject private var model: AppModel
    @ObservedObject private var index = IOSAutofillIndex.shared
    @State private var preview: AutofillModel?
    @State private var filled: String?

    var body: some View {
        if ProcessInfo.processInfo.arguments.contains("-keepiq-autofill-preview"), case .unlocked(let vault, _) = model.screen {
            VStack {
                if let filled { Text(filled).accessibilityIdentifier("autofillFilled") }
                Text(indexLine(vault.accountId))
                    .accessibilityIdentifier("autofillIndex")
                Button("AutoFill preview") {
                    preview = AutofillModel(serviceIdentifiers: [site]) { login in
                        filled = "filled: \(login.user) / \(login.password.count)"
                        preview = nil
                        self.model.refreshAutofill(vault)
                    }
                }
                .accessibilityIdentifier("autofillPreview")
                if let rp = passkeyRp { passkeyButtons(rp, vault) }
            }
            .padding()
            .sheet(item: Binding(get: { preview.map(PreviewBox.init) }, set: { if $0 == nil { preview = nil } })) { box in
                AutofillRootView(model: box.model, onCancel: { preview = nil })
            }
        }
    }

    private var site: String { ProcessInfo.processInfo.environment["KEEPIQ_AUTOFILL_SITE"] ?? "example.com" }

    // MARK: Passkeys (task 5.2): the extension's passkey screens and calls, inside the app

    @State private var passkeyLine = "no passkey yet"
    @State private var passkeyKey: P256.Signing.PublicKey?
    private var passkeyRp: String? { ProcessInfo.processInfo.environment["KEEPIQ_PASSKEY_RP"] }

    /// A clientDataJSON as Safari would build it, and its hash, which is all the extension gets.
    private func clientDataHash(_ type: String, _ rp: String) -> Data {
        let json = #"{"type":"\#(type)","challenge":"\#(UUID().uuidString)","origin":"https://\#(rp)","crossOrigin":false}"#
        return Data(SHA256.hash(data: Data(json.utf8)))
    }

    @ViewBuilder private func passkeyButtons(_ rp: String, _ vault: UnlockedVault) -> some View {
        Text(passkeyLine).accessibilityIdentifier("passkeyResult")
        Text(passkeyIndexLine(vault.accountId, rp)).accessibilityIdentifier("passkeyIndex")
        HStack {
            Button("Create passkey") {
                let request = PasskeyRequest.registration(
                    rpId: rp,
                    userName: "passkey.demo@example.test",
                    userHandle: Data("e2e-user-1".utf8),
                    clientDataHash: clientDataHash("webauthn.create", rp),
                    algorithms: [-7, -257]
                )
                preview = AutofillModel(serviceIdentifiers: [rp], passkey: request, onPasskey: { result in
                    passkeyLine = describe(result)
                    preview = nil
                    self.model.refreshAutofill(vault)
                }, onFill: { _ in })
            }
            .accessibilityIdentifier("passkeyCreatePreview")
            Button("Sign in with passkey") {
                let request = PasskeyRequest.assertion(rpId: rp, clientDataHash: clientDataHash("webauthn.get", rp), credentialId: nil, allowed: [])
                preview = AutofillModel(serviceIdentifiers: [rp], passkey: request, onPasskey: { result in
                    passkeyLine = describe(result)
                    preview = nil
                    self.model.refreshAutofill(vault)
                }, onFill: { _ in })
            }
            .accessibilityIdentifier("passkeySignInPreview")
        }
    }

    /// What iOS would get back, checked as a relying party would: the
    /// attestation's flags and key, then the signature with that key.
    private func describe(_ result: PasskeyResult) -> String {
        switch result {
        case .registration(let made, let rp, _, _):
            guard let att = Data(base64Encoded: made.attestationObject), att.count >= 30 + 55 + 16 + 77 else { return "registration unreadable" }
            let auth = [UInt8](att.dropFirst(30))
            let rpHash = [UInt8](SHA256.hash(data: Data(rp.utf8)))
            let idLength = Int(auth[53]) << 8 | Int(auth[54])
            let cose = Array(auth[(55 + idLength)...])
            guard cose.count >= 77, let key = try? P256.Signing.PublicKey(rawRepresentation: Data(cose[10..<42] + cose[45..<77])) else { return "registration: no key" }
            passkeyKey = key
            let ok = Array(auth[0..<32]) == rpHash && auth[32] == 0x45 && auth[37..<53].allSatisfy { $0 == 0 } && idLength == 16
            return "registered: rp \(Array(auth[0..<32]) == rpHash), flags \(String(format: "0x%02x", auth[32])), id \(idLength) bytes, checks \(ok)"
        case .assertion(let signed, let rp, let hash):
            guard let key = passkeyKey,
                  let auth = Data(base64Encoded: signed.authenticatorData),
                  let der = Data(base64Encoded: signed.signature),
                  let signature = try? P256.Signing.ECDSASignature(derRepresentation: der) else { return "signed in: cannot verify" }
            let verified = key.isValidSignature(signature, for: auth + hash)
            let bytes = [UInt8](auth)
            let counter = bytes[33..<37].reduce(0) { $0 << 8 | Int($1) }
            let rpOk = Array(bytes[0..<32]) == [UInt8](SHA256.hash(data: Data(rp.utf8)))
            return "signed in: verified \(verified), rp \(rpOk), flags \(String(format: "0x%02x", bytes[32])), counter \(counter), user \(Data(base64Encoded: signed.userHandle).map { String(decoding: $0, as: UTF8.self) } ?? "")"
        }
    }

    /// The passkeys in the site file on disk, and in the identity store's last rebuild.
    private func passkeyIndexLine(_ accountId: String, _ rp: String) -> String {
        let key = ApplePasskeys.shared.siteKey(rpId: rp)
        let count = AutofillFiles.shared.read(accountId: accountId, site: key)
            .map { AutofillIndex.companion.fromJson(text: $0).entries.filter { $0.isPasskey }.count } ?? 0
        return "\(key): \(count) passkeys on disk, passkey identities \(index.lastPasskeyCount)"
    }

    /// What is on disk for the asked site, read back from its file, not counted on the way in.
    private func indexLine(_ accountId: String) -> String {
        let key = AutofillSites.shared.siteKey(serviceIdentifier: site)
        let logins = AutofillFiles.shared.read(accountId: accountId, site: key)
            .map { AutofillIndex.companion.fromJson(text: $0).entries.count } ?? 0
        let where_ = AutofillFiles.shared.isShared ? "shared" : "app only"
        return "\(key): \(logins) logins on disk (\(where_)), sites \(index.lastSiteCount), identities \(index.lastIdentityCount)"
    }

    private struct PreviewBox: Identifiable {
        let model: AutofillModel
        var id: ObjectIdentifier { ObjectIdentifier(model) }
    }
}
#endif

/// The last problem, read out by VoiceOver when it appears.
struct ProblemText: View {
    let message: String?

    var body: some View {
        if let message {
            Text(message)
                .foregroundStyle(.red)
                .accessibilityIdentifier("message")
                .accessibilityAddTraits(.updatesFrequently)
        }
    }
}

extension String {
    var withoutScheme: String { replacingOccurrences(of: "https://", with: "") }
}
