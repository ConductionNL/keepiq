// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

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
        // A site the seeded replay vault has no login for.
        app.launchEnvironment["KEEPIQ_AUTOFILL_SITE"] = "keepiq-autofill.test"
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
        waitForLabel(app.staticTexts["autofillFilled"], containing: "filled: alice / 15")

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
}
