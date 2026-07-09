<?php

declare(strict_types=1);

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the auto-generated OpenAPI document for the public `/api/v1` HTTP API:
 * relocates the JSON spec route to `/api/v1/openapi.json` (the docs UI stays at
 * Scramble's default `/docs/api`), and declares bearer-token auth as the
 * default security scheme for every documented operation.
 */
class ScrambleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Scramble::configure()
            ->expose(ui: 'docs/api', document: 'api/v1/openapi.json')
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(SecurityScheme::http('bearer'));
            });
    }
}
