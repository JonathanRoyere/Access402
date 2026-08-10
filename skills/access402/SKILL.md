---
name: access402
description: Add, repair, verify, or prepare deployment of Access402 x402 v2 payments in an existing application. Use with Codex, Claude Code, and compatible coding agents for paid APIs, paid routes, USDC access, Access402 adapters, Sandbox payment tests, challenge verification, or one-prompt monetization; do not use for unrelated payment providers.
---

# Access402 integration

Add Access402 to the existing application and leave every requested route challenge-verified in Sandbox. Stop only for Access402 browser authorization, a required wallet signature, or a material deployment choice that cannot be inferred safely.

## Non-negotiable defaults

- Use x402 version 2 only.
- Default every new integration to Sandbox on `eip155:84532` with Base Sepolia USDC `0x036CbD53842c5426634e7929541eC2318f3dCF7e`.
- Use Live only after the user explicitly requests it. Live is `eip155:8453` with Base USDC `0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913`.
- Keep credentials and installation secrets server-side. Never ask for or accept private keys, seed phrases, Coinbase credentials, dashboard JWTs, or installation API keys.
- Preserve unrelated work and existing application behavior outside the declared protected routes.
- Treat repository route declarations as the source of truth. Do not create duplicate policies or duplicate adapters.

## 1. Inspect before changing

Inspect the repository root, current changes, framework, package manager, launch command, route registration, authentication, reverse proxies, deployment files, public URL configuration, and secret-loading behavior.

Search for existing Access402 artifacts before initializing:

- `access402.yaml` and `.env.access402`
- `@access402/cli`, `access402-fastapi`, or an Access402 WordPress plugin
- `Access402.from_env`, `@access402.protect`, `verifyOriginAuthorization`, or Gateway commands
- existing route, middleware, proxy, and deployment declarations

If Access402 is already configured, run this first:

```bash
npx @access402/cli doctor --json
```

Reuse and repair a healthy installation. Never initialize a second installation merely because setup is run again.

## 2. Declare routes and prices

Convert the user's request into explicit method, canonical path, USDC price, and access-mode declarations. Default to `per_request`. Ask only if a route or price is materially ambiguous.

Initialize once from the application root, repeating `--route` for each policy:

```bash
npx @access402/cli init --mode sandbox \
  --route "GET /api/report 0.02" \
  --route "POST /api/analyze 0.05" \
  --json
```

Pass `--public-url` or `--origin` only when repository or running-server detection cannot infer it safely. Do not hand-edit generated identifiers, receiving wallets, assets, or network values without validating them against the authorized installation.

### Prevent route ambiguity

Canonicalize URLs before comparing or protecting them:

1. Parse with a standards-compliant URL parser.
2. Compare normalized methods and pathnames, never raw string prefixes.
3. Reject duplicate slashes, encoded slashes or backslashes, dot segments, invalid percent encoding, query-string route identities, and invalid route templates.
4. Use one canonical trailing-slash policy and ensure the application, proxy, and Access402 declaration agree.
5. Detect overlapping templates and duplicate method/path policies; stop rather than silently choosing one.
6. Verify exact amount, network, asset, and receiving wallet for every declaration.

Do not protect health checks, browser authorization callbacks, static assets, or other operational endpoints unless the user explicitly names them.

## 3. Complete device authorization

Run `init` and let the user complete Access402 device authorization in the browser. The browser is the boundary for sign-in and approval.

Never request that the user paste a dashboard session, dashboard JWT, Coinbase credential, wallet private key, seed phrase, installation API key, or other secret into chat or the terminal. It is acceptable to request a buyer's public wallet address when a Sandbox balance check requires it.

Load `.env.access402` only in the server process and ensure Git ignores it. Never expose it through frontend environment prefixes, client bundles, source files, logs, exception bodies, test snapshots, generated build output, or agent messages.

## 4. Detect and apply one adapter

Prefer a maintained native adapter when the framework is supported. Use the Universal HTTP Gateway for other HTTP stacks. Do not combine a native adapter and Gateway protection for the same route.

### FastAPI

Install `access402-fastapi` using the repository's existing Python package manager. Follow this order:

```python
from access402_fastapi import Access402

access402 = Access402.from_env()

@app.get("/api/report")
@access402.protect(price="0.02", access="per_request")
async def report():
    ...

access402.install(app)
```

Place `@access402.protect(...)` below each FastAPI route decorator so decorator application order is correct. Call `access402.install(app)` only after all protected routes are registered. Keep configuration in server environment variables, not Python source.

### WordPress

Use the official Access402 WordPress plugin and its server-side installation credential flow. Treat WordPress as the first CMS adapter, not as the complete Access402 product. Do not reproduce payment gating in theme code, client JavaScript, snippets, or an unrelated plugin.

Map the requested WordPress paths or resources to one canonical rule each. Confirm caching layers do not serve protected content without a valid settlement and preserve `Cache-Control: private, no-store` on protected responses.

### Universal HTTP Gateway

For frameworks without a native adapter, use the generated `access402.yaml` and run the fixed-origin Gateway:

```bash
npx @access402/cli gateway
```

Add that command to the existing process or deployment definition. Configure one fixed origin; do not build an open proxy or let request input select the upstream host.

Before Live, require origin authorization in the application. Node applications can import `verifyOriginAuthorization` from `@access402/cli/gateway`; other runtimes must implement the documented HMAC verification equivalently. Reject requests missing a valid `X-Access402-Origin-Authorization` token. Ensure the public deployment reaches protected routes only through the Gateway and cannot bypass it through a second hostname, direct service URL, or public origin port.

Do not claim Live readiness while the origin remains reachable around the Gateway.

## 5. Fail closed

Protected routes must fail closed when configuration is missing or invalid, Access402 settlement is unavailable, Coinbase verification cannot complete, the network or asset differs, the amount differs, the receiving wallet differs, or origin authorization fails. Return an unavailable or payment failure response without running the protected handler.

Keep public routes reachable. Do not expose protected response bodies through error handling, shared caches, retries, previews, logs, or fallback origins. Preserve `Cache-Control: private, no-store` for protected responses.

Do not implement a facilitator locally. Coinbase/CDP credentials belong only in Access402-managed server-side secrets.

## 6. Validate the application

Start or restart the application through its normal development command. Run:

```bash
npx @access402/cli doctor --json
npx @access402/cli test <protected-url> --method <METHOD> --json
```

Run the route test for every protected method and canonical path. A successful challenge test must confirm an x402 v2 payment-required response with the expected network, USDC asset, exact amount, and receiving address. A generic HTTP 402 response is not sufficient.

For `POST`, `PUT`, or `PATCH`, derive a safe representative body from the application's schema, record it as `input_example` in `access402.yaml` when the Gateway uses that route, and test the exact method and body. Never submit payment for a body-bearing route with an unknown or guessed payload. The CLI supports an explicit body:

```bash
npx @access402/cli test https://api.example.com/analyze \
  --method POST \
  --body '{"topic":"x402"}' \
  --json
```

Fix doctor or challenge failures and rerun the checks. Use the optional read-only MCP diagnostics surface only for inspection:

```bash
npx @access402/cli mcp
```

Keep authorization and installation mutations in the CLI/browser flow.

## 7. Fund and test Sandbox payments

A challenge test does not spend funds. A full Sandbox payment requires a funded buyer wallet and a signature from that wallet. The receiving project wallet does not need faucet funds.

If the buyer lacks funds:

1. Ask only for the buyer's public address.
2. Check it with `npx @access402/cli test <url> --payer <0x-address> --json`.
3. Direct the user to the Coinbase CDP Faucet at `https://portal.cdp.coinbase.com/products/faucet`.
4. Tell the user to select Base Sepolia and request test USDC. Request Base Sepolia ETH too only if the wallet or buyer client requires gas.
5. Wait for funding, then let the user's wallet or x402 buyer client create the payment signature.
6. Rerun the exact route test and record whether a complete Sandbox payment succeeded.

Never fund the Access402 receiving wallet instead of the buyer wallet. Never handle the buyer's private key or seed phrase.

## 8. Guard Live mode

Enable Live only when the user explicitly requests a Live deployment and has approved the deployment target, routes, prices, asset, receiving wallet, and origin topology.

Set `ACCESS402_MODE=live` in server secrets and update the matching repository configuration together. Never infer Live from a dashboard state, branch name, hostname, environment name, or existing mainnet wallet.

Before reporting Live readiness, require all of the following:

- doctor passes on the deployed configuration;
- every protected route returns the expected Live x402 v2 challenge;
- canonical URL and duplicate-route checks pass;
- secrets remain server-side and absent from Git, logs, and client output;
- protected responses fail closed and are not shared-cacheable;
- Gateway origins, when used, reject bypass traffic;
- a Live payment is attempted only with the user's explicit approval.

## 9. Report the result

Finish with a concise report containing:

- detected adapter and why it was selected;
- files changed;
- protected methods, canonical paths, access modes, and exact USDC prices;
- public URL, network identifier, USDC contract, and receiving address;
- doctor result and each challenge-test result;
- Sandbox or Live payment-test status, clearly distinguishing challenge verification from settlement;
- deployment or origin-bypass safeguards;
- remaining user actions or unverified assumptions.

Redact every secret. Never print private keys, seed phrases, Coinbase credentials, dashboard JWTs, installation API keys, origin authorization secrets, or complete sensitive environment values.
