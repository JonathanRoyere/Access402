# Access402

Access402 is a open source WordPress plugin designed for monetizing your content, API routes and files mainly from AI Agents using x402 payment rules.


## Installation

1. Place the plugin in `wp-content/plugins/Access402`.
2. Activate **Access402** from the WordPress plugins screen.
3. Open **Access402** in the WordPress admin.
4. Configure global settings first. (wallet, coinbase CDP key for live mode, default access behavior)
5. Add path rules.

## Admin tabs

### Settings

Global defaults live here:

- Test/live mode
- Keyless sandbox mode and required live CDP credentials for live
- Default currency and resolved network
- Default price
- Default unlock behavior
- Test/live wallets
- Logging toggle

### Rules

- Path / URL Pattern
- Price (overrides global)
- Unlock Behavior (overrides global)
- Active (checkbox)

### Access

Global bypass controls:

- WordPress role bypass
- Trusted wallets (not implemented yet)
- Trusted IPs (not implemented yet)

### Logs

Operational request view with filters for:

- Path
- Decision
- Mode

Note: This is still in the works so expect some bugs and missing features.

## Runtime behavior

When a WordPress-routed request comes in, Access402:

1. Builds request context from the current path, request method, user roles, wallet header aliases, and IP.
2. Checks global bypass in this order:
   - Bypass roles
   - Trusted wallets
   - Trusted IPs
3. Matches the first active rule from top to bottom. (Order priority coming)
4. Resolves effective values from global settings plus rule overrides.
5. Reuses an existing signed browser unlock grant when the unlock behavior allows it.
6. Verifies and settles `PAYMENT-SIGNATURE` requests through the active x402 facilitator when a client sends payment.
7. Frontend page requests render a checkout page with a wallet button that pays through a protected unlock endpoint.
8. Matching REST requests can return `402` directly and complete after a retried paid request.
9. Matching file links can be proxied through Access402 and streamed only after the shared payment flow allows them.
10. Logs bypass, payment-required, allowed, and runtime-error outcomes when logging is enabled.


### Real payment scope

- Frontend page requests render a small checkout page that calls a protected WordPress REST unlock endpoint.
- WordPress REST routes are challenged through the same shared payment flow as pages.
- Protected file downloads are served through an Access402-controlled URL that challenges, verifies, settles, and then streams the file.
- The browser client is bundled from the official `@x402/fetch@2.9.0` and `@x402/evm@2.9.0` packages.
- The browser flow uses the documented x402 pattern: `402` -> `PAYMENT-REQUIRED` -> wallet signing -> retry with `PAYMENT-SIGNATURE` -> `PAYMENT-RESPONSE`.
- Test mode settles against Base Sepolia `USDC`, using the public `x402.org` facilitator.
- Live mode settles against Base `USDC` through Coinbase CDP.
- Successful settlement issues a signed browser unlock grant so the original page request can complete after reload, including `per_request`.
- REST and other machine clients can still pay directly by sending `PAYMENT-SIGNATURE`.

### Path scope note

v1 intercepts normal WordPress requests and its own protected file proxy URLs. Matching attachment URLs and matching local file links rendered through WordPress content filters are rewritten to the protected download URL automatically. Raw static file URLs served directly by the web server outside those WordPress-controlled surfaces can still bypass the plugin.

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


## Known issues and upcoming features

- IP whitelisting (not developed/tested yet)
- Wallet Whitelisting (not developed/tested yet)
- AI vs Human detection (not developed/tested yet)
- Priority on rules (not developed/tested yet)

### Core architecture

- `src/Domain/*` contains canonical option providers.
- `src/Repositories/*` owns persistence and query logic.
- `src/Services/*` holds runtime/business rules.
- `src/Admin/AdminController.php` keeps admin orchestration thin.
- `src/Http/FileController.php` handles protected file proxy requests.
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
- `RuleMatcher`
- `AccessEvaluator`
- `RequestLogger`
- `ProtectedPaymentFlow`
- `ProtectedFileUrlService`
- `X402FacilitatorResolver`
- `X402FacilitatorClient`
- `X402PaymentProfileResolver`
- `CheckoutPageRenderer`

## Safe uninstall

Uninstall is non-destructive by default. Data is only removed when `ACCESS402_UNINSTALL_REMOVE_DATA` is defined and truthy, or when the `access402_cleanup_on_uninstall` filter returns `true`.

