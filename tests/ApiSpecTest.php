<?php

declare(strict_types=1);

namespace LaravelCloudDeploy\Tests;

use PHPUnit\Framework\TestCase;

class ApiSpecTest extends TestCase
{
    public function test_required_paths_exist(): void
    {
        $url = getenv('LARAVEL_CLOUD_API_SPEC_URL') ?: 'https://cloud.laravel.com/api-docs/api.json';
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
            ],
        ]);
        $specJson = (string) @file_get_contents($url, false, $context);
        $this->assertNotSame('', $specJson, "Unable to download api spec from {$url}");

        $spec = json_decode($specJson, true);
        $this->assertIsArray($spec, 'api.json must be valid JSON');

        $paths = $spec['paths'] ?? null;
        $this->assertIsArray($paths, 'api.json paths missing');

        $required = [
            '/applications',
            '/applications/{application}/environments',
            '/environments/{environment}',
            '/environments/{environment}/deployments',
            '/deployments/{deployment}',
        ];

        foreach ($required as $path) {
            $this->assertArrayHasKey($path, $paths, "Missing path {$path}");
        }
    }
}
