# Tiknix Bug Report - Regression Testing

**Date:** 2025-12-28
**Tester:** Automated (Claude Code + Playwright MCP)
**Environment:** OpenSwoole + FlightPHP

---

## Critical Bugs

### BUG-001: CSRF Token Not Shared Between Native Auth and FlightPHP
**Severity:** CRITICAL
**Affects:** All form submissions (MCP Registry, API Keys, Teams, Workbench)

**Description:** After logging in via native OpenSwoole auth handler (`/auth/login`), the CSRF token is not properly shared with FlightPHP routes. All forms display "Invalid CSRF token" error and form submissions silently fail.

**Steps to Reproduce:**
1. Login via `/auth/login`
2. Navigate to any form (e.g., `/mcpregistry/add`, `/apikeys/add`)
3. Fill form and submit
4. Form stays on same page with no success/error message

**Expected:** Form should submit successfully
**Actual:** Form submission fails silently, stays on same page

**Root Cause:** The CSRF library uses `$_SESSION` which is managed differently between native Swoole handlers and FlightPHP. The CSRF token generated in FlightPHP routes doesn't match what's expected because session state isn't fully synchronized.

---

### BUG-002: Create Pages Return 404 (Teams, Workbench)
**Severity:** HIGH
**Affects:** `/teams/create`, `/workbench/create`

**Description:** Create pages show 404 error inside the page body while header renders correctly.

**Steps to Reproduce:**
1. Login as admin
2. Navigate to `/teams` or `/workbench`
3. Click "Create Team" or "New Task"
4. Page shows heading but body contains 404 error

**Log Error:** `Controller error: Undefined array key "name"`

**Root Cause:** Views are trying to access `$member['name']` but the member array from session doesn't have a 'name' key (it has 'username' instead).

---

## High Priority Bugs

### BUG-003: Wrong URL Paths in Admin Panel
**Severity:** HIGH
**Affects:** Admin panel MCP Registry links

**Description:** Links point to `/mcp/registry` but correct path is `/mcpregistry`

**Affected Links:**
- Admin Panel > MCP Server Registry → `/mcp/registry` (WRONG)
- MCP Registry page > Add Server → `/mcp/registry/add` (WRONG)
- MCP Registry page > Back to List → `/mcp/registry` (WRONG)
- API Keys page > MCP Registry → `/mcp/registry` (WRONG)

**Expected:** Should use `/mcpregistry`, `/mcpregistry/add`, etc.

---

### BUG-004: JavaScript Error on API Keys Page
**Severity:** MEDIUM
**Affects:** `/apikeys`

**Description:** Console error: `ReferenceError: bootstrap is not defined`

**Location:** Line 304 of the API keys page

**Impact:** May affect Bootstrap-dependent UI functionality (modals, tooltips, etc.)

---

## Medium Priority Bugs

### BUG-005: Inconsistent Controller Naming
**Severity:** MEDIUM
**Affects:** MCP Registry routes

**Description:** Controller is `Mcpregistry` but some views/links expect `mcp/registry` path format.

**Recommendation:** Either:
1. Update all links to use `/mcpregistry/*` paths, OR
2. Add route aliases for `/mcp/registry/*` → `Mcpregistry` controller

---

## Low Priority / Cosmetic Issues

### BUG-006: Member Since Date Shows "January 1, 1970"
**Severity:** LOW
**Affects:** Dashboard

**Description:** Member registration date displays Unix epoch instead of actual date.

---

## Test Results Summary

| Feature | Status | Notes |
|---------|--------|-------|
| Login | PASS | Native Swoole auth works |
| Logout | PASS | Session cleared correctly |
| Dashboard | PASS | Loads correctly |
| Admin Panel | PASS | Stats display correctly |
| MCP Registry List | PASS | Shows registered servers |
| MCP Registry Create | FAIL | CSRF + wrong URL |
| Teams List | PASS | Empty state works |
| Teams Create | FAIL | 404 - missing 'name' key |
| Workbench List | PASS | Filters work |
| Workbench Create | FAIL | 404 - missing 'name' key |
| Member Profile | PASS | All info displayed |
| API Keys List | PASS | Shows existing keys |
| API Keys Create | FAIL | CSRF blocking |
| Contact Form | PASS | Submission works |

---

## Recommended Fixes Priority

1. **Fix CSRF token sharing** - Most critical, blocks all CRUD operations
2. **Fix member 'name' key access** - Blocks Teams and Workbench creation
3. **Fix URL paths** - Update to use `/mcpregistry/*`
4. **Fix Bootstrap JS loading** - Check script load order
