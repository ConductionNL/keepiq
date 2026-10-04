// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import XCTest

/// Task group 2 on the iOS simulator, against mobile/e2e/server.mjs replaying
/// answers recorded from the real test server (.github/workflows/mobile-e2e.yml).
/// The replay grants a Login Flow v2 on the third poll, as a user signing in
/// would; the app skips the browser sheet in this run.
final class PairUnlockUITests: XCTestCase {
    private var app: XCUIApplication!
    private let env = ProcessInfo.processInfo.environment
    private var server: String { env["KEEPIQ_SERVER"] ?? "https://localhost:8443" }

    override func setUpWithError() throws {
        continueAfterFailure = false
        app = XCUIApplication()
        app.launchArguments = ["-keepiq-reset"]
        app.launchEnvironment["KEEPIQ_UITEST_NO_BROWSER"] = "1"
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

    private func type(_ text: String, into element: XCUIElement) {
        XCTAssertTrue(element.waitForExistence(timeout: 20), "\(element) is missing")
        element.tap()
        element.typeText(text)
    }

    private func tap(_ element: XCUIElement, timeout: TimeInterval = 20) {
        XCTAssertTrue(element.waitForExistence(timeout: timeout), "\(element) is missing")
        element.tap()
    }

    private func waitForMessage(containing text: String, timeout: TimeInterval = 60) {
        let message = app.staticTexts["message"]
        let predicate = NSPredicate(format: "label CONTAINS %@", text)
        expectation(for: predicate, evaluatedWith: message)
        waitForExpectations(timeout: timeout)
    }

    func testBrowserPairingUnlockPinAndUnpair() {
        // 2.1: the browser sign-in.
        type(server, into: app.textFields["server"])
        shot("01-connect")
        tap(app.buttons["signIn"])
        XCTAssertTrue(app.activityIndicators["waiting"].waitForExistence(timeout: 20))
        shot("02-waiting-for-browser")

        // Paired: unlock. A wrong master password first (2.2).
        type("not the master password", into: app.secureTextFields["masterPassword"])
        shot("03-unlock")
        tap(app.buttons["unlock"])
        waitForMessage(containing: "not correct")
        shot("04-wrong-master-password")

        type("Oj", into: app.secureTextFields["masterPassword"])
        tap(app.buttons["unlock"])
        XCTAssertTrue(app.staticTexts["unlocked"].waitForExistence(timeout: 60))
        shot("05-unlocked")

        // 2.3: a PIN (Argon2id through the reference C code).
        tap(app.buttons["settings"])
        type("246810", into: app.secureTextFields["newPin"])
        tap(app.buttons["setPin"])
        XCTAssertTrue(app.buttons["removePin"].waitForExistence(timeout: 60))
        shot("06-settings-pin-set")
        tap(app.buttons["back"])

        tap(app.buttons["lock"])
        type("000000", into: app.secureTextFields["pin"])
        shot("07-locked-pin")
        tap(app.buttons["unlockPin"])
        waitForMessage(containing: "4 tries left")
        shot("08-wrong-pin")
        type("246810", into: app.secureTextFields["pin"])
        tap(app.buttons["unlockPin"])
        XCTAssertTrue(app.staticTexts["unlocked"].waitForExistence(timeout: 60))

        // 2.6: unpair.
        tap(app.buttons["settings"])
        let unpair = app.buttons["unpair"]
        XCTAssertTrue(unpair.waitForExistence(timeout: 20))
        if !unpair.isHittable { app.swipeUp() }
        unpair.tap()
        let confirm = app.alerts.buttons["Disconnect"]
        XCTAssertTrue(confirm.waitForExistence(timeout: 10))
        shot("09-unpair-confirm")
        confirm.tap()
        waitForMessage(containing: "The app password was deleted in Nextcloud.")
        XCTAssertTrue(app.textFields["server"].exists)
        shot("10-unpaired")
    }

    func testAppPasswordPairingAndTwoFactorBlock() {
        type(server, into: app.textFields["server"])
        tap(app.buttons["useAppPassword"])
        type("blocked", into: app.textFields["loginName"])
        type("manual-app-password", into: app.secureTextFields["appPassword"])
        shot("20-app-password")
        tap(app.buttons["connect"])

        let blocked = app.staticTexts["blocked"]
        XCTAssertTrue(blocked.waitForExistence(timeout: 60))
        XCTAssertTrue(blocked.label.contains("two-factor authentication"))
        XCTAssertFalse(app.secureTextFields["masterPassword"].exists)
        XCTAssertFalse(app.secureTextFields["pin"].exists)
        shot("21-two-factor-required")
    }
}
