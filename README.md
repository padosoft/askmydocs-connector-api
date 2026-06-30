# askmydocs-connector-api

**API connector for [AskMyDocs](https://github.com/lopadova/AskMyDocs)** — a new
kind of connector that turns any configured HTTP endpoint into a **live LLM tool**
callable *during chat*, instead of ingesting documents into the vector store.

Where the ingest connectors (`padosoft/askmydocs-connector-*`) do
*download → chunk → embed*, the API connector changes the paradigm: each
configured endpoint (**Rotta**) becomes a **Tool**. During a chat turn the LLM
has two parallel sources — indexed history (RAG) **and** fresh data fetched in
real time from the customer's APIs.

```
Connettore API ("Gestionale Cliente X")
 ├─ Rotta "Ordini"     → Tool get_orders     (GET https://api.clientex.com/v1/orders)
 ├─ Rotta "Clienti"    → Tool get_customers
 └─ Rotta "Magazzino"  → Tool get_stock
```

## What's in the box (Fase 1)

- **Data model** — `api_connectors`, `api_auth_profiles`, `api_routes`,
  `api_route_parameters`, `api_tool_call_logs` (all tenant-aware).
- **Test → auto-configuration** — `ApiRouteTester` performs a real call,
  `SchemaInferrer` deduces input/output schema, `ToolDefinitionGenerator`
  produces the `{name, description, input_schema}` exposed to the LLM.
- **Runtime executor** — `ApiToolExecutor` resolves the binding, applies auth +
  fixed params, runs the HTTP call (timeout / retry / rate-limit / cache),
  transforms + truncates the output, logs the call and returns sanitized JSON.
- **Security** — `UrlGuard` SSRF protection (private/loopback/link-local +
  cloud-metadata block, optional domain allowlist, https-only), encrypted
  credentials at rest (`encrypted:array`, `$hidden`), secrets never exposed to
  the LLM.
- **Auth** — none / API key / Bearer / Basic / custom headers /
  OAuth2 client-credentials.

Parameter axes — **location** (`path` | `query` | `header` | `body`) ×
**source** (`llm` exposed to the model | `fixed` constant | `secret` credential).

## Installation

```bash
composer require padosoft/askmydocs-connector-api
php artisan migrate
```

The package self-registers via Laravel auto-discovery
(`Padosoft\AskMyDocsConnectorApi\ApiConnectorServiceProvider`). The host binds
the `ApiToolRegistry` + `ApiToolExecutor` into its chat tool loop and the
`ToolDescriptionAssistant` contract to its AI manager (see AskMyDocs).

### Local development (path repository)

In the host `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "/Users/marco/packages/askmydocs-connector-api",
      "options": { "symlink": true } }
],
"require": { "padosoft/askmydocs-connector-api": "@dev" }
```

```bash
composer update padosoft/askmydocs-connector-api
```

## License

Apache-2.0.
