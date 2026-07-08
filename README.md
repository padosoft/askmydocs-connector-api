<h1 align="center">askmydocs-connector-api</h1>

<p align="center">
  <strong>API connector for AskMyDocs — turn any configured HTTP endpoint into a <em>live LLM tool</em> callable during chat. Not ingest: fresh data, fetched in real time, alongside RAG.</strong><br/>
  Drop-in Laravel package. <code>composer require</code> it into any AskMyDocs install, wire two singletons, and each configured <strong>Rotta</strong> (endpoint) becomes a function the model can call mid-conversation.
</p>

<p align="center">
  <!--
    CI badge — activates once .github/workflows/tests.yml lands AND a public tag exists on
    github.com/padosoft/askmydocs-connector-api. Uncomment when both are in place.
  <a href="https://github.com/padosoft/askmydocs-connector-api/actions/workflows/tests.yml"><img alt="CI status" src="https://img.shields.io/github/actions/workflow/status/padosoft/askmydocs-connector-api/tests.yml?branch=main&label=tests"></a>
  -->
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-api"><img alt="Packagist version" src="https://img.shields.io/packagist/v/padosoft/askmydocs-connector-api.svg?label=packagist"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-api"><img alt="Total downloads" src="https://img.shields.io/packagist/dt/padosoft/askmydocs-connector-api.svg?label=downloads"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-blue.svg"></a>
  <img alt="PHP version" src="https://img.shields.io/badge/php-8.3%20%7C%208.4%20%7C%208.5-777BB4">
  <img alt="Laravel version" src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20">
</p>

---

## Table of contents

1. [Why this package](#why-this-package)
2. [Features](#features)
3. [Architecture at a glance](#architecture-at-a-glance)
4. [Requirements & installation](#requirements--installation)
5. [Quick start](#quick-start)
6. [Auth strategy setup](#auth-strategy-setup)
7. [How endpoints become tools](#how-endpoints-become-tools)
8. [Tool-call execution](#tool-call-execution)
9. [Host integration](#host-integration)
10. [Configuration reference](#configuration-reference)
11. [Security notes](#security-notes)
12. [Testing](#testing)
13. [Config recipes](#config-recipes)
14. [Troubleshooting](#troubleshooting)
15. [Roadmap](#roadmap)
16. [License](#license)

---

## Why this package

[AskMyDocs](https://github.com/lopadova/AskMyDocs) is an enterprise-grade RAG + canonical knowledge-compilation system. Every other connector in the `padosoft/askmydocs-connector-*` family does the same thing: **download → chunk → embed**. IMAP mailboxes, Google Drive, Confluence — they all pour documents into the vector store so the chat can retrieve them later.

This connector inverts the paradigm. It does **not** ingest anything. Instead, each configured HTTP endpoint (a **Rotta**) is compiled into a **Tool** — a `{name, description, input_schema}` triple the LLM sees during a chat turn. When the user asks a question, the model has **two parallel sources**:

- **indexed history** (the RAG corpus — what was true when it was last ingested), and
- **fresh data** fetched in real time from the customer's own APIs (what is true *right now*).

```
Connettore API ("Gestionale Cliente X")
 ├─ Rotta "Ordini"     → Tool get_orders     (GET https://api.clientex.com/v1/orders)
 ├─ Rotta "Clienti"    → Tool get_customers  (GET https://api.clientex.com/v1/customers/{id})
 └─ Rotta "Magazzino"  → Tool get_stock      (GET https://api.clientex.com/v1/stock)
```

Ask *"how many orders did ACME place last week, and are any of them still unshipped?"* and the model can call `get_orders` live, read the JSON, and answer from data that never touched the embedding pipeline. No re-ingest, no staleness, no vector drift — the endpoint is the source of truth and it is queried on demand.

> **`composer require padosoft/askmydocs-connector-api`, migrate, bind two singletons. Done.**

The package is deliberately small and standalone: it owns 5 tenant-aware tables, a test → auto-configuration pipeline, a hardened runtime executor, an SSRF guard, six auth strategies, and a thin admin HTTP surface. It imports **no** host class — the host wires it in via a registry + executor pair and one optional contract.

## Features

- **A new connector paradigm** — endpoints become **live tools**, not ingested documents. RAG and fresh API data run side by side inside a single chat turn.
- **5 tenant-aware tables** — `api_connectors`, `api_auth_profiles`, `api_routes`, `api_route_parameters`, `api_tool_call_logs`. Every one carries `tenant_id` and is scoped through the base package's `BelongsToTenant` trait (R30/R31).
- **Test → auto-configuration** — a real "Test connessione" call drives the whole setup: `ApiRouteTester` performs the request, `SchemaInferrer` deduces the input schema (from the declared `llm` params) and the output schema (from the sample JSON body), and `ToolDefinitionGenerator` produces the `{name, description, input_schema}` the LLM will see. The operator can edit every field; nothing is guessed blindly.
- **Hardened runtime executor** — `ApiToolExecutor` gives every tool call: server-side auth injection, per-Rotta timeout, **retry on transient failures only** (5xx / connection errors, *never* 4xx) with linear backoff, optional per-Rotta rate-limit and short-TTL response cache, output byte-cap with a truncation envelope, a sanitised `api_tool_call_logs` row (secrets never logged), and **failure-loud** structured `{error, status}` results so the model can explain a failure instead of hallucinating around a silent one (R14).
- **SSRF guard** — `UrlGuard` is AskMyDocs's single outbound-URL chokepoint. It is enforced both at configuration time (Test) and at runtime (every tool call): https-only, private / loopback / link-local / reserved ranges blocked (which also covers the cloud-metadata endpoint `169.254.169.254`), DNS-rebinding defence via A/AAAA resolution, and an optional exact-or-subdomain domain allowlist.
- **Six auth strategies** — `none`, `api_key` (header or query), `bearer`, `basic`, `custom` (arbitrary secret headers), `oauth2_cc` (OAuth2 client-credentials with token caching). Credentials are stored `encrypted:array` and `$hidden`, and are **never** surfaced to the LLM.
- **Two parameter axes** — every parameter is described by **location** (`path` | `query` | `header` | `body` — *where* it goes) × **source** (`llm` | `fixed` | `secret` — *who* decides its value). Only `llm` params enter the tool schema the model sees; `fixed` are constants; `secret` are pulled from the auth profile and never exposed.
- **R44 tri-surface** — the same capability, over one shared core (`ConnectorAdminService` / `ApiRouteTester`), on three surfaces: Artisan (`api-connector:list`, `api-connector:test`), a RESTful admin HTTP API, and the host MCP tool `ApiConnectorsTool`.
- **R30 per-tenant scoping** — every loader is tenant-scoped and there is **no** implicit route-model binding: an id guessed from another tenant 404s (IDOR-safe by design).
- **Config-gated chat injection (R43)** — surfacing routes as chat tools is a master switch, `connector-api.chat_tools.enabled`. Off → the chat behaves exactly as before (no API tools) while the config/test/try surfaces still work; on → active routes are injected, capped per conversation.
- **Fase 2 reserved** — every route carries a `mode` column (`tool` | `ingest` | `both`). Fase 1 ships `tool`; `ingest`/`both` are wired in the schema for a future crawl-and-index mode without a migration.

## Architecture at a glance

```
                ┌──────────────────────────────┐
Composer        │ padosoft/askmydocs-          │
require ───────▶│ connector-api  (this package)│
                └──────────────┬───────────────┘
                               │ auto-discovered provider
                               │ ApiConnectorServiceProvider
                               ▼
                ┌──────────────────────────────┐
                │ padosoft/askmydocs-connector-│
                │ base ^1.4                    │
                │  • BelongsToTenant trait     │
                │  • TenantContext             │
                └──────────────┬───────────────┘
                               │
          ┌────────────────────┼─────────────────────────┐
          ▼                    ▼                          ▼
 ┌──────────────────┐  ┌──────────────────┐   ┌────────────────────────┐
 │ Admin surface    │  │ ConnectorAdmin-  │   │ ApiToolRegistry        │
 │  HTTP routes     │─▶│ Service (core)   │   │  activeToolsForTenant()│
 │  Artisan cmds    │  │  create/test/    │   │  routeForTool()        │
 │  MCP tool (host) │  │  activate/try    │   └───────────┬────────────┘
 └──────────────────┘  └────────┬─────────┘               │
                                │ test→schema→tool          │ resolves
                                ▼                           ▼
                       ┌──────────────────┐      ┌────────────────────────┐
                       │ ApiRouteTester   │      │ ApiToolExecutor        │
                       │ SchemaInferrer   │      │  plan → auth → SSRF →   │
                       │ ToolDefinition-  │      │  HTTP(retry) → shape →  │
                       │ Generator        │      │  cap → log → result    │
                       └────────┬─────────┘      └───────────┬────────────┘
                                │                            │
                                ▼                            ▼
                       ┌──────────────────┐      ┌────────────────────────┐
                       │ UrlGuard (SSRF)  │      │ Customer's HTTP API     │
                       │ HttpDispatcher   │─────▶│ (SSRF-guarded, https)   │
                       └──────────────────┘      └────────────────────────┘
```

The host wires the package into its chat loop by resolving two singletons and binding one optional contract:

- **`ApiToolRegistry`** — lists the active tools for a tenant/project (`activeToolsForTenant()`) and resolves a tool name back to its executable route (`routeForTool()`). The host's `McpToolCallingService::buildToolIndex()` merges these (as `kind=api`) into its tool index, gated by `connector-api.chat_tools.enabled` (R43).
- **`ApiToolExecutor`** — runs a resolved route with the model's arguments and returns the `tool_result`. The host dispatches an API tool call here.
- **`ToolDescriptionAssistant`** (contract) — the package ships a no-op default (`NullToolDescriptionAssistant`, field-derived drafts); the host rebinds it to an AI-backed implementation so tool names/descriptions can be drafted by the LLM during Test.

The package never imports a host class. It depends only on `padosoft/askmydocs-connector-base ^1.4`, which supplies the `BelongsToTenant` trait and the `TenantContext` singleton — the same tenancy primitives every AskMyDocs connector shares.

> **Note on the admin UI.** This package ships **no Blade views or frontend assets** by design. The admin experience is AskMyDocs's own React SPA driving the JSON API documented below — a package-owned UI would duplicate the host's design system, auth, and team-scope wiring. The absence of views is deliberate, not an omission.

## Requirements & installation

**Requirements**

- PHP `^8.3`
- Laravel `^12.0 | ^13.0` (`illuminate/*` contracts/database/http/support)
- `padosoft/askmydocs-connector-base ^1.4`
- An AskMyDocs host (or any Laravel app) that binds a `TenantContext` and, for chat injection, wires the registry + executor.

**Install**

```bash
composer require padosoft/askmydocs-connector-api
php artisan migrate
```

The package self-registers through Laravel auto-discovery (`Padosoft\AskMyDocsConnectorApi\ApiConnectorServiceProvider`) — no manual provider entry. `migrate` creates the 5 `api_*` tables (they auto-load via the provider's `loadMigrationsFrom`, so `migrate` picks them up even without publishing).

**Publish (optional)**

```bash
# Publish config/connector-api.php for env-var overrides + the MANDATORY routes.middleware override
php artisan vendor:publish --tag=api-connector-config

# Copy the migrations into your app (only if you want to customise them)
php artisan vendor:publish --tag=api-connector-migrations

# Copy the admin routes into routes/connector-api.php (only to customise the route list)
php artisan vendor:publish --tag=api-connector-routes

# Copy the connector icon into public/ (for the host admin UI's connector picker)
php artisan vendor:publish --tag=api-connector-assets
```

> The `api-connector-assets` tag publishes the connector's icon (`public/icons/api.svg` → `public/connectors/`) so a host UI can render this connector alongside the ingest connectors.

### Local development (path repository)

For local development the host resolves this package through a Composer `path` repository with a symlink, so edits are picked up without a Packagist round-trip. In the **host** `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "/Users/marco/packages/askmydocs-connector-api",
        "options": { "symlink": true }
    }
],
"require": {
    "padosoft/askmydocs-connector-api": "@dev"
}
```

```bash
composer update padosoft/askmydocs-connector-api
```

## Quick start

The lifecycle is always the same: **create a Connettore → add a Rotta → Test it (which generates the tool) → Activate → it's live**. Below, the HTTP payloads match the real `FormRequest` shapes; substitute the admin prefix (`api/admin/api-connectors`) and the host's auth as configured (see [Host integration](#host-integration)).

### 1. Create a Connettore (the container)

```http
POST /api/admin/api-connectors
Content-Type: application/json

{
  "name": "Gestionale Cliente X",
  "description": "Live orders + stock for ACME's ERP",
  "project_key": "acme",
  "base_url": "https://api.clientex.com",
  "headers": { "Accept": "application/json" },
  "is_active": true
}
```

`project_key` is optional; when set, the connector's routes only apply to that KB project (an empty project scope is tenant-global). `201 Created` returns the connector resource (`id`, fields, empty `routes`).

### 2. Add an auth profile (a bearer token here)

```http
POST /api/admin/api-connectors/{connector}/auth-profiles
Content-Type: application/json

{
  "type": "bearer",
  "credentials": { "token": "sk-live-xxxxxxxx" }
}
```

`credentials` is stored `encrypted:array` and is `$hidden` — it is never echoed back in any response. See [Auth strategy setup](#auth-strategy-setup) for every strategy's shape.

### 3. Add a Rotta (the endpoint that becomes a tool)

```http
POST /api/admin/api-connectors/{connector}/routes
Content-Type: application/json

{
  "name": "Ordini cliente",
  "http_method": "GET",
  "url": "https://api.clientex.com/v1/customers/{customer_id}/orders",
  "auth_profile_id": 12,
  "mode": "tool",
  "timeout_ms": 8000,
  "cache_ttl_s": 30,
  "parameters": [
    {
      "name": "customer_id",
      "location": "path",
      "source": "llm",
      "type": "string",
      "required": true,
      "description": "The customer's ERP id, e.g. ACME-001"
    },
    {
      "name": "status",
      "location": "query",
      "source": "llm",
      "type": "string",
      "required": false,
      "description": "Filter orders by status (open, shipped, cancelled)"
    },
    {
      "name": "include_archived",
      "location": "query",
      "source": "fixed",
      "type": "boolean",
      "value": "false"
    }
  ]
}
```

The two parameter axes at work: `customer_id` and `status` are `source: llm` (they enter the tool schema the model fills in), while `include_archived` is `source: fixed` (a constant the operator pins, hidden from the model). `location` decides placement — `path` substitutes `{customer_id}` in the URL, `query` appends to the querystring. The route is created in `status: draft`.

### 4. Run the pre-save Test (this is what generates the tool)

```http
POST /api/admin/api-connectors/routes/{route}/test
Content-Type: application/json

{
  "example_args": { "customer_id": "ACME-001", "status": "open" }
}
```

The Test performs a **real** call with the operator's example `llm` values + the configured `fixed`/`secret` params + auth. On a JSON success it infers the input + output schema, generates the tool definition, and promotes the route to `status: tested`. The response carries `test` (ok / status / is_json / body), plus the generated `tool_definition`, `input_schema`, `output_schema`.

Or from the CLI — same core (`ApiRouteTester`), tenant-scoped:

```bash
php artisan api-connector:test 42 --tenant=acme --args='{"customer_id":"ACME-001","status":"open"}'
```

### 5. Activate → the tool is live

```http
POST /api/admin/api-connectors/routes/{route}/activate
```

Activation requires a `tested` route (a never-tested route 422s). Once `active` with `mode` = `tool` (or `both`), the route is exposed to the chat loop by `ApiToolRegistry`.

### 6. List everything

```bash
php artisan api-connector:list --tenant=acme
```

```
Gestionale Cliente X (#7) — 1 route(s)
 slug          method  status  mode  last_test
 get_orders    GET     active  tool  ok
```

## Auth strategy setup

An auth profile belongs to a connector and applies to a route either as the connector's default (`default_auth_profile_id`) or as a per-route override (`auth_profile_id`, resolved by `ApiRoute::effectiveAuthProfile()`). Every profile stores `credentials` **encrypted at rest** (`encrypted:array` cast) and `$hidden` — the secret never reaches the LLM, a JSON response, or a log. On update, credentials are **merged**, so a blank edit form never wipes a stored secret. `type` is one of the six `AuthType` cases below.

### `none`

Public endpoint, no auth material.

```json
{ "type": "none" }
```

### `api_key`

The key goes in a header (default `X-API-Key`) or in the querystring.

```json
{
  "type": "api_key",
  "credentials": { "key": "abcd1234" },
  "config": { "in": "header", "name": "X-API-Key" }
}
```

`config.in` is `header` (default) or `query`; `config.name` names the header/query key. `credentials` may use `key` or `api_key`.

### `bearer`

`Authorization: Bearer <token>`.

```json
{
  "type": "bearer",
  "credentials": { "token": "sk-live-xxxx" }
}
```

`credentials` may use `token` or `access_token`.

### `basic`

`Authorization: Basic base64(username:password)`.

```json
{
  "type": "basic",
  "credentials": { "username": "svc-acme", "password": "s3cr3t" }
}
```

### `custom`

One or more arbitrary secret headers.

```json
{
  "type": "custom",
  "credentials": {
    "headers": { "X-Tenant": "42", "X-Signature": "deadbeef" }
  }
}
```

### `oauth2_cc`

OAuth2 client-credentials grant. The strategy fetches an access token from the token endpoint (itself SSRF-guarded), caches it for its lifetime (`expires_in` − 30 s, floor 30 s), and injects `Authorization: Bearer <token>`. A failed token exchange **throws** (R14) — the executor returns an error rather than silently calling the endpoint unauthenticated.

```json
{
  "type": "oauth2_cc",
  "credentials": { "client_id": "acme-client", "client_secret": "xxxx" },
  "config": {
    "token_url": "https://auth.clientex.com/oauth/token",
    "scope": "orders:read",
    "auth_style": "body",
    "header_name": "Authorization"
  }
}
```

`config.token_url` is required. `config.auth_style` is `body` (default — `client_id`/`client_secret` in the form body) or `basic` (HTTP Basic on the token request). `config.scope` and `config.header_name` are optional.

## How endpoints become tools

The generation pipeline runs inside `ConnectorAdminService::testRoute()` (behind the Test endpoint and the `api-connector:test` command) and produces exactly the `{name, description, input_schema}` triple the LLM consumes.

```
Test call (ApiRouteTester)
  ├─ RequestPlanner.plan()      resolve params → path/query/header/body + auth
  ├─ UrlGuard.assertAllowed()   SSRF check on the final URL
  ├─ HttpDispatcher.send()      real HTTP request, per-route timeout
  └─ TestResult { ok, status, isJson, body, error }   ← R14: 3 distinct outcomes
        │  (persists last_test_at / last_test_status / last_test_payload on the route)
        ▼  on JSON success:
  SchemaInferrer.inferInput(parameters)   → { type:object, properties, required }   (llm params ONLY)
  SchemaInferrer.inferOutput(body)        → recursive structure of the sample JSON
  ToolDefinitionGenerator.generate(...)   → { name, description, input_schema }
        │  name  = LLM-assisted slug (host ToolDescriptionAssistant) or field-derived; snake_case, ≤64 chars
        │  descr = LLM-assisted or a drafted "Calls METHOD /path and returns the live response…"
        ▼
  route.status → tested   (input_schema, output_schema, tool_definition persisted)
```

Key invariants:

- **Only `source = llm` params enter the input schema.** `fixed` and `secret` params stay internal — the model never sees them, so it can never override a pinned constant or a credential.
- **The output schema is inferred from a real sample.** Nested objects map to `object` with `properties`; lists map to `array` with `items` sampled from the first element (depth-guarded at 8 to bound pathological nesting).
- **LLM assist is optional and gated.** With `connector-api.llm_assist.enabled` on and a host-bound `ToolDescriptionAssistant`, the name/description are drafted by the AI from the method, URL, input schema and a trimmed response sample. Off (or with the no-op default), a field-derived draft is used. Either way the operator can edit the result, and `POST .../routes/{route}/regenerate-description` re-runs the generator against the stored schema + last test payload.
- **The slug is the tool name.** It is normalised to `^[a-z_][a-z0-9_]*$`, ≤ 64 chars, unique per `(tenant_id, project_key, slug)`. An operator-set slug is never clobbered by the generator.

## Tool-call execution

At chat time, when the model calls a tool, the host resolves it with `ApiToolRegistry::routeForTool()` and hands it to `ApiToolExecutor::execute($route, $arguments, $context)`. The executor is 100% server-side — the LLM only ever supplies the `llm` argument values and only ever receives the transformed, capped response. URLs, headers, and secrets never leave the server.

```
ApiToolExecutor::execute(route, arguments, context)
  1. rate-limit        route.rate_limit > 0 and over budget?  → { error, status: 429 }   (per-minute window)
  2. cache read        route.cache_ttl_s > 0 and hit?          → cached result
  3. run:
       RequestPlanner.plan()      llm args + fixed constants + secret (from auth profile via secret_ref)
                                   → path/query/header/body; missing required llm param → throw;
                                     unresolved {token} left in the URL → throw
       AuthApplierFactory         → AuthMaterial (extra headers / query) merged in
       UrlGuard.assertAllowed()   SSRF check on the final URL
       HttpDispatcher.send()      timeout + retry-on-transient-only (5xx / connection), linear backoff
       decode + branch (R14):
         non-2xx        → { error: "Endpoint returned HTTP <n>.", status: <n> }
         non-JSON       → { error: "…non-JSON response (unsupported in Fase 1).", status: <n> }
         success+JSON   → OutputTransformer.selectFields()  (include/exclude dot-paths, * wildcards)
                        → OutputTransformer.capBytes()       (truncation envelope if over the byte cap)
                        → result
       log a sanitised api_tool_call_logs row (llm+fixed params only; secrets NEVER)
  4. cache write        on success when route.cache_ttl_s > 0
  (any throwable)       → { error: <message>, status: null } + logged
```

Operational guarantees:

- **Retry is transient-only.** `HttpDispatcher` retries on 5xx and connection errors up to `defaults.retry_times`, backing off `retry_backoff_ms × attempt`. A 4xx is terminal — the package never hammers an endpoint that just said "bad request".
- **Output is bounded.** `OutputTransformer.capBytes()` replaces an over-limit payload with `{_truncated, _note, _bytes, preview}` so the context window can't blow up and mass exfiltration is capped. Field selection (`output_transform.include` / `.exclude`, dot-paths with `*` wildcards) shrinks the payload before the cap.
- **Logging never breaks the tool path.** The `api_tool_call_logs` write is wrapped in try/catch (mirrors the host's `ChatLogManager`); a logging failure never propagates to the model.
- **`api_tool_call_logs` is append-only** (`UPDATED_AT = null`) and stores only sanitised request params + a truncated response excerpt, per conversation, for debug/audit.

An operator can dry-run the whole pipeline without a conversation via `POST .../routes/{route}/try` (or `ConnectorAdminService::tryRoute()`), supplying `{"arguments": {...}}` exactly as the LLM would.

## Host integration

Two wiring points, both in a host service provider.

**1. Bind the AI-backed description assistant** (optional — enables LLM-drafted tool names/descriptions):

```php
use Padosoft\AskMyDocsConnectorApi\Contracts\ToolDescriptionAssistant;

$this->app->bind(ToolDescriptionAssistant::class, HostToolDescriptionAssistant::class);
```

Without this, the package uses `NullToolDescriptionAssistant` (field-derived drafts) — everything still works.

**2. Merge API tools into the chat loop.** The host's `McpToolCallingService::buildToolIndex()` merges the package's active tools (as `kind=api`) — gated by `connector-api.chat_tools.enabled` (R43) — and dispatches a call to the executor:

```php
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolRegistry;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolExecutor;

// While building the tool index for a conversation:
if (config('connector-api.chat_tools.enabled')) {
    foreach (app(ApiToolRegistry::class)->activeToolsForTenant($tenantId, $projectKey) as $tool) {
        // merge $tool['name'] + $tool['definition'] into the index (kind = api)
    }
}

// When the model calls one:
$route  = app(ApiToolRegistry::class)->routeForTool($tenantId, $toolName, $projectKey);
$result = app(ApiToolExecutor::class)->execute($route, $arguments, ['conversation_id' => $conversationId]);
```

`activeToolsForTenant()` returns only `active` routes with `mode` ∈ {`tool`, `both`}, whose connector `is_active`, matching the tenant + project scope, deduped by tool name and capped at `tools.max_per_conversation`.

**3. Override the route middleware (MANDATORY — R32).** The package's default `routes.middleware` is `['api']`, which is **unauthenticated** and for standalone dev only. In your published `config/connector-api.php` the host **must** replace it with its authenticated admin stack:

```php
// config/connector-api.php (host)
'routes' => [
    'enabled'    => true,
    'prefix'     => 'api/admin/api-connectors',
    'middleware' => ['api', 'auth:sanctum', 'tenant.authorize', 'can:manageConnectors'],
],
```

This is the R32 authorization-matrix row for the connector admin surface. Leaving the default `['api']` in place ships the entire connector admin API — including credential writes — **publicly**. Do not skip this.

The admin routes (mounted under `routes.prefix`, named `api-connectors.*`):

| Method | Path | Name | Purpose |
|---|---|---|---|
| `GET` | `/` | `index` | List connectors (+ compact route summaries) |
| `POST` | `/` | `store` | Create connector |
| `GET` | `{connector}` | `show` | Connector detail (routes + auth profiles) |
| `PATCH` | `{connector}` | `update` | Update connector (R28 project_key guard → 422) |
| `DELETE` | `{connector}` | `destroy` | Delete connector (cascades profiles + routes) |
| `POST` | `{connector}/auth-profiles` | `auth-profiles.store` | Create auth profile |
| `PATCH` | `auth-profiles/{profile}` | `auth-profiles.update` | Update auth profile (credentials merged) |
| `DELETE` | `auth-profiles/{profile}` | `auth-profiles.destroy` | Delete auth profile |
| `POST` | `{connector}/routes` | `routes.store` | Create Rotta |
| `GET` | `routes/{route}` | `routes.show` | Rotta detail (+ generated artifacts) |
| `PATCH` | `routes/{route}` | `routes.update` | Update Rotta |
| `DELETE` | `routes/{route}` | `routes.destroy` | Delete Rotta |
| `POST` | `routes/{route}/test` | `routes.test` | Test connessione → generate tool |
| `POST` | `routes/{route}/regenerate-description` | `routes.regenerate-description` | Re-draft tool name/description |
| `POST` | `routes/{route}/activate` | `routes.activate` | Activate (requires `tested`) |
| `POST` | `routes/{route}/disable` | `routes.disable` | Disable |
| `POST` | `routes/{route}/try` | `routes.try` | Dry-run the executor |

Route params are plain numeric ids; every controller loads the model **tenant-scoped** (R30) — there is no implicit route-model binding, so a guessed id from another tenant 404s.

## Configuration reference

Every knob in `config/connector-api.php`, its env var, default, and meaning. Publish the config (`--tag=api-connector-config`) to override the file directly (required for `routes.middleware`, which has no env var).

| Config key | Env var | Default | Meaning |
|---|---|---|---|
| `ssrf.enabled` | `API_CONNECTOR_SSRF_ENABLED` | `true` | Master switch for the SSRF guard. Off only for dev/tests — leaving it off in production removes the single outbound-URL chokepoint. |
| `ssrf.https_only` | `API_CONNECTOR_HTTPS_ONLY` | `true` | Reject any non-`https` scheme at config + runtime. |
| `ssrf.resolve_dns` | `API_CONNECTOR_SSRF_RESOLVE_DNS` | `true` | Resolve the host and check **every** A/AAAA address is public (DNS-rebinding defence). |
| `ssrf.allowlist` | `API_CONNECTOR_DOMAIN_ALLOWLIST` | `''` (empty = any public host) | CSV of allowed host suffixes, e.g. `api.clientex.com,api.other.com`. When non-empty, only these hosts and their subdomains are permitted (private ranges are still blocked). |
| `output.max_bytes` | `API_CONNECTOR_OUTPUT_MAX_BYTES` | `16384` | Max size (bytes) of the JSON `tool_result` returned to the LLM. Larger payloads are replaced by a truncation envelope. |
| `chat_tools.enabled` | `API_CONNECTOR_CHAT_TOOLS_ENABLED` | `true` | Master switch (R43) for injecting API routes as live chat tools. Off → chat behaves as before; config/test/try still work. |
| `tools.max_per_conversation` | `API_CONNECTOR_MAX_TOOLS_PER_CONVERSATION` | `16` | Hard cap on API tools injected into one conversation (keeps tool-routing sharp). `0` = no cap. |
| `defaults.timeout_ms` | `API_CONNECTOR_DEFAULT_TIMEOUT_MS` | `10000` | Per-call timeout used when a Rotta does not set its own `timeout_ms`. |
| `defaults.retry_times` | `API_CONNECTOR_RETRY_TIMES` | `2` | Retries applied at runtime on transient failures only (5xx / connection), never on 4xx. |
| `defaults.retry_backoff_ms` | `API_CONNECTOR_RETRY_BACKOFF_MS` | `250` | Base backoff between retries; the dispatcher waits `retry_backoff_ms × attempt`. |
| `defaults.cache_ttl_s` | `API_CONNECTOR_CACHE_TTL_S` | `0` | Documented default cache TTL. Runtime caching is driven by the per-Rotta `cache_ttl_s`; `0` = no cache. |
| `llm_assist.enabled` | `API_CONNECTOR_LLM_ASSIST` | `true` | Use the host's bound `ToolDescriptionAssistant` to draft the tool name/description from the test call; otherwise a field-derived draft is used. |
| `routes.enabled` | `API_CONNECTOR_ROUTES_ENABLED` | `true` | Mount the admin HTTP routes. Off → the package ships without the admin surface. |
| `routes.prefix` | `API_CONNECTOR_ROUTES_PREFIX` | `api/admin/api-connectors` | URL prefix for the admin routes. |
| `routes.middleware` | *(no env var — override in published config)* | `['api']` | **MUST** be replaced by the host with its authenticated admin stack (R32). The default is unauthenticated — dev only. |

## Security notes

Security is not a footnote for a connector that lets operators point the app at arbitrary URLs and store third-party credentials. The controls below are enforced by code, not convention.

- **SSRF guard on every URL, at config time AND runtime.** `UrlGuard` is AskMyDocs's single outbound-URL chokepoint. It runs both when an operator configures/tests a URL and before **every** tool call and OAuth2 token fetch. Policy: **https-only** (configurable down to http for dev); the host — or, with `resolve_dns` on, **every** resolved A/AAAA address — must be a **public** address, so private / loopback / link-local / reserved ranges are blocked, which also covers the cloud-metadata endpoint `169.254.169.254`; and when a non-empty **domain allowlist** is set, the host must match exactly or as a subdomain. Resolving all addresses is the DNS-rebinding defence: a hostname that resolves public at config time but private at call time is still rejected. A violation throws `UrlNotAllowedException` — the request never leaves the server.
- **Credentials encrypted at rest, hidden, and never surfaced to the LLM.** `ApiAuthProfile::$credentials` is cast `encrypted:array` (Laravel app-key encryption) and listed in `$hidden`, so it is never serialized to a JSON response or a log. `secret`-source params are resolved server-side from the profile via `secret_ref` and injected into the outbound request only; they are excluded from `loggableParams` and never enter the tool schema the model sees. On update, credentials are merged, so a blank field never wipes a stored secret.
- **Output byte-cap limits exfiltration.** `output.max_bytes` (default 16 KiB) bounds the JSON handed back to the model. A hostile or misconfigured endpoint cannot pump an unbounded payload through a tool call — it is truncated to a preview envelope.
- **R30 per-tenant scoping — IDOR-safe by design.** Every loader in `ConnectorAdminService` (and the registry, and the CLI) is scoped to the active tenant with `forTenant()`; there is **no** implicit route-model binding. An id guessed from another tenant returns **404**, not 403 — the app never leaks whether an id exists in another tenant.
- **The MANDATORY route-middleware override.** The package default `routes.middleware` is `['api']`, which is **unauthenticated**. The host **must** override it with `['api', 'auth:sanctum', 'tenant.authorize', 'can:manageConnectors']` (R32) in its published `config/connector-api.php`. Shipping the default in production exposes the entire connector admin surface — including credential writes — publicly. This is the single most important step in [Host integration](#host-integration).
- **Failure is loud, never silent (R14).** A failed Test is a valid displayed outcome (`ok:false`), not a fabricated schema. A runtime failure returns `{error, status}` so the model explains it. An OAuth2 token-exchange failure throws rather than calling the endpoint unauthenticated.

## Testing

```bash
composer install
composer test        # vendor/bin/phpunit  (Unit + Feature)
composer analyse     # vendor/bin/phpstan analyse --memory-limit=512M  (level 8)
composer format      # vendor/bin/pint
```

The suite runs on **SQLite in-memory** via Orchestra Testbench — no external services, no real HTTP. Outbound calls are exercised with `Http::fake()`; the SSRF guard, the request planner, the executor pipeline, schema inference, tool generation, and the admin controllers all have deterministic coverage. Static analysis is PHPStan at level 8; formatting is Laravel Pint.

## Config recipes

### A bearer-auth read endpoint

A public-internet GET protected by a bearer token, with a 30 s cache and a path parameter chosen by the model.

```jsonc
// 1) auth profile
{ "type": "bearer", "credentials": { "token": "sk-live-xxxx" } }

// 2) route
{
  "name": "Customer profile",
  "http_method": "GET",
  "url": "https://api.clientex.com/v1/customers/{customer_id}",
  "auth_profile_id": 12,
  "cache_ttl_s": 30,
  "parameters": [
    { "name": "customer_id", "location": "path", "source": "llm",
      "type": "string", "required": true, "description": "ERP customer id" }
  ]
}
```

### An API-key-in-header endpoint with a pinned query filter

The key rides in a custom header; a `fixed` query param pins a constant the model can't change; a rate-limit caps calls per minute.

```jsonc
// 1) auth profile
{
  "type": "api_key",
  "credentials": { "key": "abcd1234" },
  "config": { "in": "header", "name": "X-API-Key" }
}

// 2) route
{
  "name": "Open tickets",
  "http_method": "GET",
  "url": "https://desk.clientex.com/api/tickets",
  "auth_profile_id": 15,
  "rate_limit": 20,
  "output_transform": { "include": ["tickets.*.id", "tickets.*.subject", "tickets.*.status"] },
  "parameters": [
    { "name": "status", "location": "query", "source": "fixed", "type": "string", "value": "open" },
    { "name": "assignee", "location": "query", "source": "llm", "type": "string",
      "required": false, "description": "Filter by assignee email" }
  ]
}
```

`output_transform.include` shrinks each ticket to three fields before the byte-cap, so the model reads a lean list.

### An OAuth2 client-credentials endpoint

Unattended service-to-service access; the token is fetched (SSRF-guarded) and cached automatically.

```jsonc
// 1) auth profile
{
  "type": "oauth2_cc",
  "credentials": { "client_id": "acme-client", "client_secret": "xxxx" },
  "config": {
    "token_url": "https://auth.clientex.com/oauth/token",
    "scope": "stock:read",
    "auth_style": "basic"
  }
}

// 2) route
{
  "name": "Warehouse stock",
  "http_method": "GET",
  "url": "https://api.clientex.com/v1/stock",
  "auth_profile_id": 18,
  "timeout_ms": 8000,
  "parameters": [
    { "name": "sku", "location": "query", "source": "llm", "type": "string",
      "required": true, "description": "Product SKU to look up" }
  ]
}
```

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| **Test/tool call rejected before any HTTP** (`UrlNotAllowedException`) | URL is `http` while `https_only` is on, resolves to a private/reserved IP, or is outside a configured allowlist | Use `https`; if the target is genuinely internal, that is blocked by design (SSRF) — front it with a public gateway. If it's a legitimate public host missing from `API_CONNECTOR_DOMAIN_ALLOWLIST`, add its suffix. |
| **Tool returns `{ "error": "Endpoint returned HTTP 401." }`** | Auth profile missing/wrong, or not linked to the route | Check the profile `type` + `credentials`; set the route's `auth_profile_id` (override) or the connector's `default_auth_profile_id`. Remember credentials are write-only — re-enter them if unsure. |
| **Tool returns HTTP 403** | Credentials valid but lack scope/permission upstream | Grant the API key/token the right scope on the provider side; for `oauth2_cc`, widen `config.scope`. |
| **Result is `{ "_truncated": true, ... }`** | Response exceeded `output.max_bytes` (16 KiB default) | Add an `output_transform.include` field selection to the route, ask a narrower question, or raise `API_CONNECTOR_OUTPUT_MAX_BYTES`. |
| **Tool never appears in chat** | `connector-api.chat_tools.enabled` is off (R43) | Set `API_CONNECTOR_CHAT_TOOLS_ENABLED=true`. |
| **Tool never appears in chat** | Route not `active`, `mode` not `tool`/`both`, or the connector `is_active=false` | Test → Activate the route; confirm `mode` and the connector's active flag. |
| **Tool never appears in chat** | The conversation already holds `tools.max_per_conversation` API tools | Raise `API_CONNECTOR_MAX_TOOLS_PER_CONVERSATION` (or `0` for no cap), or reduce the number of active routes for that tenant/project. |
| **Tool never appears in chat** | Host has not resolved `ApiToolRegistry`/`ApiToolExecutor` into its tool loop | Wire the two singletons in a host service provider — see [Host integration](#host-integration). |
| **Route in the wrong project** | Route inherits its `project_key` from the connector; it's tenant-global only when the connector's project is empty | Set the connector's `project_key`; you cannot change it while the connector owns routes (R28 → 422) — delete the routes first. |
| **Tool description is a bland "Calls GET /path…"** | `llm_assist.enabled` off, or no host `ToolDescriptionAssistant` bound | Bind an AI-backed assistant + set `API_CONNECTOR_LLM_ASSIST=true`, or edit the description and call `regenerate-description`. |
| **Admin API reachable without login** | `routes.middleware` left at the default `['api']` | Override it with the authenticated admin stack (R32) — see [Security notes](#security-notes). |
| **PHPStan OOMs locally** | Default memory limit too low | `composer analyse` (already passes `--memory-limit=512M`). |

## Roadmap

- [ ] **Fase 2 — ingest mode.** Reserved in the schema via the `mode` column (`RouteMode`: `tool` | `ingest` | `both`). A future release will let a Rotta be crawled and indexed into the vector store — or serve both as a live tool and as ingested content — without a migration.
- [ ] **Provider tool-calling parity.** The host wires API tools through its MCP tool loop; broadening first-class tool-calling parity across every provider (Anthropic / Gemini and the OpenAI-family) is tracked host-side so the same Rotta behaves identically on all chat providers.
- [ ] **Per-Rotta secret rotation hooks** and richer output transforms (JSONPath beyond dot-path `*` wildcards).

## License

Apache-2.0 — see [LICENSE](LICENSE).

Built and maintained by [Padosoft](https://padosoft.com/). Part of the AskMyDocs connector ecosystem.
