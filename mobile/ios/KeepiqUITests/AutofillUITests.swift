// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import CryptoKit
import XCTest

/// Task group 4 on the iOS simulator, as far as a simulator test reaches.
/// Picking Keepiq as the AutoFill provider happens in Settings and cannot be
/// automated, so the app shows the extension's own screens (the same model,
/// files and Keychain) under -keepiq-autofill-preview, against the replay:
///
/// - the unlock inside the AutoFill sheet, with the master password;
/// - "Add login" for keepiq-autofill.test, saved encrypted to the suite key and
///   filled in at once;
/// - the index rebuilt after the vault refresh: one site file and one
///   identity for ASCredentialIdentityStore;
/// - the sheet again, without a new unlock, listing that login from the
///   site file and filling it.
///
/// One-time codes on iOS 17 (task 4.5), on webmail.example.com, where the
/// seeded vault holds a login and an authenticator item: filling the login
/// puts the authenticator's current code on the clipboard. A site with no
/// authenticator item leaves the clipboard alone.
///
/// Manual: Keepiq in the QuickType bar of Safari and an app, and Face ID in
/// the sheet.
final class AutofillUITests: XCTestCase {
    private var app: XCUIApplication!
    private let env = ProcessInfo.processInfo.environment
    private var server: String { env["KEEPIQ_SERVER"] ?? "https://localhost:8443" }

    override func setUpWithError() throws {
        continueAfterFailure = false
        app = XCUIApplication()
        app.launchArguments = ["-keepiq-reset", "-keepiq-autofill-preview"]
        app.launchEnvironment["KEEPIQ_UITEST_NO_BROWSER"] = "1"
        // The one-time code test fills the seeded webmail login; the other
        // test uses a site the seeded replay vault has no login for.
        app.launchEnvironment["KEEPIQ_AUTOFILL_SITE"] = name.contains("OneTimeCode") ? "webmail.example.com" : "keepiq-autofill.test"
        app.launch()
    }

    private func shot(_ name: String) {
        let screenshot = XCUIScreen.main.screenshot()
        let attachment = XCTAttachment(screenshot: screenshot)
        attachment.name = name
        attachment.lifetime = .keepAlways
        add(attachment)
        if let dir = env["KEEPIQ_SHOTS_DIR"] {
            try? screenshot.pngRepresentation.write(to: URL(fileURLWithPath: dir).appendingPathComponent("\(name).png"))
        }
    }

    /// iOS may show its own "Save Password?" prompt after a sign-in or a save.
    /// It is not Keepiq's, so the test answers "Not Now" when it is up.
    private func dismissSystemSavePrompt() {
        let springboard = XCUIApplication(bundleIdentifier: "com.apple.springboard")
        for owner in [app!, springboard] where owner.buttons["Not Now"].exists {
            owner.buttons["Not Now"].tap()
            return
        }
    }

    /// Whether iOS offered to save a password after the add-login form (task
    /// 3.2.1): recorded as an attachment and in the log, then answered.
    private func recordSavePrompt(_ step: String) {
        let springboard = XCUIApplication(bundleIdentifier: "com.apple.springboard")
        for owner in [app!, springboard] where owner.buttons["Not Now"].waitForExistence(timeout: 4) {
            shot("save-password-prompt-\(step)")
            print("KEEPIQ save-password prompt after \(step)")
            owner.buttons["Not Now"].tap()
            return
        }
        print("KEEPIQ no save-password prompt after \(step)")
    }

    /// Connecting with an app password makes iOS offer to save it a moment
    /// later. Answer that first: keys typed while the prompt slides in are lost.
    private func answerSavePromptAfterConnect() {
        let springboard = XCUIApplication(bundleIdentifier: "com.apple.springboard")
        for owner in [app!, springboard] where owner.buttons["Not Now"].waitForExistence(timeout: 4) {
            owner.buttons["Not Now"].tap()
            return
        }
    }

    /// Makes an element tappable: answers the prompt, hides the keyboard that
    /// covers the lower half of a form, or scrolls the element into view.
    private func makeHittable(_ element: XCUIElement) {
        dismissSystemSavePrompt()
        if element.isHittable { return }
        if app.keyboards.count > 0 {
            // Return ends editing in a SwiftUI field and lowers the keyboard.
            let keys = app.keyboards.buttons.matching(
                NSPredicate(format: "label IN %@", ["Return", "return", "Done", "done"]))
            if keys.firstMatch.exists { keys.firstMatch.tap() } else { app.swipeDown(velocity: .slow) }
        } else {
            app.swipeUp(velocity: .slow)
        }
    }

    /// Taps until the field has keyboard focus: a field in a sheet that is
    /// still sliding in takes the tap without taking the focus.
    /// Waits for an element while answering the system prompt, which can
    /// appear a moment after the tap that caused it.
    private func appears(_ element: XCUIElement, timeout: TimeInterval) -> Bool {
        let end = Date().addingTimeInterval(timeout)
        while Date() < end {
            if element.waitForExistence(timeout: 1) {
                if element.isHittable { return true }
                makeHittable(element)
            }
        }
        return element.exists && element.isHittable
    }

    private func type(_ text: String, into element: XCUIElement) {
        XCTAssertTrue(appears(element, timeout: 20), "\(element) is missing")
        let focused = NSPredicate(format: "hasKeyboardFocus == true")
        for _ in 0..<5 {
            if element.isHittable { element.tap() }
            let wait = XCTNSPredicateExpectation(predicate: focused, object: element)
            if XCTWaiter().wait(for: [wait], timeout: 3) == .completed { break }
        }
        element.typeText(text)
    }

    private func tap(_ element: XCUIElement, timeout: TimeInterval = 20) {
        XCTAssertTrue(appears(element, timeout: timeout), "\(element) is missing")
        element.tap()
    }

    private func waitForLabel(_ element: XCUIElement, containing text: String, timeout: TimeInterval = 60) {
        expectation(for: NSPredicate(format: "label CONTAINS %@", text), evaluatedWith: element)
        waitForExpectations(timeout: timeout)
    }

    func testAddAndFillThroughTheAutofillSheet() {
        type(server, into: app.textFields["server"])
        tap(app.buttons["useAppPassword"])
        type("admin", into: app.textFields["loginName"])
        type("manual-app-password", into: app.secureTextFields["appPassword"])
        tap(app.buttons["connect"])
        answerSavePromptAfterConnect()
        type("Oj", into: app.secureTextFields["masterPassword"])
        tap(app.buttons["unlock"])
        XCTAssertTrue(app.buttons["lock"].waitForExistence(timeout: 60), "the vault opens after unlock")

        // The sheet starts locked: the extension has its own unlock.
        tap(app.buttons["autofillPreview"])
        type("Oj", into: app.secureTextFields["autofillMasterPassword"])
        shot("autofill-ios-01-unlock")
        tap(app.buttons["autofillUnlock"])
        XCTAssertTrue(app.staticTexts["autofillEmpty"].waitForExistence(timeout: 60))
        shot("autofill-ios-02-no-login-yet")

        // Add login: saved, then filled.
        tap(app.buttons["autofillAdd"])
        type("alice", into: app.textFields["autofillAddUser"])
        type("Correct-horse-1", into: app.secureTextFields["autofillAddPassword"])
        shot("autofill-ios-03-add-login")
        tap(app.buttons["autofillAddSave"])
        recordSavePrompt("autofill-add")
        waitForLabel(app.staticTexts["autofillFilled"], containing: "filled: alice / 15")
        // keepiq-autofill.test has no authenticator item: nothing on the clipboard.
        waitForLabel(app.staticTexts["autofillCode"], containing: "no code on clipboard")

        // The refresh after the save rebuilt the index, and the site file is on disk.
        waitForLabel(app.staticTexts["autofillIndex"], containing: "keepiq-autofill.test: 1 logins on disk")
        shot("autofill-ios-04-index-rebuilt")

        // Again: no unlock within the idle time, the login from the site file.
        // The first sheet may still be closing: wait until the button is back.
        let preview = app.buttons["autofillPreview"]
        XCTAssertTrue(preview.waitForExistence(timeout: 20))
        tap(preview)
        let row = app.buttons["autofillRow"]
        let shown = [row, app.secureTextFields["autofillMasterPassword"], app.staticTexts["autofillEmpty"]]
        let deadline = Date().addingTimeInterval(30)
        while Date() < deadline, !shown.contains(where: { $0.exists }) {
            _ = row.waitForExistence(timeout: 1)
        }
        shot("autofill-ios-05-second-sheet")
        XCTAssertTrue(row.exists, "second sheet: unlock form \(shown[1].exists), empty list \(shown[2].exists)")
        XCTAssertFalse(app.secureTextFields["autofillMasterPassword"].exists)
        row.tap()
        waitForLabel(app.staticTexts["autofillFilled"], containing: "filled: alice / 15")
    }

    /// The seeded "Authenticator (demo)" item of webmail.example.com
    /// (mobile/e2e/server.mjs DEMO_ITEMS).
    private let seededTotpSecret = "JBSWY3DPEHPK3PXP"

    func testFillPutsTheOneTimeCodeOnTheClipboard() {
        type(server, into: app.textFields["server"])
        tap(app.buttons["useAppPassword"])
        type("admin", into: app.textFields["loginName"])
        type("manual-app-password", into: app.secureTextFields["appPassword"])
        tap(app.buttons["connect"])
        answerSavePromptAfterConnect()
        type("Oj", into: app.secureTextFields["masterPassword"])
        tap(app.buttons["unlock"])
        XCTAssertTrue(app.buttons["lock"].waitForExistence(timeout: 60), "the vault opens after unlock")
        // The unlock refreshes the vault and writes the site file (example.com): the login and the authenticator.
        expectation(for: NSPredicate(format: "label BEGINSWITH 'example.com: ' AND NOT (label CONTAINS ': 0 logins')"),
                    evaluatedWith: app.staticTexts["autofillIndex"])
        waitForExpectations(timeout: 60)

        tap(app.buttons["autofillPreview"])
        type("Oj", into: app.secureTextFields["autofillMasterPassword"])
        tap(app.buttons["autofillUnlock"])
        let row = app.buttons["autofillRow"].firstMatch
        XCTAssertTrue(row.waitForExistence(timeout: 60), "the webmail login is listed")
        shot("autofill-ios-06-code-site")
        row.tap()
        waitForLabel(app.staticTexts["autofillFilled"], containing: "filled: anna.demo@example.com / 17")

        let code = app.staticTexts["autofillCode"]
        waitForLabel(code, containing: "code on clipboard: ", timeout: 20)
        shot("autofill-ios-07-code-on-clipboard")
        let copied = code.label.replacingOccurrences(of: "code on clipboard: ", with: "")
        // The code of the current 30-second step, or of the one before when the step turned during the fill.
        let now = Date().timeIntervalSince1970
        let expected = [now, now - 30].map { Self.totp(secret: seededTotpSecret, at: $0) }
        XCTAssertTrue(expected.contains(copied), "copied \(copied), expected one of \(expected)")
    }

    /// RFC 6238 with SHA-1, 6 digits and a 30-second step, written apart from
    /// the shared core so the test does not check the core with itself.
    static func totp(secret: String, at time: TimeInterval) -> String {
        let alphabet = Array("ABCDEFGHIJKLMNOPQRSTUVWXYZ234567")
        var bits = 0, value = 0
        var key = [UInt8]()
        for char in secret.uppercased() where char != "=" {
            guard let index = alphabet.firstIndex(of: char) else { continue }
            value = ((value << 5) | index) & 0xFFFF
            bits += 5
            if bits >= 8 {
                key.append(UInt8((value >> (bits - 8)) & 0xFF))
                bits -= 8
            }
        }
        let message = withUnsafeBytes(of: UInt64(time / 30).bigEndian) { Data($0) }
        let mac = Array(HMAC<Insecure.SHA1>.authenticationCode(for: message, using: SymmetricKey(data: key)))
        let offset = Int(mac[mac.count - 1] & 0x0F)
        let number = (UInt32(mac[offset] & 0x7F) << 24) | (UInt32(mac[offset + 1]) << 16)
            | (UInt32(mac[offset + 2]) << 8) | UInt32(mac[offset + 3])
        return String(format: "%06u", number % 1_000_000)
    }

    /// The RFC 6238 SHA-1 vectors, trimmed to 6 digits, so a wrong expected code cannot pass unseen.
    func testTotpHelperMatchesTheRfcVectors() {
        let secret = "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ" // "12345678901234567890"
        XCTAssertEqual(Self.totp(secret: secret, at: 59), "287082")
        XCTAssertEqual(Self.totp(secret: secret, at: 1_111_111_109), "081804")
        XCTAssertEqual(Self.totp(secret: secret, at: 1_234_567_890), "005924")
        XCTAssertEqual(Self.totp(secret: secret, at: 2_000_000_000), "279037")
    }
}
