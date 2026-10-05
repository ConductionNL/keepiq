// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import AuthenticationServices
import Foundation
import KeepiqShared
import UIKit

/// Where the app is.
enum AppScreen {
    case pair
    case unlock(String)
    /// The vault is open; the vault screens read and write through the session.
    case unlocked(UnlockedVault, MobileSession)
    case settings(UnlockedVault)
}

/// The app's state and the actions the views call (tasks 2.1 to 2.6). All of
/// it runs on the main actor, which is also where Kotlin's suspend functions
/// are called from.
@MainActor
final class AppModel: ObservableObject {
    @Published private(set) var screen: AppScreen = .pair
    @Published var message: String?
    @Published private(set) var busy = false
    @Published private(set) var gate: UnlockGate?
    @Published private(set) var waiting: LoginFlowStart?
    @Published private(set) var maxIdle: Int?
    /// Bumped after a settings change, so views re-read the stores.
    @Published private(set) var revision = 0

    let client: KeepiqClient
    let biometric = BiometricKeychain()
    private let browser = BrowserLogin()
    private var loginTask: Task<Void, Never>?
    private var idleTask: Task<Void, Never>?
    private var lastActivity = Date()
    /// The open vault's session, closed on lock.
    private var session: MobileSession?
    /// UI tests replace the browser: the replayed server grants on its own.
    private let noBrowser: Bool

    init() {
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("-keepiq-reset") { KeychainStorage.deleteAll() }
        noBrowser = ProcessInfo.processInfo.environment["KEEPIQ_UITEST_NO_BROWSER"] == "1"
        #else
        noBrowser = false
        #endif
        client = KeepiqClientKt.doNewKeepiqClient(storage: KeychainStorage(), clientName: "Keepiq for iOS (\(UIDevice.current.model))")
        let biometric = self.biometric
        client.onWipe = { accountId in biometric.delete(accountId) }
        if let active = client.accounts.activeId() { screen = .unlock(active) }
        NotificationCenter.default.addObserver(
            forName: UIApplication.protectedDataWillBecomeUnavailableNotification, object: nil, queue: .main
        ) { [weak self] _ in
            // The phone locked (design D4): lock the vault with it.
            MainActor.assumeIsolated { self?.lock() }
        }
    }

    var accounts: [Account] { client.accounts.accounts() }

    func account(_ id: String) -> Account? { client.accounts.account(id: id) }

    // MARK: Pairing (2.1, 2.4)

    func startLogin(server: String) {
        perform {
            let start = try await self.client.startLogin(serverInput: server)
            self.waiting = start
            if !self.noBrowser, let url = URL(string: start.loginUrl) {
                self.browser.open(url) { [weak self] in self?.browserClosed() }
            }
            self.loginTask = Task { [weak self] in
                guard let self else { return }
                do {
                    let account = try await self.client.finishLogin(start: start)
                    self.loginDone(account)
                } catch {
                    self.waiting = nil
                    self.browser.close()
                    if !Self.isCancellation(error) { self.message = Self.text(error) }
                }
            }
        }
    }

    /// The user closed the browser sheet: one last poll, then the form again.
    private func browserClosed() {
        guard let start = waiting else { return }
        loginTask?.cancel()
        client.cancelLogin()
        perform {
            let account = try await self.client.finishLoginNow(start: start)
            self.waiting = nil
            if let account { self.loginDone(account) }
        }
    }

    func cancelLogin() {
        loginTask?.cancel()
        client.cancelLogin()
        waiting = nil
        browser.close()
    }

    private func loginDone(_ account: Account) {
        waiting = nil
        browser.close()
        showUnlock(account.accountId)
    }

    func pairManually(server: String, loginName: String, appPassword: String) {
        perform {
            let account = try await self.client.pairManually(serverInput: server, loginName: loginName, appPassword: appPassword)
            self.showUnlock(account.accountId)
        }
    }

    func addAccount() {
        lock()
        message = nil
        screen = .pair
    }

    func switchAccount(_ id: String) {
        lock()
        try? client.accounts.setActive(id: id)
        showUnlock(id)
    }

    // MARK: Unlock (2.2, 2.3)

    private func showUnlock(_ accountId: String) {
        gate = nil
        message = nil
        screen = .unlock(accountId)
        refreshGate(accountId)
    }

    func refreshGate(_ accountId: String) {
        perform { self.gate = try await self.client.unlockGate(accountId: accountId) }
    }

    func unlock(_ accountId: String, masterPassword: String) {
        withSuite(accountId) { suite in
            try await self.client.unlockWithMasterPassword(accountId: accountId, suite: suite, masterPassword: masterPassword)
        }
    }

    func unlock(_ accountId: String, pin: String) {
        withSuite(accountId) { suite in
            try await self.client.unlockWithPin(accountId: accountId, suite: suite, pin: pin)
        }
    }

    func unlockWithBiometric(_ accountId: String) {
        withSuite(accountId) { suite in
            let key = try await self.biometric.read(accountId)
            do {
                return try await self.client.unlockWithKeyBase64(accountId: accountId, suite: suite, unlockKey: key)
            } catch {
                if (error as NSError).userInfo["KotlinException"] is StaleUnlockKeyException { self.biometric.delete(accountId) }
                throw error
            }
        }
    }

    private func withSuite(_ accountId: String, _ open: @escaping (Suite) async throws -> UnlockedVault) {
        perform {
            let current: UnlockGate
            if let gate = self.gate {
                current = gate
            } else {
                current = try await self.client.unlockGate(accountId: accountId)
            }
            self.gate = current
            if let blocked = current as? UnlockGate.Blocked {
                self.message = blocked.message
                return
            }
            guard let ready = current as? UnlockGate.Ready else { return }
            let vault = try await open(ready.suite)
            let session: MobileSession
            do {
                guard let account = self.account(accountId) else { throw AppError.accountGone }
                session = try MobileSession.companion.forVault(account: account, vault: vault)
            } catch {
                vault.lock()
                throw error
            }
            self.session = session
            self.message = nil
            self.screen = .unlocked(vault, session)
            if let max = try? await self.client.maxIdleMinutes(accountId: accountId) {
                self.maxIdle = max.intValue
            } else {
                self.maxIdle = nil
            }
            self.touch()
            self.startIdleWatch()
        }
    }

    // MARK: Unlock options (2.3)

    func enableBiometric(_ vault: UnlockedVault) {
        perform {
            try self.biometric.save(vault.accountId, unlockKey: vault.unlockKeyBase64())
            self.revision += 1
        }
    }

    func disableBiometric(_ vault: UnlockedVault) {
        biometric.delete(vault.accountId)
        revision += 1
    }

    func setPin(_ vault: UnlockedVault, pin: String) {
        perform {
            try await self.client.setPin(vault: vault, pin: pin)
            self.revision += 1
        }
    }

    func removePin(_ vault: UnlockedVault) {
        client.pins.remove(accountId: vault.accountId)
        revision += 1
    }

    // MARK: Locking (2.5)

    func idleChoice(_ accountId: String) -> Int { Int(client.accounts.settings(id: accountId).idleMinutes) }

    func setIdle(_ vault: UnlockedVault, minutes: Int) {
        let current = client.accounts.settings(id: vault.accountId)
        try? client.accounts.updateSettings(id: vault.accountId, settings: current.doCopy(idleMinutes: Int32(minutes), biometric: current.biometric))
        revision += 1
    }

    func effectiveIdle(_ accountId: String) -> Int {
        let max = maxIdle.map { KotlinInt(int: Int32($0)) }
        return Int(IdlePolicy.shared.effectiveMinutes(choice: Int32(idleChoice(accountId)), organisationMax: max))
    }

    func offeredIdleChoices() -> [Int] {
        let max = maxIdle.map { KotlinInt(int: Int32($0)) }
        return IdlePolicy.shared.offeredChoices(organisationMax: max).map { $0.intValue }
    }

    /// Any interaction restarts the idle time.
    func touch() { lastActivity = Date() }

    func cameToForeground() {
        if let accountId = unlockedAccountId, Date().timeIntervalSince(lastActivity) >= Double(effectiveIdle(accountId) * 60) {
            lock()
        } else if unlockedAccountId != nil {
            startIdleWatch()
        }
    }

    private var unlockedAccountId: String? {
        switch screen {
        case .unlocked(let vault, _), .settings(let vault): return vault.accountId
        default: return nil
        }
    }

    private func startIdleWatch() {
        idleTask?.cancel()
        idleTask = Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: 5_000_000_000)
                guard let self, let accountId = self.unlockedAccountId else { return }
                if Date().timeIntervalSince(self.lastActivity) >= Double(self.effectiveIdle(accountId) * 60) {
                    self.lock()
                    return
                }
            }
        }
    }

    /// Locks now: the session forgets the private key, the unlock key is
    /// overwritten, and the unlock screen shows. `reason` is shown there, for
    /// a lock the user did not ask for.
    func lock(reason: String? = nil) {
        let vault: UnlockedVault
        switch screen {
        case .unlocked(let v, _), .settings(let v): vault = v
        default: return
        }
        idleTask?.cancel()
        closeSession()
        vault.lock()
        showUnlock(vault.accountId)
        if let reason { message = reason }
    }

    private func closeSession() {
        session?.close()
        session = nil
    }

    func openSettings(_ vault: UnlockedVault) { touch(); screen = .settings(vault) }

    func closeSettings(_ vault: UnlockedVault) {
        touch()
        guard let session else { return lock() }
        screen = .unlocked(vault, session)
    }

    // MARK: Unpair (2.6)

    func unpair(_ accountId: String) {
        closeSession()
        if case .unlocked(let v, _) = screen { v.lock() }
        if case .settings(let v) = screen { v.lock() }
        idleTask?.cancel()
        perform {
            let revoked = try await self.client.unpair(accountId: accountId).boolValue
            self.gate = nil
            if let active = self.client.accounts.activeId() { self.screen = .unlock(active); self.refreshGate(active) } else { self.screen = .pair }
            self.message = revoked
                ? "Disconnected. The app password was deleted in Nextcloud."
                : "Disconnected on this phone. Nextcloud could not be reached, so delete the app password there under Security."
        }
    }

    // MARK: Helpers

    /// Every action restarts the idle time. A tap gesture over the whole app
    /// would do it for plain taps too, but it swallows the taps of Form
    /// buttons on iOS 18.
    private func perform(_ work: @escaping () async throws -> Void) {
        touch()
        busy = true
        message = nil
        Task {
            defer { self.busy = false }
            do {
                try await work()
            } catch {
                if !Self.isCancellation(error) { self.message = Self.text(error) }
            }
        }
    }

    /// The Kotlin message of a core error, or the Swift one.
    static func text(_ error: Error) -> String {
        if let kotlin = (error as NSError).userInfo["KotlinException"] as? KotlinThrowable, let message = kotlin.message {
            return message
        }
        return error.localizedDescription
    }

    static func isCancellation(_ error: Error) -> Bool {
        if error is CancellationError { return true }
        if case BiometricError.cancelled = error { return true }
        if let stopped = (error as NSError).userInfo["KotlinException"] as? LoginFlowStoppedException { return !stopped.timedOut }
        return false
    }
}

enum AppError: LocalizedError {
    case accountGone

    var errorDescription: String? { "This account is gone from this phone." }
}

/// The login page in the system browser sheet (design D3). Nextcloud's
/// grant page does not call back, so the app closes the sheet itself when
/// polling found the app password.
final class BrowserLogin: NSObject, ASWebAuthenticationPresentationContextProviding {
    private var session: ASWebAuthenticationSession?
    private var closedByApp = false

    func open(_ url: URL, onClosed: @escaping @MainActor () -> Void) {
        closedByApp = false
        let session = ASWebAuthenticationSession(url: url, callbackURLScheme: "keepiq") { [weak self] _, _ in
            guard let self, !self.closedByApp else { return }
            Task { @MainActor in onClosed() }
        }
        session.presentationContextProvider = self
        session.prefersEphemeralWebBrowserSession = false
        self.session = session
        session.start()
    }

    func close() {
        closedByApp = true
        session?.cancel()
        session = nil
    }

    func presentationAnchor(for session: ASWebAuthenticationSession) -> ASPresentationAnchor {
        UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .flatMap { $0.windows }
            .first { $0.isKeyWindow } ?? ASPresentationAnchor()
    }
}
