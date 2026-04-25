# APPTOOK Extension Project Context

## Origin of the project

This project started from the `extension-cursor pool` extension that was discovered as an existing solution. The original extension allows a user to log in with a purchased licence and then use the extension after activating it and starting the API worker.

In the original flow, the user performs these steps:

1. Log in with a licence
2. Click `Activate`
3. Click `Start api-worker`

After that, the extension can be used according to the purchased licence package, such as a daily token quota and a licence duration like 1 day, 7 days, or 30 days.

## Problem the project is trying to solve

The original flow has a limitation: when a licence reaches its daily quota, the user must buy another licence and log in again to continue.

The goal of `Extension APTOOK` is to create a new branded extension experience on top of the existing `extension-cursor pool` behaviour so that the user sees only the APPTOOK UI and branding, while the backend still relies on the original extension-cursor pool capabilities.

## What APPTOOK changes

`Extension APTOOK` is intended to sit on top of the original extension-cursor pool and provide:

- APPTOOK branding and UI
- a simpler user experience
- hidden background licence switching
- continued usage without exposing the underlying licence rotation logic to the user

From the user's point of view, they only see APPTOOK key usage and a longer, smoother experience.

## Backend and admin relationship

The APPTOOK extension must work together with a WordPress admin/backend system.

The backend is responsible for creating and managing `apptook key` records, and each `apptook key` can be linked to one or more licences.

Example:

- `apptook key A` may be linked to `licence 1`
- when `licence 1` reaches its limit, the system can switch to `licence 2`
- the user should not notice the switch
- the user should feel like the APPTOOK key keeps working for a longer time

## Intended architecture idea

The high-level idea is:

- WordPress admin creates and manages APPTOOK keys
- each APPTOOK key maps to one or more backend licences
- the extension UI is branded as APPTOOK
- the hidden logic still uses the extension-cursor pool capabilities underneath
- licence rotation happens in the background

## Important note

This project is not trying to remove the original extension-cursor pool logic.
It is intended to reuse the original capability while creating a better user-facing APPTOOK experience.

## Why this document exists

This file is the project origin note so that future developers can quickly understand:

- where the idea came from
- what problem it solves
- how the APPTOOK layer relates to the original extension-cursor pool
- what the WordPress backend is responsible for
- why licence switching is hidden from the user
