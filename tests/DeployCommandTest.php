<?php

declare(strict_types=1);

namespace LaravelCloudDeploy\Tests;

use LaravelCloudDeploy\Command\DeployCommand;
use LaravelCloudDeploy\Support\CloudApi;
use LaravelCloudDeploy\Tests\Support\TestOutput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DeployCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('LARAVEL_CLOUD_API_TOKEN=');
        putenv('LARAVEL_CLOUD_ENVIRONMENT=');
        putenv('LARAVEL_CLOUD_APPLICATION_NAME=');
        putenv('LARAVEL_CLOUD_ENVIRONMENT_NAME=');
        putenv('LARAVEL_CLOUD_POLL_INTERVAL=');
        putenv('LARAVEL_CLOUD_TIMEOUT=');
    }

    public function test_requires_token(): void
    {
        $command = new DeployCommand(new CloudApi(), new TestOutput());
        $input = new ArrayInput([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required env var');
        $command->run($input, new NullOutput());
    }

    public function test_resolves_env_id_and_triggers_deploy_without_wait(): void
    {
        $api = new class extends CloudApi {
            public array $calls = [];

            public function request(string $method, string $path, string $token, ?array $body = null): array
            {
                $this->calls[] = [$method, $path];

                if ($path === '/applications?filter%5Bname%5D=My%20App') {
                    return ['status' => 200, 'payload' => [
                        'data' => [[
                            'id' => 'app_1',
                            'attributes' => [
                                'name' => 'My App',
                                'slug' => 'my-app',
                            ],
                        ]],
                    ], 'raw' => '{}'];
                }

                if ($path === '/applications/app_1/environments?filter%5Bname%5D=production') {
                    return ['status' => 200, 'payload' => [
                        'data' => [[
                            'id' => 'env_1',
                            'attributes' => [
                                'name' => 'production',
                                'slug' => 'production',
                            ],
                        ]],
                    ], 'raw' => '{}'];
                }

                if ($path === '/environments/env_1') {
                    return ['status' => 200, 'payload' => [
                        'data' => [
                            'attributes' => [
                                'vanity_domain' => 'example.test',
                            ],
                            'links' => [
                                'self' => ['href' => 'https://cloud.laravel.com/api/environments/env_1'],
                            ],
                        ],
                    ], 'raw' => '{}'];
                }

                if ($path === '/environments/env_1/deployments') {
                    return ['status' => 200, 'payload' => [
                        'data' => [
                            'id' => 'dep_1',
                            'attributes' => [
                                'status' => 'pending',
                            ],
                            'links' => [
                                'self' => ['href' => 'https://cloud.laravel.com/api/deployments/dep_1'],
                            ],
                        ],
                    ], 'raw' => '{}'];
                }

                return ['status' => 500, 'payload' => null, 'raw' => 'error'];
            }
        };

        putenv('LARAVEL_CLOUD_API_TOKEN=token');
        putenv('LARAVEL_CLOUD_APPLICATION_NAME=My App');
        putenv('LARAVEL_CLOUD_ENVIRONMENT_NAME=production');

        $command = new DeployCommand($api, new TestOutput());

        $input = new ArrayInput(['--no-wait' => true]);
        $exitCode = $command->run($input, new NullOutput());

        $this->assertSame(0, $exitCode);
        $this->assertSame([
            ['GET', '/applications?filter%5Bname%5D=My%20App'],
            ['GET', '/applications/app_1/environments?filter%5Bname%5D=production'],
            ['GET', '/environments/env_1'],
            ['POST', '/environments/env_1/deployments'],
        ], $api->calls);
    }
}
