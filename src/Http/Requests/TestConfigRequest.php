<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\Concerns\ValidatesRouteConfig;

/**
 * Validates the "Testa" body — a (possibly incomplete) config JSON to dry-run
 * against its endpoint, plus optional LLM example args. Only the callable target
 * (method + url) is required; the route need not exist yet (create mode).
 */
final class TestConfigRequest extends FormRequest
{
    use ValidatesRouteConfig;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return array_merge($this->routeConfigRules(requireIdentity: false), [
            'example_args' => ['nullable', 'array'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function config(): array
    {
        $config = $this->validated('config');

        return is_array($config) ? $config : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function exampleArgs(): array
    {
        $args = $this->validated('example_args');

        return is_array($args) ? $args : [];
    }
}
