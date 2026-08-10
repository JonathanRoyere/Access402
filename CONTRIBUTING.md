# Contributing

Thank you for improving the Access402 agent skill.

## Scope

Keep this repository limited to the canonical public skill, its agent metadata, public documentation, and validation. Do not add dashboard source, private architecture, customer data, credentials, private URLs, generated output, or a second `SKILL.md`.

The source skill lives at `skills/access402/SKILL.md`. `.agents/skills/access402/` and `.claude/skills/access402/` are customer-repository installation destinations and must not be committed here.

## Make a change

1. Update the canonical skill or its public documentation.
2. Keep `SKILL.md` focused and below 500 lines.
3. Preserve Sandbox as the default and x402 version 2 as the only protocol version.
4. Keep credentials server-side and use placeholders in examples.
5. Run `npm test` with Node.js 20 or newer.
6. Review the diff for secrets and private implementation details before opening a pull request.

Behavior documented here should match the published `@access402/cli` command surface. Coordinate any required installer changes separately in the private CLI repository; do not copy private implementation into this public repository.
