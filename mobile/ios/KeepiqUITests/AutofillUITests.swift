// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import XCTest

/// Task group 4 on the iOS simulator, as far as a simulator test reaches.
/// Picking Keepiq as the AutoFill provider happens in Settings and cannot be
/// automated, so the app shows the extension's own screens (the same model,
/// files and Keychain) under -keepiq-autofill-preview, against the replay:
///
/// - the unlock inside the AutoFill sheet, with the master password;
/// - "Add login" for example.com, saved encrypted to the suite key and
///   filled in at once;
/// - the index rebuilt after the vault refresh: one site file and one
///   identity for ASCredentialIdentityStore;
/// - the sheet again, without a new unlock, listing that login from the
///   site file and filling it.
///
/// Manual: Keepiq in the QuickType bar of Safari and an app, Face ID in the
/// sheet, and the one-time code on the clipboard after a fill.
final class AutofillUITests: XCTestCase {
    private var app: XCUIApplication!
    private let env = ProcessInfo.processInfo.environment
    private var server: String { env["KEEPIQ_SERVER"] ?? "https://localhost:8443" }

    override func setUpWithError() throws {
        continueAfterFailure = false
        app = XCUIApplication()
        app.launchArguments = ["-keepiq-reset", "-keepiq-autofill-preview"]
        app.launchEnvironment["KEEPIQ_UITEST_NO_BROWSER"] = "1"
        app.launchEnvironment["KEEPIQ_AUTOFILL_SITE"] = "example.com"
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

    /// iOS may still show its own "Save Password?" prompt over the app; it is
    /// not Keepiq's, so the test answers it and carries on.
    private func dismissSystemSavePrompt() {
        for target in [app!, XCUIApplication(bundleIdentifier: "com.apple.springboard")] {
            let notNow = target.buttons["Not Now"]
            if notNow.exists && notNow.isHittable { notNow.tap() }
        }
    }

    /// Taps until the field has keyboard focus: a field in a sheet that is
    /// still sliding in takes the tap without taking the focus.
    /// Waits for an element while answering the system prompt, which can
    /// appear a moment after the tap that caused it.
    private func appears(_ element: XCUIElement, timeout: TimeInterval) -> Bool {
        let end = Date().addingTimeInterval(timeout)
        while Date() < end {
            dismissSystemSavePrompt()
            if element.waitForExistence(timeout: 1) { return true }
        }
        return element.exists
    }

    private func type(_ text: String, into element: XCUIElement) {
        dismissSystemSavePrompt()
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
        dismissSystemSavePrompt()
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
        type("Oj", into: app.secureTextFields["masterPassword"])
        tap(app.buttons["unlock"])
        XCTAssertTrue(app.staticTexts["unlocked"].waitForExistence(timeout: 60))

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
        waitForLabel(app.staticTexts["autofillFilled"], containing: "filled: alice / 15")

        // The refresh after the save rebuilt the index.
        waitForLabel(app.staticTexts["autofillIndex"], containing: "sites 1, identities 1")
        shot("autofill-ios-04-index-rebuilt")

        // Again: no unlock within the idle time, the login from the site file.
        tap(app.buttons["autofillPreview"])
        let row = app.buttons["autofillRow"]
        XCTAssertTrue(row.waitForExistence(timeout: 30))
        XCTAssertFalse(app.secureTextFields["autofillMasterPassword"].exists)
        shot("autofill-ios-05-list")
        row.tap()
        waitForLabel(app.staticTexts["autofillFilled"], containing: "filled: alice / 15")
    }
}
