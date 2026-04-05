# Access402 v1

Access402 is a production-minded WordPress plugin for monetizing WordPress-routed paths with x402-style payment rules, global defaults, and trusted bypass controls.

## What ships in v1

- OOP-only PHP 8.1+ plugin architecture
- Custom tables for rules, trusted wallets, trusted IPs, and request logs
- Top-level admin tabs: Settings, Rules, Access, Logs
- Global settings model with one source of truth in the WordPress options API
- Path-based rules with exact and `*` wildcard matching
- Drag-and-drop rule ordering with top-to-bottom precedence
- Trusted role, wallet, and IP bypass before payment checks
- Structured 402 challenge responses for matched frontend and REST paths
- Coinbase CDP connection testing with signed JWT requests

## Installation

1. Place the plugin in `wp-content/plugins/Access402`.
2. Activate **Access402** from the WordPress plugins screen.
3. Open **Access402** in the WordPress admin.
4. Configure global settings first.
5. Add path rules in the order you want them evaluated.

## Admin tabs

### Settings

Global defaults live here:

- Test/live mode
- Coinbase CDP credentials
- Connection testing per environment
- Default currency and resolved network
- Default price
- Default unlock behavior
- Test/live wallets
- Logging toggle

### Rules

Rules are intentionally path-first in v1:

- Exact path support
- `*` wildcard support
- Table order decides precedence
- Nullable price and unlock overrides
- Slide-over add/edit flow with live summary preview

### Access

Global bypass controls:

- WordPress role bypass
- Trusted wallets
- Trusted IPs

### Logs

Operational request view with filters for:

- Path
- Decision
- Mode

## Runtime behavior

When a WordPress-routed request comes in, Access402:

1. Builds request context from the current path, request method, user roles, wallet header aliases, and IP.
2. Checks global bypass in this order:
   - Bypass roles
   - Trusted wallets
   - Trusted IPs
3. Matches the first active rule from top to bottom.
4. Resolves effective values from global settings plus rule overrides.
5. Returns a structured 402 challenge payload for matched requests.
6. Logs bypass, payment-required, and runtime-error outcomes when logging is enabled.

### Important v1 runtime note

The admin and domain model intentionally support `USDC`, `ETH`, and `SOL` because that is the product surface defined for v1. CDP’s clearly documented facilitator examples are narrower than that model today, so the runtime stays honest: it emits structured challenge data and clean request evaluation instead of pretending every configured combination has full facilitator settlement wired in. That keeps v1 production-safe while leaving the service layer ready for a stricter settlement implementation in a later version.

### Path scope note

v1 intercepts requests that flow through WordPress. Direct static files served outside WordPress are not automatically proxied by this plugin.

## Architecture notes

### Folder structure

```text
access402.php
assets/
  css/
  js/
src/
  Admin/
  Database/
  Domain/
  Http/
  Repositories/
  Services/
  Support/
templates/
  admin/
uninstall.php
```

### Core architecture

- `src/Domain/*` contains canonical option providers.
- `src/Repositories/*` owns persistence and query logic.
- `src/Services/*` holds runtime/business rules.
- `src/Admin/AdminController.php` keeps admin orchestration thin.
- `src/Http/RuntimeController.php` handles path evaluation and challenge responses.

### Key services

- `SettingsRepository`
- `RuleRepository`
- `TrustedWalletRepository`
- `TrustedIpRepository`
- `LogRepository`
- `EffectiveRuleConfigResolver`
- `RuleSummaryBuilder`
- `WalletValidator`
- `ProviderConnectionTester`
- `RuleMatcher`
- `AccessEvaluator`
- `RequestLogger`

## Safe uninstall

Uninstall is non-destructive by default. Data is only removed when `ACCESS402_UNINSTALL_REMOVE_DATA` is defined and truthy, or when the `access402_cleanup_on_uninstall` filter returns `true`.

## Scalability decisions already baked into v1

- Shared option providers
- Resolver-based override handling
- Path-first rules for simpler evolution
- Global currency/network/wallet model
- Reusable summary builder
- OOP repositories/services separation
