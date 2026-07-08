# Changelog

All notable changes to `padosoft/askmydocs-connector-api` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). This project uses [Semantic Versioning](https://semver.org/).

---

## [1.0.0] — 2026-07-08

Initial release. A new connector paradigm for AskMyDocs: instead of ingesting
documents into the vector store, each configured HTTP **endpoint (Rotta)**
becomes a **live LLM tool** callable during a chat turn — so the model answers
from indexed history (RAG) **and** fresh data fetched in real time from the
customer's APIs.

### Added

- **Live-tool paradigm (Phase 1).** A `Connettore API` groups one or more
  `Rotte` (endpoints); each active Rotta is surfaced to the chat loop as a
  `{name, description, input_schema}` tool and dispatched server-side.
- **Five tenant-aware tables** — `api_connectors`, `api_auth_profiles`,
  `api_routes`, `api_route_parameters`, `api_tool_call_logs`. Every table
  carries `tenant_id` and its composite uniques are tenant-first (R30/R31).
- **Test → auto-configuration.** `ApiRouteTester` performs a real call,
  `SchemaInferrer` deduces the input/output schema, and
  `ToolDefinitionGenerator` (optionally assisted by the host-bound
  `ToolDescriptionAssistant`) produces the tool definition exposed to the LLM.
- **Runtime executor.** `ApiToolExecutor` resolves the binding, applies auth +
  fixed params, runs the HTTP call with per-Rotta timeout / retry-on-transient
  (never on 4xx) / backoff / cache TTL, transforms and byte-caps the output,
  writes a sanitised `api_tool_call_logs` row, and returns JSON — surfacing
  failures loudly rather than answering with an empty result.
- **Tool registry.** `ApiToolRegistry` builds the per-tenant, per-conversation
  index of active tools, honouring the `tools.max_per_conversation` cap.
- **SSRF protection.** `UrlGuard` validates every configured URL at test time
  **and** before each tool call: https-only (configurable), blocks
  private / loopback / link-local / reserved ranges + the cloud-metadata
  endpoint `169.254.169.254`, resolves hostnames to guard against DNS
  rebinding, and enforces an optional domain allowlist (exact host or
  subdomain). It is AskMyDocs's single outbound-URL chokepoint.
- **Six auth strategies** — none / api_key / bearer / basic / custom headers /
  OAuth2 client-credentials. Credentials are stored `encrypted:array`, marked
  `$hidden`, and are never surfaced to the LLM.
- **Parameter model** — each parameter has a **location** (`path` | `query` |
  `header` | `body`) and a **source** (`llm` exposed to the model | `fixed`
  constant | `secret` credential reference).
- **Tri-surface exposure (R44)** — the same `ConnectorAdminService` core is
  reachable via Artisan (`api-connector:list`, `api-connector:test`), the
  admin HTTP routes, and the host's MCP `ApiConnectorsTool`.
- **Chat-tool injection gate (R43)** — `chat_tools.enabled` master switch; when
  off, no API tools are injected and the rest of the package (config, test,
  try) still works.
- **Config knobs** in `config/connector-api.php`: `ssrf.*`, `output.max_bytes`,
  `chat_tools.enabled`, `tools.max_per_conversation`, `defaults.*`
  (timeout / retry / backoff / cache), `llm_assist.enabled`, and
  `routes.*`.
- **Publish tags** — `api-connector-migrations`, `api-connector-config`,
  `api-connector-routes`, `api-connector-assets` (brand icon).
- **Quality gates** — PHPStan level 8, Pint, and a CI matrix of
  PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13. R30/R31 tenant isolation is asserted
  by the package's own test suite.

### Dependencies

- Requires `padosoft/askmydocs-connector-base` `^1.4` for the `BelongsToTenant`
  trait, `TenantContext`, and the connector registry primitives.
- `illuminate/*` `^12.0|^13.0`, `nesbot/carbon` `^2.0|^3.0`, `php` `^8.3`.

### Compatibility

- **Phase 1 (live tools) is implemented.** **Phase 2 (ingest)** is reserved via
  the `mode` column (`RouteMode`) only — no ingest path ships in this release.
- The admin UI is provided by the host application (React SPA over the JSON
  API); this package ships no Blade views by design.

[1.0.0]: https://github.com/padosoft/askmydocs-connector-api/releases/tag/v1.0.0
