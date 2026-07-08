# Architecture — askmydocs-connector-api

Design notes for the API connector. User-facing documentation lives in
[`../README.md`](../README.md); this file records the internal design and the
rationale behind the non-obvious decisions.

## Paradigm

The ingest connectors (`padosoft/askmydocs-connector-*`) do
*download → chunk → embed*. The API connector inverts that: each configured
HTTP **endpoint (Rotta)** becomes a **live LLM tool**. During a chat turn the
model has two parallel sources — indexed history (RAG) **and** fresh data
fetched in real time from the customer's APIs. Nothing is stored in the vector
store in Phase 1.

```
Connettore API ("Gestionale Cliente X")
 ├─ Rotta "Ordini"    → Tool get_orders    (GET https://api.clientex.com/v1/orders)
 ├─ Rotta "Clienti"   → Tool get_customers
 └─ Rotta "Magazzino" → Tool get_stock
```

## Data model (5 tenant-aware tables)

| Table | Holds |
|---|---|
| `api_connectors` | a connector (a logical grouping + `mode`) |
| `api_auth_profiles` | reusable auth material — `credentials` is `encrypted:array`, `$hidden` |
| `api_routes` | a Rotta = one endpoint = one tool (url, method, schemas, tool_definition, status, mode) |
| `api_route_parameters` | per-route params — `location` × `source` |
| `api_tool_call_logs` | one sanitised row per tool execution |

Every table carries `tenant_id` and its composite uniques are tenant-first
(R30/R31). Cross-tenant isolation is enforced at the query layer via the
`BelongsToTenant::forTenant()` scope from `connector-base`.

## Pipeline

1. **Connect** — create a `Connettore` + a `Rotta` (endpoint + auth profile +
   parameters).
2. **Test → auto-config** — `ApiRouteTester` performs a real call through
   `UrlGuard`; `SchemaInferrer` deduces the input/output schema;
   `ToolDefinitionGenerator` (optionally via the host `ToolDescriptionAssistant`)
   emits the `{name, description, input_schema}` tool definition.
3. **Expose** — `ApiToolRegistry` lists a tenant's active tools (status =
   active, mode ∈ {tool, both}), capped by `tools.max_per_conversation`; the
   host merges them into its chat tool index, gated by `chat_tools.enabled`.
4. **Execute** — `ApiToolExecutor` resolves the binding, applies auth + fixed
   params, runs the HTTP call (timeout / retry-on-transient / backoff / cache),
   transforms + byte-caps the output, logs the call, returns JSON.

## Security chokepoint

`UrlGuard` is AskMyDocs's single outbound-URL guard. It runs both at
configuration ("Test connessione") time and before every tool call: https-only
(configurable), private/loopback/link-local/reserved + cloud-metadata
(`169.254.169.254`) blocks, DNS-rebinding guard, optional domain allowlist.
Secrets never reach the LLM; the output byte-cap limits mass exfiltration.

The package's admin routes default to the `['api']` middleware
(**unauthenticated — dev only**). The host MUST override
`connector-api.routes.middleware` with its authenticated admin stack
(`auth:sanctum` + tenant scoping + an RBAC gate) — R32.

## Tri-surface (R44)

One core (`ConnectorAdminService`) behind three surfaces: Artisan
(`api-connector:list` / `:test`), the admin HTTP routes, and the host MCP
`ApiConnectorsTool`.

## Phase 2 (reserved)

Ingest is designed-in but not shipped: the `mode` column (`RouteMode`) reserves
`ingest` / `both` so a Rotta can later feed the vector store on a schedule in
addition to (or instead of) being a live tool. No ingest path exists in 1.0.0.
