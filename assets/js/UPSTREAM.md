# Access402 Browser Checkout Provenance

The bundled browser checkout client in `assets/js/frontend-checkout.js` is compiled from:

- local source: `assets/js/src/frontend-checkout.js`
- official package: `@x402/fetch@2.9.0`
- official package: `@x402/evm@2.9.0`

Upstream package metadata:

- `@x402/fetch@2.9.0`
  - npm tarball: `https://registry.npmjs.org/@x402/fetch/-/fetch-2.9.0.tgz`
  - repository: `https://github.com/x402-foundation/x402`
- `@x402/evm@2.9.0`
  - npm tarball: `https://registry.npmjs.org/@x402/evm/-/evm-2.9.0.tgz`
  - repository: `https://github.com/x402-foundation/x402`

Build command:

```bash
npm run build:checkout
```

The checked-in bundle should be reproducible from the pinned dependencies in `package-lock.json`.
