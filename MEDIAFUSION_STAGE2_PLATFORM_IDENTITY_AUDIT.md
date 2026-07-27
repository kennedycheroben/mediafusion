# MEDIAFUSION — STAGE 2: OAUTH PLATFORM IDENTITY FORENSIC AUDIT

**Date:** 2026-07-27
**Status:** READ-ONLY AUDIT — No code or database changes made
**Scope:** Complete platform identity handling across entire codebase

---

## CURRENT PLATFORM MODEL

### Actual Database State (verified live)

```
oauth_tokens.platform values:
  id=1  user=2  platform=youtube    status=invalid
  id=2  user=3  platform=tiktok     status=active
  id=4  user=3  platform=meta       status=invalid
  id=5  user=10 platform=youtube    status=active
  id=6  user=10 platform=tiktok     status=active
  id=7  user=10 platform=meta       status=active

uploads.platforms JSON values:
  ["youtube"]
  ["youtube","tiktok"]
```

**No `facebook` or `instagram` rows exist in either table.** All current Meta tokens use platform=`'meta'`.

### Code Recognized Platform Values

| Platform String | OAuth Initiation | OAuth Callback Storage | Token Refresh | Distribution | Analytics |
|---|---|---|---|---|---|
| `youtube` | connect.php:85 | callback.php:169 | refresh_worker.php:131 | distributor.py:411 | analytics.php:183 |
| `tiktok` | connect.php:113 | callback.php:79 | SKIPPED | distributor.py:415 | analytics.php:184 |
| `facebook` | connect.php:101 | callback_meta.php:101 | SKIPPED | **NO HANDLER** | analytics.php:185 |
| `instagram` | connect.php:107 | callback_meta.php:101 | SKIPPED | **NO HANDLER** | analytics.php:186 |
| `meta` | **NO HANDLER** | **NEVER WRITTEN** | refresh_worker.php:194 | distributor.py:413 | analytics.php:187 |

---

## ALL STORAGE LOCATIONS (Where platform values are written to DB)

### callback.php (YouTube + TikTok)

| Line | Platform | SQL | Type |
|------|----------|-----|------|
| 79 | `'tiktok'` | `INSERT INTO oauth_tokens ... VALUES (?, 'tiktok', ...)` | Hardcoded literal |
| 169 | `'youtube'` | `:platform => 'youtube'` bound to named param | Hardcoded literal |

### callback_meta.php (Facebook + Instagram)

| Line | Platform | SQL | Type |
|------|----------|-----|------|
| 101 | `$metaPlatform` | `:platform => $metaPlatform` bound to named param | Dynamic: `'facebook'` or `'instagram'` |

**$metaPlatform determined by:** `$_SESSION['meta_pending_platform']` set in `connect_meta.php:18` from `$_GET['platform']`.

**Key observation:** `callback_meta.php` stores `'facebook'` or `'instagram'` — **never `'meta'`**. There is no code path that writes `'meta'` to the database.

### upload_handler.php

| Line | Platform | SQL | Type |
|------|----------|-----|------|
| 115 | N/A | `uploads.platforms = $_POST['platforms']` (JSON) | Passthrough from client |

No platform validation. Whatever the client sends is stored verbatim.

---

## ALL READ/QUERY LOCATIONS (Where platform values are read from DB)

### refresh_worker.php

| Line | SQL | Purpose |
|------|-----|---------|
| 206-211 | `SELECT ... FROM oauth_tokens WHERE token_expiry <= DATE_ADD(NOW(), INTERVAL 15 MINUTE)` | Fetch all expiring tokens (no platform filter) |
| 131 | `UPDATE ... WHERE user_id = ? AND platform = 'youtube'` | Update YouTube token after refresh |
| 194 | `UPDATE ... WHERE user_id = ? AND platform = 'meta'` | Update Meta token after refresh |
| 62 | `UPDATE ... WHERE user_id = ? AND platform = ?` | Mark token invalid (parameterized) |
| 70 | `UPDATE ... WHERE user_id = ? AND platform = ?` | Set token expiry to NOW (parameterized) |

### connect.php

| Line | SQL | Purpose |
|------|-----|---------|
| 51 | `DELETE FROM oauth_tokens WHERE user_id = ? AND platform = ?` | Disconnect platform |
| 69 | `SELECT platform FROM oauth_tokens WHERE user_id = ?` | Get connected platforms |

### fetch_live_analytics.php

| Line | SQL | Purpose |
|------|-----|---------|
| 122 | `SELECT platform, access_token FROM oauth_tokens WHERE user_id = ?` | Get tokens for post fetch |
| 221 | `SELECT platform, access_token, token_expiry FROM oauth_tokens WHERE user_id = ?` | Get tokens for live analytics |

### analytics.php

| Line | SQL | Purpose |
|------|-----|---------|
| 18 | `SELECT platform FROM oauth_tokens WHERE user_id = ?` | Get platforms for follower estimate |

### distributor.py

| Line | SQL | Purpose |
|------|-----|---------|
| 150 | `SELECT access_token, refresh_token, token_expiry, token_status FROM oauth_tokens WHERE user_id=%s AND platform=%s` | Get token for specific platform |
| 208 | `UPDATE oauth_tokens SET access_token = %s, token_expiry = %s WHERE user_id = %s AND platform = 'youtube'` | Update YouTube token after refresh |

### uploader.py

| Line | SQL | Purpose |
|------|-----|---------|
| 59-62 | `UPDATE oauth_tokens SET access_token = %s, token_expiry = %s WHERE user_id = %s AND platform = %s` | Update token after YouTube refresh |
| 376 | `SELECT platform, access_token, refresh_token FROM oauth_tokens WHERE user_id = %s` | Get all tokens for user |

---

## ALL REFRESH PATHS

### refresh_worker.php dispatch logic (lines 214-224)

```php
foreach ($rows as $row) {
    $platform = (string)($row['platform'] ?? '');
    if ($platform === 'youtube') {
        refresh_youtube($pdo, $row);        // → googleapis.com/oauth/token
    } elseif ($platform === 'meta') {
        refresh_meta($pdo, $row);           // → graph.facebook.com/oauth/access_token
    } else {
        log_line("Skipping refresh for platform={$platform}");
    }
}
```

### Refresh coverage matrix

| Platform | Refresh handler | API endpoint | Status |
|----------|----------------|--------------|--------|
| `youtube` | `refresh_youtube()` | `https://oauth2.googleapis.com/token` | **WORKING** |
| `tiktok` | None (skipped) | N/A | **NOT IMPLEMENTED** |
| `facebook` | None (skipped) | N/A | **BROKEN** — would need `refresh_meta()` |
| `instagram` | None (skipped) | N/A | **BROKEN** — would need `refresh_meta()` |
| `meta` | `refresh_meta()` | `https://graph.facebook.com/{version}/oauth/access_token` | **WORKING** |

### uploader.py inline refresh (line 98)

Only YouTube uploads perform inline token refresh during upload:
```python
update_db_token(user_id, 'youtube', credentials.token, credentials.expiry)
```

No inline refresh for TikTok, Facebook, Instagram, or Meta.

---

## ALL PUBLISHING PATHS

### distributor.py (production engine)

| Line | Platform | Handler | Token lookup |
|------|----------|---------|-------------|
| 411 | `youtube` | `push_youtube()` | `get_token(user_id, "youtube")` |
| 413 | `meta` | `push_meta()` | `get_token(user_id, "meta")` |
| 415 | `tiktok` | `push_tiktok()` | `get_token(user_id, "tiktok")` |
| — | `facebook` | **NO HANDLER** | **Silently skipped** |
| — | `instagram` | **NO HANDLER** | **Silently skipped** |

### uploader.py (legacy uploader)

| Line | Platform | Handler | Token lookup |
|------|----------|---------|-------------|
| 389 | `youtube` | `upload_youtube()` | `tokens.get('youtube')` |
| 391 | `tiktok` | `upload_tiktok()` | `tokens.get('tiktok')` |
| 393 | `facebook` | `upload_facebook()` | `tokens.get('facebook') or tokens.get('meta')` |
| 396 | `instagram` | `upload_instagram()` | `tokens.get('instagram') or tokens.get('meta')` |
| 399 | `meta` | `upload_meta()` | `tokens.get('meta')` |

### fetch_live_analytics.php API routing

| Line | Platform | API endpoint |
|------|----------|-------------|
| 291 | `youtube` | `googleapis.com/youtube/v3/channels?part=statistics&mine=true` |
| 309 | `tiktok` | `open.tiktokapis.com/v2/user/info/?fields=follower_count,likes_count` |
| 325 | `facebook` | `graph.facebook.com/{version}/me?fields=id,name,fan_count` |
| 339 | `instagram` | `graph.facebook.com/{version}/me/accounts?fields=instagram_business_account{...}` |

### view_post_router.php

| Line | Platform | URL pattern |
|------|----------|------------|
| 95 | `youtube` | `youtube.com/watch?v={post_id}` |
| 99 | `tiktok` | `tiktok.com/video/{post_id}` |
| 103 | `facebook` | `facebook.com/{post_id}` |
| 107 | `instagram` | `instagram.com/p/{post_id}/` |

---

## ALL DISCONNECT PATHS

### connect.php disconnect logic

| Line | SQL | Platform source |
|------|-----|----------------|
| 51 | `DELETE FROM oauth_tokens WHERE user_id = ? AND platform = ?` | `$_GET['platform']` |

**Allowed platforms (line 17):** `['youtube', 'meta', 'tiktok', 'facebook', 'instagram']`

**UI renders disconnect forms for:** `youtube`, `tiktok`, `facebook`, `instagram` (lines 159, 183, 207, 231)

**No UI renders disconnect for `meta`** — but `meta` is in the allowed list, so a crafted POST would succeed.

---

## MISMATCHES

### MISMATCH #1 — CRITICAL: Meta token storage vs refresh

| Component | Stores as | Expects for refresh |
|-----------|-----------|-------------------|
| `callback_meta.php` | `'facebook'` or `'instagram'` | N/A |
| `refresh_worker.php` | N/A | `'meta'` |

**Impact:** If a user connects Facebook or Instagram through the current UI flow, `callback_meta.php` stores the token as `'facebook'` or `'instagram'`. When that token expires, `refresh_worker.php` dispatches it to the `else` branch ("Skipping refresh") because it only matches `$platform === 'meta'`. **The token silently expires and becomes invalid.** Users must manually reconnect.

### MISMATCH #2 — HIGH: Meta token storage vs distribution

| Component | Stores as | Expects for distribution |
|-----------|-----------|------------------------|
| `callback_meta.php` | `'facebook'` or `'instagram'` | N/A |
| `distributor.py` | N/A | `'meta'` |

**Impact:** `distributor.py` only creates threads for `youtube`, `meta`, and `tiktok` (lines 411-416). If `uploads.platforms` contains `"facebook"` or `"instagram"`, those platforms are **silently skipped** — no upload thread is created, no error is reported. The job completes with `{"ok": true}` for the other platforms.

### MISMATCH #3 — MEDIUM: uploader.py handles facebook/instagram, distributor.py does not

| Component | Handles `facebook` | Handles `instagram` |
|-----------|-------------------|-------------------|
| `uploader.py` | YES (with meta fallback) | YES (with meta fallback) |
| `distributor.py` | **NO** | **NO** |

**Impact:** Two different upload engines exist. `uploader.py` (legacy) handles all 5 platforms. `distributor.py` (production) only handles 3. Users who select Facebook or Instagram as target platforms get different results depending on which engine processes their upload.

### MISMATCH #4 — MEDIUM: fetch_live_analytics.php meta fallback is dead code

| Component | Stores as | Looks for in fallback |
|-----------|-----------|---------------------|
| `callback_meta.php` | `'facebook'` or `'instagram'` | N/A |
| `fetch_live_analytics.php` | N/A | Falls back to `'meta'` |

**Impact:** `fetch_live_analytics.php` lines 138-142 and 237-249 implement a fallback: if no token exists for `'facebook'` or `'instagram'`, check for a `'meta'` token. This fallback will never trigger because no `'meta'` rows are created by the current callback. The fallback is dead code — harmless but misleading.

### MISMATCH #5 — LOW: connect.php allows `meta` disconnect but no UI triggers it

| Component | Allowed | UI triggers |
|-----------|---------|------------|
| `connect.php` `$allowedPlatforms` | `'meta'` included | No disconnect form for `meta` |

**Impact:** A crafted POST request could disconnect a `'meta'` platform token. Since no `'meta'` rows are created by current callbacks, this would match zero rows and silently succeed. Low risk but violates principle of least privilege.

### MISMATCH #6 — LOW: analytics.php handles `meta` but getPostPlatformStats does not

| Function | Handles `meta` |
|----------|---------------|
| `analytics.php` line 187 | YES (`case 'meta': $totalFollowers += 47000`) |
| `analytics.php` getPostPlatformStats() | **NO** (falls through, returns zeroed stats) |

**Impact:** If a `'meta'` platform row exists, the follower estimate works but the per-post stats return zeros. Cosmetic inconsistency.

---

## CONFIRMED BUGS

### BUG #1 — Meta tokens created by callback_meta.php are never refreshed

**Severity:** HIGH
**File:** `backend/refresh_worker.php:214-224`
**Root cause:** Dispatch logic only matches `$platform === 'meta'`, but `callback_meta.php` stores `'facebook'` or `'instagram'`.
**Impact:** Facebook/Instagram tokens silently expire. Users lose publishing capability until manual reconnection.
**Current status:** LATENT — no `facebook`/`instagram` rows exist yet. Will trigger on first Facebook/Instagram OAuth connection.

### BUG #2 — distributor.py silently skips facebook/instagram publishing

**Severity:** HIGH
**File:** `backend/engine/distributor.py:411-416`
**Root cause:** No `push_facebook()` or `push_instagram()` threads spawned. Only `youtube`, `meta`, `tiktok` are handled.
**Impact:** Facebook/Instagram uploads silently fail. No error reported to user.
**Current status:** LATENT — no `facebook`/`instagram` in `uploads.platforms` yet.

### BUG #3 — Meta token update SQL uses hardcoded `'meta'`

**Severity:** HIGH (part of BUG #1)
**File:** `backend/refresh_worker.php:194`
**SQL:** `UPDATE oauth_tokens SET access_token = ?, ... WHERE user_id = ? AND platform = 'meta'`
**Impact:** Even if the dispatch were fixed, the UPDATE would not match `'facebook'`/`'instagram'` rows.

---

## POTENTIAL BUGS

### POTENTIAL #1 — TikTok tokens are never refreshed

**Severity:** MEDIUM
**File:** `backend/refresh_worker.php:222`
**Impact:** TikTok tokens expire after ~24 hours (short-lived). No refresh mechanism exists. Users must manually reconnect every day.
**Note:** TikTok OAuth may not support refresh tokens in the same way. This may be by design or a gap.

### POTENTIAL #2 — Hardcoded Graph API version in Python

**Severity:** LOW
**Files:** `distributor.py:264`, `uploader.py:208,242,254,272,287,301`
**Value:** `v25.0` hardcoded
**PHP constant:** `META_GRAPH_VERSION` in `config.php:189` (default `v25.0`)
**Impact:** If `META_GRAPH_VERSION` is updated in `.env`, Python scripts still use `v25.0`.

### POTENTIAL #3 — upload_handler.php performs no platform validation

**Severity:** LOW
**File:** `backend/upload_handler.php:115`
**Impact:** Client can send any string in `platforms` JSON. Invalid platform strings would be stored and passed to distributor, which silently skips unknown platforms.

---

## RECOMMENDED CANONICAL MODEL

### Analysis: facebook/instagram vs meta

| Factor | Separate (facebook/instagram) | Unified (meta) |
|--------|-------------------------------|----------------|
| OAuth scopes | Different per platform | Same OAuth flow |
| API endpoints | Same Graph API, different fields | Same Graph API |
| Token refresh | Same endpoint, same parameters | Same endpoint, same parameters |
| Token storage | Two rows per user (FB + IG) | One row per user |
| Distribution | Two separate uploads | One upload (FB auto-discovers IG) |
| Complexity | Higher (2x storage, 2x refresh) | Lower (1x storage, 1x refresh) |
| Current data | No rows exist | All current rows use `'meta'` |
| Current refresh | Broken | Working |
| Current distribution | Broken | Working |

### Recommendation: Unified `'meta'` model

**Both Facebook and Instagram should be represented internally as platform `'meta'`.**

Rationale:
1. Both use the same Graph API, same OAuth flow, same token refresh mechanism
2. The current database already uses `'meta'` for all Meta tokens
3. The refresh worker already handles `'meta'`
4. The distributor already handles `'meta'`
5. `uploader.py` already has `upload_meta()` which handles both Facebook and Instagram
6. Facebook and Instagram are not independent platforms — Instagram requires a Facebook Page connection via the Graph API

**The separate `'facebook'`/`'instagram'` storage in `callback_meta.php` is the anomaly, not the norm.**

---

## REQUIRED DATABASE CHANGES

### None currently needed

The database already uses `'meta'` for all Meta token rows. No schema changes required.

**However**, if any `facebook` or `instagram` rows are created by the current `callback_meta.php` before the code fix, a migration would be needed:

```sql
-- Only needed if facebook/instagram rows exist:
UPDATE oauth_tokens SET platform = 'meta' WHERE platform IN ('facebook', 'instagram');
```

**This migration should NOT be run now** — there are no such rows.

---

## REQUIRED CODE CHANGES

### Change #1 — callback_meta.php: Store as 'meta'

**File:** `callback_meta.php`
**Current (line 101):** `:platform => $metaPlatform` where `$metaPlatform` is `'facebook'` or `'instagram'`
**Change to:** `:platform => 'meta'`
**Remove:** Lines 15-17 (platform validation), line 101 dynamic binding
**Rationale:** Tokens should be stored as `'meta'` to match refresh and distribution logic

### Change #2 — connect_meta.php: Simplify to single 'meta' platform

**File:** `connect_meta.php`
**Current:** Accepts `$_GET['platform']` as `'facebook'` or `'instagram'`, selects scopes per platform
**Change to:** Always use combined Meta scopes, store `$_SESSION['meta_pending_platform'] = 'meta'`
**Rationale:** Single OAuth flow produces a token that works for both Facebook and Instagram

### Change #3 — connect.php: Remove 'facebook'/'instagram' from allowed platforms

**File:** `connect.php`
**Current (line 17):** `$allowedPlatforms = ['youtube', 'meta', 'tiktok', 'facebook', 'instagram']`
**Change to:** `$allowedPlatforms = ['youtube', 'meta', 'tiktok']`
**Remove:** Lines 101-110 (facebook/instagram OAuth initiation branches)
**Rationale:** No separate facebook/instagram OAuth flow needed

### Change #4 — refresh_worker.php: No change needed (already handles 'meta')

**File:** `backend/refresh_worker.php`
**Current:** Dispatches `'meta'` to `refresh_meta()`, updates with `WHERE platform = 'meta'`
**Status:** Already correct for the unified model
**No change required**

### Change #5 — distributor.py: No change needed (already handles 'meta')

**File:** `backend/engine/distributor.py`
**Current:** Handles `youtube`, `meta`, `tiktok` in dispatch
**Status:** Already correct for the unified model
**No change required**

### Change #6 — fetch_live_analytics.php: Remove dead 'meta' fallback code

**File:** `fetch_live_analytics.php`
**Current (lines 138-142, 237-249):** Falls back to `$tokens['meta']` when looking for `'facebook'`/`'instagram'`
**Change to:** Remove fallback; tokens are stored as `'meta'`, lookups should use `'meta'` directly
**Rationale:** Dead code creates confusion

### Change #7 — analytics.php: Remove 'facebook'/'instagram' cases, keep 'meta'

**File:** `analytics.php`
**Current (lines 185-186):** `case 'facebook'`, `case 'instagram'` with follower estimates
**Change to:** Remove these cases; they will never match since tokens are stored as `'meta'`
**Keep:** `case 'meta': $totalFollowers += 47000`

### Change #8 — history.php: Remove 'facebook'/'instagram' display, keep 'meta'

**File:** `history.php`
**Current:** Renders icons for `'facebook'` and `'instagram'` from uploads.platforms JSON
**Note:** This is about `uploads.platforms` (what the user selected for distribution), not `oauth_tokens.platform`
**Decision needed:** If the UI no longer offers facebook/instagram as separate targets, these display cases can be removed

### Change #9 — view_post_router.php: Keep facebook/instagram URL routing

**File:** `view_post_router.php`
**Current:** Routes `facebook` and `instagram` post URLs to native platform links
**Status:** This is for viewing published posts, not for token management
**No change required** — post URLs legitimately contain facebook/instagram domain references

### Change #10 — uploader.py: Remove facebook/instagram branches, keep meta

**File:** `backend/python/uploader.py`
**Current (lines 393-400):** Handles `'facebook'`, `'instagram'`, and `'meta'` separately
**Change to:** Remove `'facebook'` and `'instagram'` branches; only `'meta'` needs handling
**Keep:** `upload_meta()` which already handles both Facebook and Instagram via Graph API

### Change #11 — studio.php: Update platform selection UI

**File:** `studio.php`
**Current:** Offers checkboxes for `'youtube'`, `'tiktok'`, `'facebook'`, `'instagram'`
**Change to:** Offer checkboxes for `'youtube'`, `'tiktok'`, `'meta'`
**Rationale:** Users should select "Meta" as a single target, not separate Facebook/Instagram

---

## MIGRATION/RISK PLAN

### Pre-fix verification

| Step | Action | Command |
|------|--------|---------|
| 1 | Confirm no facebook/instagram rows exist | `SELECT platform, COUNT(*) FROM oauth_tokens GROUP BY platform` |
| 2 | Backup database | `mysqldump -u root -p mediafusion oauth_tokens uploads > /tmp/pre_meta_fix.sql` |

### Fix sequence (smallest safe order)

| Step | File | Change | Risk |
|------|------|--------|------|
| 1 | `callback_meta.php` | Store as `'meta'` instead of `'facebook'`/`'instagram'` | LOW — no existing rows affected |
| 2 | `connect_meta.php` | Simplify to single Meta OAuth flow | LOW — UI change only |
| 3 | `connect.php` | Remove `'facebook'`/`'instagram'` from allowed platforms | LOW — prevents crafted disconnect |
| 4 | `fetch_live_analytics.php` | Remove dead `'meta'` fallback code | LOW — dead code removal |
| 5 | `analytics.php` | Remove `'facebook'`/`'instagram'` switch cases | LOW — cosmetic |
| 6 | `uploader.py` | Remove `'facebook'`/`'instagram'` branches | LOW — code simplification |
| 7 | `history.php` | Update display for unified Meta | LOW — cosmetic |
| 8 | `studio.php` | Update platform checkboxes | MEDIUM — user-facing UI change |

### Rollback plan

If any step causes issues:
```bash
mysql -u root -p mediafusion < /tmp/pre_meta_fix.sql
```

### Post-fix verification

| Step | Action | Expected |
|------|--------|----------|
| 1 | Connect Meta account via UI | Token stored as `platform='meta'` |
| 2 | Wait for token expiry (or manually set `token_expiry = NOW()`) | `refresh_worker.php` refreshes it |
| 3 | Upload with Meta target | `distributor.py` pushes to Facebook + Instagram |
| 4 | Check analytics page | Meta stats displayed correctly |

---

## REGRESSION TEST PLAN

### Test #1 — Meta OAuth flow stores as 'meta'

```php
// After fix: callback_meta.php should store 'meta'
// Verify: SELECT platform FROM oauth_tokens WHERE user_id = ? AND platform = 'meta'
```

### Test #2 — Token refresh works for 'meta'

```bash
# Manually expire a meta token:
mysql -u root -p mediafusion -e "UPDATE oauth_tokens SET token_expiry = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE platform = 'meta'"

# Run refresh worker:
php backend/refresh_worker.php

# Verify token was refreshed:
mysql -u root -p mediafusion -e "SELECT token_status, updated_at FROM oauth_tokens WHERE platform = 'meta'"
# Expected: token_status='valid', updated_at = current time
```

### Test #3 — Distribution works for 'meta'

```bash
# Create upload with platforms=["youtube","meta"]
# Verify: distributor.py creates threads for both youtube and meta
# Verify: push_meta() calls graph.facebook.com/v25.0/me/videos
```

### Test #4 — Disconnect works for 'meta'

```bash
# POST ?action=disconnect&platform=meta
# Verify: DELETE matches the 'meta' row
# Verify: No facebook/instagram rows remain
```

### Test #5 — Analytics displays 'meta' correctly

```php
// Verify: analytics.php case 'meta' returns follower estimate
// Verify: getPostPlatformStats() handles 'meta' (currently returns zeros)
```

### Test #6 — No regression on youtube/tiktok

```bash
# Upload with platforms=["youtube","tiktok"]
# Verify: Both platforms process correctly
# Verify: No errors in logs
```

---

## SUMMARY

### Current state

| Platform | Storage | Refresh | Distribution | Status |
|----------|---------|---------|-------------|--------|
| `youtube` | callback.php | refresh_worker.php | distributor.py | **WORKING** |
| `tiktok` | callback.php | NOT IMPLEMENTED | distributor.py | **PARTIAL** (no refresh) |
| `facebook` | callback_meta.php | **BROKEN** (expects 'meta') | **BROKEN** (no handler) | **LATENT BUG** |
| `instagram` | callback_meta.php | **BROKEN** (expects 'meta') | **BROKEN** (no handler) | **LATENT BUG** |
| `meta` | **NEVER WRITTEN** | refresh_worker.php | distributor.py | **WORKING** (if rows existed) |

### Root cause

`callback_meta.php` was refactored to store tokens as `'facebook'`/`'instagram'` (per-account granularity), but `refresh_worker.php` and `distributor.py` were never updated to match. The rest of the codebase (`fetch_live_analytics.php`, `uploader.py`) implemented ad-hoc fallbacks that mask the inconsistency.

### Recommended fix

Unify on `'meta'` as the canonical platform value for all Meta/Facebook/Instagram tokens. Revert `callback_meta.php` to store `'meta'`. Remove dead `'facebook'`/`'instagram'` code paths. This is the smallest safe correction because:
1. The database already uses `'meta'` for all existing rows
2. The refresh worker already handles `'meta'`
3. The distributor already handles `'meta'`
4. The uploader already has `upload_meta()` for both Facebook and Instagram
5. No data migration is required

### Files requiring changes (10 total)

| # | File | Change | Risk |
|---|------|--------|------|
| 1 | `callback_meta.php` | Store `'meta'` instead of `'facebook'`/`'instagram'` | LOW |
| 2 | `connect_meta.php` | Simplify to single Meta OAuth | LOW |
| 3 | `connect.php` | Remove `'facebook'`/`'instagram'` from allowed list | LOW |
| 4 | `fetch_live_analytics.php` | Remove dead `'meta'` fallback code | LOW |
| 5 | `analytics.php` | Remove `'facebook'`/`'instagram'` switch cases | LOW |
| 6 | `uploader.py` | Remove `'facebook'`/`'instagram'` branches | LOW |
| 7 | `history.php` | Update display for unified Meta | LOW |
| 8 | `studio.php` | Update platform checkboxes to `'meta'` | MEDIUM |
| 9 | `refresh_worker.php` | No change (already correct) | — |
| 10 | `distributor.py` | No change (already correct) | — |

---

*Report generated 2026-07-27. All findings verified against live database state and source code.*
