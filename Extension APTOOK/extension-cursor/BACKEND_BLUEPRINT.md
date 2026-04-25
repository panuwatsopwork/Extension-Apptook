# Extension Cursor Backend Blueprint

This document describes the backend capabilities required to support APPTOOK key management and licence mapping.

## 1. Core domains

### APPTOOK Key
Represents the customer-facing key used by the user inside the extension.

Recommended fields:
- `id`
- `key_value`
- `status`
- `created_at`
- `updated_at`
- `last_used_at`
- `notes`

### Licence
Represents the actual backend licence resource that can be used by the extension.

Recommended fields:
- `id`
- `licence_value`
- `status`
- `token_capacity`
- `expires_at`
- `created_at`
- `updated_at`
- `last_used_at`
- `notes`

### Mapping
Represents the relationship between an APPTOOK key and one or more licences.

Recommended fields:
- `id`
- `apptook_key_id`
- `licence_id`
- `priority`
- `status`
- `created_at`
- `updated_at`

### Session
Represents a logged-in user session for the extension.

Recommended fields:
- `id`
- `apptook_key_id`
- `licence_id`
- `session_token`
- `status`
- `expires_at`
- `created_at`
- `updated_at`
- `last_heartbeat_at`

### Audit Log
Captures who changed what and when.

Recommended fields:
- `id`
- `actor_type`
- `actor_id`
- `action`
- `payload`
- `created_at`

## 2. Required backend capabilities

### APPTOOK key management
- create key
- edit key
- disable key
- archive key
- search keys
- filter by status

### Licence management
- create licence
- edit licence
- delete licence
- bulk import licences
- expire / revoke licences
- track usage and capacity

### Mapping management
- assign one APPTOOK key to multiple licences
- change mapping priority
- disable mapping without deleting records
- rotate to the next available licence automatically

### Session lifecycle
- login / authenticate key
- create session
- validate session
- renew session
- revoke session
- clean stale sessions

### Monitoring
- active keys count
- active licences count
- expired items count
- rotation history
- last login / last heartbeat
- failures and error traces

### Recovery / cleanup
- clear stale sessions
- rebind invalid mappings
- recover from interrupted rotation
- repair broken state after restart

## 3. WordPress admin screens

Recommended admin pages:
- dashboard overview
- APPTOOK keys
- licences
- mappings
- sessions
- audit log
- settings

## 4. API endpoints

Recommended endpoints for extension communication:
- login
- validate session
- activate session
- start worker
- refresh state
- heartbeat
- recover state
- logout

## 5. Security expectations

- capability checks for admin actions
- nonces for browser requests
- request payload validation
- rate limiting for auth endpoints
- audit logging for admin changes
- sanitize all stored and displayed values

## 6. Architecture notes

The backend should keep responsibilities separated:
- UI rendering in admin layer
- request handling in controller layer
- database logic in repository layer
- business rules in service layer
- shared constants in bootstrap or config layer

## 7. Implementation phases

### Phase 1
- core tables
- key CRUD
- licence CRUD
- basic mapping

### Phase 2
- session lifecycle
- login/validate endpoints
- automatic activation flow

### Phase 3
- monitoring dashboard
- audit logging
- recovery tools
- advanced rotation logic
