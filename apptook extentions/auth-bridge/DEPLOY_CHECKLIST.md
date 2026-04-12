# APPTOOK Auth Bridge Deployment Checklist

## 1) Prepare environment
- [ ] Install Node.js LTS
- [ ] Copy `.env.example` to `.env`
- [ ] Fill all required environment values
- [ ] Ensure `ACCESS_JWT_SECRET` and `REFRESH_JWT_SECRET` are different and random

## 2) Google Sheets setup
- [ ] Service account has access to target sheet
- [ ] `SHEET_ID` is correct
- [ ] `users` sheet columns:
  - [ ] `username`
  - [ ] `password` (bcrypt hash recommended, plaintext supported for migration)
  - [ ] `status` (`active`/other)
  - [ ] `licenseCode`
  - [ ] `role`
- [ ] `licenses` sheet columns:
  - [ ] `licenseCode`
  - [ ] `status` (`active`/other)
  - [ ] `expireAt` (ISO or valid date)
  - [ ] `maxDevices`
  - [ ] `currentDevices` (comma-separated)

## 3) Install and run
- [ ] Run `npm install`
- [ ] Run `npm start`
- [ ] Verify `GET /health` returns `ok: true`

## 4) Security hardening
- [ ] Run behind HTTPS reverse proxy (Nginx/Caddy/Cloudflare Tunnel)
- [ ] Restrict network access to backend where possible
- [ ] Rotate JWT secrets regularly
- [ ] Protect `.refresh-store.json` file permission
- [ ] Backup `.refresh-store.json` if required for session continuity

## 5) Extension integration checks
- [ ] Login returns `licenseCode + accessToken + refreshToken`
- [ ] Session restore works after reopen
- [ ] Expired access token auto-refreshes successfully
- [ ] Logout revokes refresh token and clears local tokens
- [ ] Disabled/expired license is denied by `/session/me` and `/token/refresh`

## 6) Password migration (recommended)
- [ ] Convert plaintext passwords to bcrypt hashes
- [ ] Confirm login still works with bcrypt rows
- [ ] Remove plaintext passwords from sheet

## 7) Operational checks
- [ ] Monitor failed login spikes (rate-limit triggered)
- [ ] Verify Google API quota status
- [ ] Test behavior during temporary Google API failure
- [ ] Document recovery process for rotated/compromised secrets
