# APPTOOK User Flow Overview

## Goal

Keep the user-facing experience as simple as possible:

1. The user enters only an `APPTOOK key`.
2. The extension resolves the license/session in the background.
3. The extension auto-activates the session.
4. The extension starts the API worker automatically.
5. The user only sees the minimum status needed to know the session is ready.

## High-level architecture

There are two main parts in the repository:

- `extension-cursor` — the VS Code extension and user webview.
- `apptook-digital-store` — the WordPress-side plugin and data store.

The current work focuses on the user-facing `extension-cursor` webview.

## Intended user flow

### 1. Login gate

The webview should open on a login screen that contains only:

- APPTOOK logo / branding
- APPTOOK key input
- Login button
- Login / status message area

The user should not be shown:

- proxy settings
- network protocol settings
- token settings
- activation buttons
- worker start buttons
- admin-style dashboard controls

### 2. Login request

When the user submits their APPTOOK key:

- the webview sends the key to the extension host
- the host validates it against the backend
- the host returns session / license data if the key is valid

### 3. Session restoration

If valid session data is returned:

- the webview stores the session state locally
- the UI unlocks into a logged-in state
- the extension prepares the current key / session values
- any stale or mismatched state should be cleared if needed

### 4. Auto-activation

After login succeeds:

- the extension automatically triggers activation
- the user should not need to click an Activate button
- activation status is only shown as a progress or result message

### 5. Auto start API worker

After activation succeeds:

- the extension automatically starts the API worker
- the user should not need to click a Start Worker button
- the UI should only show the relevant success / loading state

### 6. Ready state

When everything is available:

- the user sees the logged-in status
- the session data is visible only in the compact approved format
- background refresh / recovery can continue silently where appropriate

## What should be hidden from the user panel

The user panel should not expose these operational controls:

- `Activate` / `Deactivate` button
- `Start api-worker` button
- `Refresh Status` button in normal user flow
- `proxy-settings`
- `network-settings`
- `token-settings`
- any admin/debug style dashboard widgets

These controls may still exist in code for host logic, but they should not be visible in the user-facing UI.

## Current behavior found in the code

The current `userPanel.js` still contains a mix of behaviors:

- manual login flow
- resume / restore flow
- loop key rotation flow
- worker recovery flow
- dashboard sync / refresh flow
- a premium dashboard wrapper that re-renders or hides several sections

This means the UI currently supports more than the intended minimal user flow.

## Main issue to fix

The biggest UX problem is that the user-facing panel still contains legacy controls and state transitions that make it look like an admin/control panel instead of a simple login screen.

The target is:

- keep login visible
- keep only minimal status visible after login
- move everything else behind automatic background behavior or out of the user panel entirely

## Recommended implementation direction

### UI simplification

Keep only:

- logo
- APPTOOK key input
- login button
- status messages

Remove or hide:

- network controls
- token controls
- proxy controls
- manual activation controls
- manual worker start controls

### Flow simplification

After a valid login:

1. save session state
2. auto-activate
3. auto-start worker
4. show ready state

### State cleanup

Make sure stale state is cleared when:

- a fresh install is detected
- the stored session is invalid
- the extension restarts with mismatched session data

## Files most relevant to this flow

- `apptook extentions/extension-cursor/webview/userPanel.js`
- `apptook extentions/extension-cursor/webview/userPanel.css`
- `apptook extentions/extension-cursor/includes/class-extension-cursor-admin.php`
- `apptook extentions/extension-cursor/includes/class-extension-cursor-api.php`
- `apptook extentions/extension-cursor/bootstrap.js`

## Notes for review

This document describes the intended user flow, not necessarily the current implementation.

The current code still contains legacy paths, so the implementation should be checked against this overview before making further changes.
