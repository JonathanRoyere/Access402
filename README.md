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
- Structured `PAYMENT-REQUIRED` responses for matched frontend and REST paths
- Real `PAYMENT-SIGNATURE` verification and settlement through x402 facilitators
- Automatic testnet facilitator fallback to `x402.org` when test CDP credentials are blank
- Browser checkout flow built on the official `@x402/fetch` and `@x402/evm` libraries

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
- Optional test CDP credentials and required live CDP credentials
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
5. Reuses an existing signed browser unlock grant when the unlock behavior allows it.
6. Verifies and settles `PAYMENT-SIGNATURE` requests through the active x402 facilitator when a client sends payment.
7. Frontend page requests render a checkout page with a wallet button that pays through a protected unlock endpoint.
8. Logs bypass, payment-required, allowed, and runtime-error outcomes when logging is enabled.

### Important v1 runtime note

The admin and domain model intentionally still support `USDC`, `ETH`, and `SOL` because that is the broader product surface defined for v1. The real settled browser/runtime flow in this codebase is intentionally narrower today: it supports `USDC` on Base Sepolia in test mode and `USDC` on Base in live mode. If a rule resolves to `ETH` or `SOL`, the runtime now fails honestly with a configuration/runtime message instead of pretending an unsupported settlement happened.

### Real payment scope

- Frontend page requests render a small checkout page that calls a protected WordPress REST unlock endpoint.
- The browser client is bundled from the official `@x402/fetch@2.9.0` and `@x402/evm@2.9.0` packages.
- The browser flow uses the documented x402 pattern: `402` -> `PAYMENT-REQUIRED` -> wallet signing -> retry with `PAYMENT-SIGNATURE` -> `PAYMENT-RESPONSE`.
- Test mode settles against Base Sepolia `USDC`, using the public `x402.org` facilitator by default or Coinbase CDP when both test credentials are configured.
- Live mode settles against Base `USDC` through Coinbase CDP.
- Successful settlement issues a signed browser unlock grant so the original page request can complete after reload, including `per_request`.
- REST and other machine clients can still pay directly by sending `PAYMENT-SIGNATURE`.

### Path scope note

v1 intercepts requests that flow through WordPress. Direct static files served outside WordPress are not automatically proxied by this plugin.

## Architecture notes

### Folder structure

```text
access402.php
assets/
  css/
  js/
    UPSTREAM.md
    src/
package.json
package-lock.json
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
- `src/Http/RuntimeController.php` handles page and generic REST interception.
- `src/Http/UnlockController.php` exposes the protected REST unlock endpoint used by the browser checkout button.

### Upstream dependency provenance

- `assets/js/frontend-checkout.js` is bundled from the official npm packages `@x402/fetch@2.9.0` and `@x402/evm@2.9.0`.
- The local wrapper source lives in `assets/js/src/frontend-checkout.js`.
- Provenance details are recorded in `assets/js/UPSTREAM.md`.
- Rebuild with `npm run build:checkout`.

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
- `ProtectedPaymentFlow`
- `X402FacilitatorResolver`
- `X402FacilitatorClient`
- `X402PaymentProfileResolver`
- `CheckoutPageRenderer`

## Safe uninstall

Uninstall is non-destructive by default. Data is only removed when `ACCESS402_UNINSTALL_REMOVE_DATA` is defined and truthy, or when the `access402_cleanup_on_uninstall` filter returns `true`.

## Scalability decisions already baked into v1

- Shared option providers
- Resolver-based override handling
- Path-first rules for simpler evolution
- Global currency/network/wallet model
- Reusable summary builder
- OOP repositories/services separation
