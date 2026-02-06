<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Support\ActionOutput;
use App\Support\CloudApi;
use App\Support\UrlHelper;

class DeployCommand extends Command
{
    protected $signature = 'cloud:deploy';

    protected $description = 'Deploy a Laravel Cloud environment and report progress.';

    public function handle(CloudApi $api, ActionOutput $output): int
    {
        $token = getenv('LARAVEL_CLOUD_API_TOKEN') ?: null;
        $environmentId = getenv('LARAVEL_CLOUD_ENVIRONMENT') ?: null;
        $applicationName = getenv('LARAVEL_CLOUD_APPLICATION_NAME') ?: null;
        $environmentName = getenv('LARAVEL_CLOUD_ENVIRONMENT_NAME') ?: null;
        $waitValue = strtolower(getenv('LARAVEL_CLOUD_WAIT') ?: 'true');
        $pollInterval = (int) (getenv('LARAVEL_CLOUD_POLL_INTERVAL') ?: '10');
        $timeoutSeconds = (int) (getenv('LARAVEL_CLOUD_TIMEOUT') ?: '1800');

        if ($token === null || $token === '') {
            $output->fail('Missing required env var: LARAVEL_CLOUD_API_TOKEN', 'config_error', 2);
        }

        $shouldWait = in_array($waitValue, ['1', 'true', 'yes'], true);

        if ($environmentId === null || $environmentId === '') {
            if ($applicationName === null || $applicationName === '' || $environmentName === null || $environmentName === '') {
                $output->fail('Provide either LARAVEL_CLOUD_ENVIRONMENT (id) or both LARAVEL_CLOUD_APPLICATION_NAME and LARAVEL_CLOUD_ENVIRONMENT_NAME', 'config_error', 2);
            }

            $environmentId = $this->resolveEnvironmentId($api, $output, $token, $applicationName, $environmentName);
        }

        $environmentUrl = $this->resolveEnvironmentUrl($api, $token, $environmentId);

        $response = $api->request('POST', "/environments/{$environmentId}/deployments", $token);
        if ($response['status'] === 0) {
            $output->fail('Failed to initiate deployment (network error).', 'api_error', 1, $response['raw']);
        }
        if ($response['status'] >= 300) {
            $output->fail("Failed to initiate deployment (HTTP {$response['status']}).", 'api_error', 1, $response['raw']);
        }
        if ($response['payload'] === null) {
            $output->fail('Failed to parse deployment response (invalid JSON).', 'invalid_response', 1, $response['raw']);
        }

        $deployment = $response['payload']['data'] ?? null;
        if (!is_array($deployment) || !isset($deployment['id'])) {
            $output->fail('Deployment response missing id', 'invalid_response', 1, $response['raw']);
        }

        $deploymentId = (string) $deployment['id'];
        $deploymentStatus = $deployment['attributes']['status'] ?? 'unknown';
        $deploymentLink = UrlHelper::normalizeLink($deployment['links']['self'] ?? null);

        $output->set('deployment_id', $deploymentId);
        if ($deploymentLink) {
            $output->set('deployment_url', $deploymentLink);
        }
        if ($environmentUrl) {
            $output->set('environment_url', $environmentUrl);
        }

        if (!$shouldWait) {
            $output->set('deployment_status', (string) $deploymentStatus);
            $output->set('success', 'false');
            $output->summary("Deployment triggered: `{$deploymentId}`");
            return Command::SUCCESS;
        }

        $terminalSuccess = ['deployment.succeeded'];
        $terminalFailure = ['build.failed', 'failed', 'deployment.failed', 'cancelled'];
        $deploymentLogged = false;
        $start = time();

        fwrite(STDOUT, "Build started. Waiting for deployment to begin...\n");

        while (true) {
            if (time() - $start > $timeoutSeconds) {
                $output->fail('Deployment polling timed out', 'timeout', 1);
            }

            $statusResponse = $api->request('GET', "/deployments/{$deploymentId}", $token);
            if ($statusResponse['status'] === 0) {
                $output->fail('Failed to fetch deployment status (network error).', 'api_error', 1, $statusResponse['raw']);
            }
            if ($statusResponse['status'] >= 300) {
                $output->fail("Failed to fetch deployment status (HTTP {$statusResponse['status']}).", 'api_error', 1, $statusResponse['raw']);
            }
            if ($statusResponse['payload'] === null) {
                $output->fail('Failed to parse deployment status response (invalid JSON).', 'invalid_response', 1, $statusResponse['raw']);
            }

            $deployment = $statusResponse['payload']['data'] ?? null;
            if (!is_array($deployment)) {
                $output->fail('Deployment status response missing data', 'invalid_response', 1, $statusResponse['raw']);
            }

            $deploymentStatus = (string) ($deployment['attributes']['status'] ?? 'unknown');

            if (!$deploymentLogged && str_starts_with($deploymentStatus, 'deployment.')) {
                $deploymentLogged = true;
                fwrite(STDOUT, "Deployment step started.\n");
            }

            if (in_array($deploymentStatus, $terminalSuccess, true)) {
                $output->set('deployment_status', $deploymentStatus);
                $output->set('success', 'true');
                $output->summary("Deployment succeeded: `{$deploymentId}`");
                if ($environmentUrl) {
                    $output->summary("Environment: {$environmentUrl}");
                }
                fwrite(STDOUT, "Deployment completed successfully.\n");
                if ($environmentUrl) {
                    fwrite(STDOUT, "Environment: {$environmentUrl}\n");
                }
                return Command::SUCCESS;
            }

            if (in_array($deploymentStatus, $terminalFailure, true)) {
                $failureReason = $deployment['attributes']['failure_reason'] ?? null;
                $output->set('deployment_status', $deploymentStatus);
                $output->set('success', 'false');
                $output->summary("Deployment failed: `{$deploymentId}`");
                if ($environmentUrl) {
                    $output->summary("Environment: {$environmentUrl}");
                }
                if (is_string($failureReason) && $failureReason !== '') {
                    $output->summary("Failure reason: {$failureReason}");
                }
                fwrite(STDERR, "Deployment failed: {$deploymentStatus}\n");
                if (is_string($failureReason) && $failureReason !== '') {
                    fwrite(STDERR, "Failure reason: {$failureReason}\n");
                }
                if ($environmentUrl) {
                    fwrite(STDERR, "Environment: {$environmentUrl}\n");
                }
                return Command::FAILURE;
            }

            sleep($pollInterval);
        }
    }

    private function resolveEnvironmentId(CloudApi $api, ActionOutput $output, string $token, string $applicationName, string $environmentName): string
    {
        $appQuery = http_build_query(['filter' => ['name' => $applicationName]], '', '&', PHP_QUERY_RFC3986);
        $response = $api->request('GET', "/applications?{$appQuery}", $token);
        if ($response['status'] === 0) {
            $output->fail('Failed to fetch applications (network error).', 'api_error', 1, $response['raw']);
        }
        if ($response['status'] >= 300) {
            $output->fail("Failed to fetch applications (HTTP {$response['status']}).", 'api_error', 1, $response['raw']);
        }
        if ($response['payload'] === null) {
            $output->fail('Failed to parse applications response (invalid JSON).', 'invalid_response', 1, $response['raw']);
        }

        $applications = $response['payload']['data'] ?? [];
        if (!is_array($applications) || count($applications) === 0) {
            $output->fail("No applications found for name: {$applicationName}", 'not_found', 1);
        }

        $application = $this->selectByName($applications, $applicationName, 'application');
        if (!isset($application['id'])) {
            $output->fail('Application response missing id', 'invalid_response', 1);
        }

        $applicationId = (string) $application['id'];
        $envQuery = http_build_query(['filter' => ['name' => $environmentName]], '', '&', PHP_QUERY_RFC3986);
        $envResponse = $api->request('GET', "/applications/{$applicationId}/environments?{$envQuery}", $token);
        if ($envResponse['status'] === 0) {
            $output->fail("Failed to fetch environments for application {$applicationId} (network error).", 'api_error', 1, $envResponse['raw']);
        }
        if ($envResponse['status'] >= 300) {
            $output->fail("Failed to fetch environments for application {$applicationId} (HTTP {$envResponse['status']}).", 'api_error', 1, $envResponse['raw']);
        }
        if ($envResponse['payload'] === null) {
            $output->fail('Failed to parse environments response (invalid JSON).', 'invalid_response', 1, $envResponse['raw']);
        }

        $environments = $envResponse['payload']['data'] ?? [];
        if (!is_array($environments) || count($environments) === 0) {
            $output->fail("No environments found for name: {$environmentName}", 'not_found', 1);
        }

        $environment = $this->selectByName($environments, $environmentName, 'environment');
        if (!isset($environment['id'])) {
            $output->fail('Environment response missing id', 'invalid_response', 1);
        }

        return (string) $environment['id'];
    }

    private function selectByName(array $items, string $target, string $label): array
    {
        $exact = array_values(array_filter($items, function ($item) use ($target) {
            $name = $item['attributes']['name'] ?? null;
            $slug = $item['attributes']['slug'] ?? null;
            return $name === $target || $slug === $target;
        }));

        if (count($exact) === 1) {
            return $exact[0];
        }
        if (count($exact) > 1) {
            fwrite(STDERR, "Multiple {$label} matches found for: {$target}\n");
            foreach ($exact as $match) {
                $name = $match['attributes']['name'] ?? 'unknown';
                $slug = $match['attributes']['slug'] ?? 'unknown';
                $id = $match['id'] ?? 'unknown';
                fwrite(STDERR, "- name: {$name}, slug: {$slug}, id: {$id}\n");
            }
            exit(1);
        }
        if (count($items) === 1) {
            return $items[0];
        }

        $lower = strtolower($target);
        $ci = array_values(array_filter($items, function ($item) use ($lower) {
            $name = $item['attributes']['name'] ?? null;
            $slug = $item['attributes']['slug'] ?? null;
            return ($name !== null && strtolower($name) === $lower) || ($slug !== null && strtolower($slug) === $lower);
        }));

        if (count($ci) === 1) {
            return $ci[0];
        }

        fwrite(STDERR, "No unique {$label} match found for: {$target}\n");
        if (count($ci) > 1) {
            fwrite(STDERR, "Case-insensitive matches:\n");
            foreach ($ci as $match) {
                $name = $match['attributes']['name'] ?? 'unknown';
                $slug = $match['attributes']['slug'] ?? 'unknown';
                $id = $match['id'] ?? 'unknown';
                fwrite(STDERR, "- name: {$name}, slug: {$slug}, id: {$id}\n");
            }
        } elseif (count($items) > 0) {
            fwrite(STDERR, "Available {$label}s:\n");
            foreach ($items as $match) {
                $name = $match['attributes']['name'] ?? 'unknown';
                $slug = $match['attributes']['slug'] ?? 'unknown';
                $id = $match['id'] ?? 'unknown';
                fwrite(STDERR, "- name: {$name}, slug: {$slug}, id: {$id}\n");
            }
        }
        exit(1);
    }

    private function resolveEnvironmentUrl(CloudApi $api, string $token, string $environmentId): ?string
    {
        $response = $api->request('GET', "/environments/{$environmentId}", $token);
        if ($response['status'] === 0 || $response['status'] >= 300 || $response['payload'] === null) {
            return null;
        }

        $envData = $response['payload']['data'] ?? null;
        if (!is_array($envData)) {
            return null;
        }

        $vanityDomain = $envData['attributes']['vanity_domain'] ?? null;
        $envLink = UrlHelper::normalizeLink($envData['links']['self'] ?? null);

        return UrlHelper::buildEnvironmentUrl(is_string($vanityDomain) ? $vanityDomain : null, $envLink);
    }
}
