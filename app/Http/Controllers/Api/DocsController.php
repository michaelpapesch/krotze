<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class DocsController extends Controller
{
    /**
     * The OpenAPI document. Version and server URL are filled in here rather
     * than hard-coded in the spec, so a self-hosted installation advertises
     * itself and the version can never go stale.
     */
    public function spec()
    {
        return response()->json(self::document(), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    public static function document(): array
    {
        $spec = require resource_path('api/openapi.php');

        $spec['info']['version'] = (string) config('app.version');
        $spec['servers'] = [[
            'url' => rtrim((string) config('app.url'), '/'),
            'description' => config('app.name'),
        ]];

        return $spec;
    }

    /** The browsable reference. */
    public function page()
    {
        return view('api-docs');
    }
}
