<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DocsController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The spec is hand-written, so the only thing keeping it honest is this test.
 * It compares resources/api/openapi.php against the real route table in both
 * directions: an endpoint added without documentation fails here, and so does a
 * documented path that no longer exists.
 */
class OpenApiSpecTest extends TestCase
{
    /**
     * Route prefixes the spec covers: the API itself, the upload capability
     * URLs, and the two deep links a client may have to open or generate.
     */
    private const DOCUMENTED_PREFIXES = ['api/', 'u/', 'join/', 'register/', 'embed/'];

    /** Routes that are part of the app but deliberately outside the spec. */
    private const NOT_DOCUMENTED = [
        'api/docs',
        'api/openapi.json',
    ];

    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spec = DocsController::document();
    }

    public function test_every_api_route_is_documented(): void
    {
        $missing = [];

        foreach ($this->appOperations() as $operation) {
            [$method, $path] = $operation;
            if (! isset($this->spec['paths'][$path][$method])) {
                $missing[] = strtoupper($method).' '.$path;
            }
        }

        $this->assertSame([], $missing, "These endpoints exist but are not in the OpenAPI spec:\n".implode("\n", $missing));
    }

    public function test_every_documented_operation_exists(): void
    {
        $real = array_map(fn ($o) => $o[0].' '.$o[1], $this->appOperations());
        $stale = [];

        foreach ($this->specOperations() as [$method, $path]) {
            if (! in_array($method.' '.$path, $real, true)) {
                $stale[] = strtoupper($method).' '.$path;
            }
        }

        $this->assertSame([], $stale, "The spec documents endpoints that do not exist:\n".implode("\n", $stale));
    }

    public function test_signed_endpoints_declare_the_signing_headers(): void
    {
        $schemes = ['ChatDevice', 'ChatTs', 'ChatNonce', 'ChatSig'];
        $wrong = [];

        foreach ($this->appOperations(withMiddleware: true) as [$method, $path, $signed]) {
            $operation = $this->spec['paths'][$path][$method] ?? null;
            if ($operation === null) {
                continue; // reported by the first test
            }

            $declared = ! empty($operation['security']);
            if ($declared !== $signed) {
                $wrong[] = strtoupper($method).' '.$path
                    .($signed ? ' is signed but the spec says otherwise' : ' is public but the spec requires a signature');

                continue;
            }
            if ($signed && array_keys($operation['security'][0]) !== $schemes) {
                $wrong[] = strtoupper($method).' '.$path.' does not require all four signing headers';
            }
        }

        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    public function test_every_operation_is_described_and_answers_something(): void
    {
        $bad = [];

        foreach ($this->specOperations() as [$method, $path, $operation]) {
            $label = strtoupper($method).' '.$path;
            if (empty($operation['summary'])) {
                $bad[] = $label.' has no summary';
            }
            if (empty($operation['tags'])) {
                $bad[] = $label.' has no tag';
            }
            if (empty($operation['responses'])) {
                $bad[] = $label.' documents no response';
            } elseif (! array_filter(array_keys($operation['responses']), fn ($c) => (int) $c < 300)) {
                $bad[] = $label.' documents no success response';
            }
        }

        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_no_reference_dangles(): void
    {
        $broken = [];
        $walk = function ($node, string $where) use (&$walk, &$broken) {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if ($key === '$ref' && is_string($value)) {
                    if (! $this->resolve($value)) {
                        $broken[] = $value.' (at '.$where.')';
                    }

                    continue;
                }
                $walk($value, $where.'/'.$key);
            }
        };
        $walk($this->spec['paths'], 'paths');
        $walk($this->spec['components']['schemas'], 'components/schemas');

        $this->assertSame([], array_unique($broken), implode("\n", array_unique($broken)));
    }

    public function test_every_declared_tag_is_used_and_every_used_tag_declared(): void
    {
        $declared = array_column($this->spec['tags'], 'name');
        $used = [];
        foreach ($this->specOperations() as [, , $operation]) {
            $used = array_merge($used, $operation['tags'] ?? []);
        }
        $used = array_unique($used);

        $this->assertSame([], array_values(array_diff($used, $declared)), 'Operations use tags that are not declared.');
        $this->assertSame([], array_values(array_diff($declared, $used)), 'Some declared tags are on no operation.');
    }

    public function test_the_spec_is_served_with_this_installations_url_and_version(): void
    {
        $response = $this->getJson('/api/openapi.json')->assertOk();

        $response->assertJsonPath('openapi', '3.1.0');
        $response->assertJsonPath('info.version', config('app.version'));
        $response->assertJsonPath('servers.0.url', rtrim(config('app.url'), '/'));
    }

    public function test_the_reference_page_renders(): void
    {
        $this->get('/api/docs')->assertOk()->assertSee('Build your own Krotze client');
    }

    /* ------------------------------ helpers ------------------------------- */

    /**
     * Every documentable operation the app actually serves, as
     * [method, path] — or [method, path, isSigned] with $withMiddleware.
     *
     * @return list<array>
     */
    private function appOperations(bool $withMiddleware = false): array
    {
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $covered = array_filter(
                self::DOCUMENTED_PREFIXES,
                fn ($prefix) => str_starts_with($uri, $prefix),
            );
            if (! $covered || in_array($uri, self::NOT_DOCUMENTED, true)) {
                continue;
            }

            // The upload route makes its variant optional; the spec spells the
            // variant out, since that is the form a client actually builds.
            $path = '/'.str_replace('{variant?}', '{variant}', $uri);
            $signed = in_array('chat.auth', $route->gatherMiddleware(), true);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $operations[] = $withMiddleware
                    ? [strtolower($method), $path, $signed]
                    : [strtolower($method), $path];
            }
        }

        return $operations;
    }

    /** @return list<array{0: string, 1: string, 2: array}> */
    private function specOperations(): array
    {
        $operations = [];
        foreach ($this->spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $operations[] = [$method, $path, $operation];
            }
        }

        return $operations;
    }

    /** Follow a local JSON pointer, e.g. #/components/schemas/Message. */
    private function resolve(string $ref): bool
    {
        if (! str_starts_with($ref, '#/')) {
            return false;
        }

        $node = $this->spec;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return false;
            }
            $node = $node[$segment];
        }

        return true;
    }
}
