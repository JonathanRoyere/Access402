# Access402 agent skill

Access402 adds x402 v2 payment protection to existing HTTP applications so teams can price access to content, files, workflows, and API endpoints in USDC. It preserves the application's role as the origin while adding browser-authorized setup, code-owned route policy, challenge diagnostics, and supported enforcement adapters.

WordPress is the first CMS adapter, not the entire product. Access402 also supports a native FastAPI integration and a Universal HTTP Gateway path for other HTTP stacks.

## How the pieces fit together

- **This public repository** is the canonical, auditable source for the Access402 coding-agent instructions. There is exactly one source skill: [`skills/access402/SKILL.md`](skills/access402/SKILL.md).
- **`@access402/cli`** installs the canonical skill into supported agent directories and provides deterministic `init`, `doctor`, `test`, `gateway`, and `mcp` commands.
- **Access402 browser authorization** handles device sign-in and installation approval outside chat and the terminal. Agents must never collect dashboard sessions, installation credentials, wallet private keys, seed phrases, or Coinbase credentials.
- **Native adapters** enforce policy inside a supported application. The skill currently documents FastAPI and the official WordPress plugin.
- **Universal HTTP Gateway** is the fixed-origin enforcement option for other HTTP stacks. It reads code-owned route declarations and must be paired with origin-authorization and bypass prevention before Live use.
- **MCP diagnostics** are an optional, read-only inspection surface. Authorization and installation changes remain in the visible CLI and browser flow.

The public skill contains no credentials. Generated installation configuration and secrets remain server-side in the customer application and must stay out of Git, client bundles, logs, and agent messages.

## Canonical layout

```text
skills/
└── access402/
    ├── SKILL.md
    └── agents/
        └── openai.yaml
```

Do not add source copies under `.agents/skills` or `.claude/skills` in this repository. Those are installation destinations in customer repositories:

```text
# Codex
.agents/skills/access402/

# Claude Code
.claude/skills/access402/
```

Install with the published CLI from the customer application's root:

```bash
npx @access402/cli skill install --agent codex
npx @access402/cli skill install --agent claude-code
```

Compatible agents may consume the canonical `skills/access402/` directory using their standard Agent Skills installation mechanism.

## Sandbox-first example

New integrations start on Base Sepolia (`eip155:84532`) with test USDC:

```bash
npx @access402/cli init --mode sandbox \
  --route "GET /api/report 0.02" \
  --json

npx @access402/cli doctor --json
npx @access402/cli test https://api.example.com/api/report \
  --method GET \
  --json
```

For an unsupported HTTP framework, run the generated fixed-origin Gateway configuration:

```bash
npx @access402/cli gateway
```

For optional read-only diagnostics in an MCP-capable coding agent:

```bash
npx @access402/cli mcp
```

Challenge verification does not spend funds. A complete Sandbox payment requires test USDC in the buyer wallet and that wallet's signature. The Coinbase CDP Faucet can fund a public buyer address on Base Sepolia; never share a private key or seed phrase.

Live mode is never inferred. Follow the skill's Live safeguards and enable it only after an explicit user request and deployment review.

## Validate

The repository has no runtime dependencies. With Node.js 20 or newer:

```bash
npm test
```

The validator checks the canonical skill, metadata, line budget, public networks and USDC contracts, required safety language, absence of common secret patterns, uniqueness of `SKILL.md`, and package publication protection.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). Changes to Access402 behavior belong in the canonical skill first; installation tooling should consume that source rather than embedding another copy.

## License

Licensed under the [MIT License](LICENSE).
