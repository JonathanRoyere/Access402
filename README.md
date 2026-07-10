# Access402

Access402 is a TypeScript package suite for making x402 payments safer to run in production.

It is designed for API sellers who want to charge for endpoints without having to hand-roll payment state, idempotency, retry protection, receipts, and settlement recovery around the base x402 flow.

## What This Project Will Be

Access402 keeps the public x402 experience standard:

1. Buyer makes a normal HTTP request.
2. Seller returns `402 Payment Required`.
3. Buyer signs a payment payload.
4. Buyer retries with the payment signature.
5. Seller verifies and settles the payment.
6. Seller returns the paid resource.

Access402 does not try to replace that protocol. Its job is to add the production layer around it:

- Route protection
- Idempotency
- Duplicate retry protection
- Payment state tracking
- Receipt creation
- Settlement safety
- Recovery for unresolved payments
- Better logs and errors

## Planned Package Structure

```text
packages/
  core/
  express/
  storage-memory/
  storage-redis/
  fetch/
  testing/
```

### `@access402/core`

The payment engine.

This package is responsible for:

- Route matching
- Payment parsing
- Payment fingerprinting
- Payment state transitions
- Idempotency decisions
- Receipt creation
- Storage and facilitator interfaces

### `@access402/express`

The Express adapter for sellers.

This package is responsible for:

- Reading Express requests
- Calling the core payment gate
- Returning 402 challenges
- Allowing paid requests through
- Attaching payment context to the request

### `@access402/storage-memory`

The local development store.

This package is responsible for:

- In-memory locks
- In-memory payment records
- In-memory receipts

### `@access402/storage-redis`

The first production storage adapter.

This package is responsible for:

- Atomic locks
- Persistent payment state
- Receipt persistence
- Unresolved payment tracking

### `@access402/fetch`

An optional buyer helper.

This package is responsible for:

- Detecting 402 responses
- Reading payment requirements
- Signing a payment payload
- Retrying with the payment signature

### `@access402/testing`

Testing helpers for the whole repo.

This package is responsible for:

- Fake facilitators
- Fake payment payloads
- Failure-state simulations
- Replay and retry test helpers

## How The Pieces Fit Together

The project is split on purpose:

- `core` contains the payment rules
- `express` connects those rules to a web server
- `storage-*` packages store payment state
- `fetch` helps buyers pay automatically
- `testing` helps validate the behavior safely

That separation keeps the important logic framework-independent.

## Current Status

Access402 is in early development and is currently a structural scaffold.

Currently implemented:

- Monorepo package layout
- Root npm workspace configuration
- Shared TypeScript configuration
- Package-level TypeScript project references
- Package manifests and export surfaces
- Structural smoke test for repo shape

Not implemented yet:

- Core payment logic
- Storage interfaces and adapters
- Express integration behavior
- Fetch payment retry behavior
- Testing helpers beyond the workspace scaffold
- Real facilitator adapter
- Real Redis adapter
- Final x402 wire-format compatibility
- Full end-to-end HTTP example app
- Recovery jobs for unresolved payments
- Full automated test suite

## Local Development

Install and build:

```bash
npm install
npm run build
```

Run a smoke test:

```bash
npm run smoke
```

Structural smoke with any scenario name currently behaves the same way and only checks repo layout:

```bash
npm run smoke -- success
```

## Repo Structure

```text
.
├── package.json
├── tsconfig.base.json
├── tsconfig.json
├── packages/
│   ├── core/
│   ├── express/
│   ├── fetch/
│   ├── storage-memory/
│   ├── storage-redis/
│   └── testing/
└── work/
    └── smoke-test.mjs
```

## Near-Term Goal

The first meaningful milestone is simple:

An Express API seller should be able to protect one paid route, verify and settle a valid payment, reject invalid or duplicate attempts, and store payment state safely.
