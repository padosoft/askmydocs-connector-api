<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Exceptions;

use Padosoft\AskMyDocsConnectorApi\Support\UrlGuard;

/**
 * Thrown by {@see UrlGuard} when a
 * configured URL fails the SSRF policy (bad scheme, private/loopback/link-local
 * target, cloud-metadata endpoint, or not in the domain allowlist).
 */
final class UrlNotAllowedException extends ApiConnectorException {}
