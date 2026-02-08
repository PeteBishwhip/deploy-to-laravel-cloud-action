<?php

declare(strict_types=1);

namespace LaravelCloudDeploy\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class CloudApi
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://cloud.laravel.com/api',
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'laravel-cloud-deploy-action',
            ],
        ]);
    }

    /**
     * @return array{status:int,payload:array|null,raw:string}
     */
    public function request(string $method, string $path, string $token, ?array $body = null): array
    {
        $path = '/' . ltrim($path, '/');
        $debug = getenv('ACTIONS_STEP_DEBUG') === 'true' || getenv('RUNNER_DEBUG') === '1';
        if ($debug) {
            $baseUri = rtrim((string) $this->client->getConfig('base_uri'), '/');
            fwrite(STDERR, "[cloud-api] {$method} {$baseUri}{$path}\n");
        }
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ];

        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->client->request($method, $path, $options);
            $raw = (string) $response->getBody();
            $payload = null;
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $payload = $decoded;
                }
            }

            return ['status' => $response->getStatusCode(), 'payload' => $payload, 'raw' => $raw];
        } catch (GuzzleException $e) {
            return ['status' => 0, 'payload' => null, 'raw' => $e->getMessage()];
        }
    }
}
