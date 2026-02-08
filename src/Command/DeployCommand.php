<?php

declare(strict_types=1);

namespace LaravelCloudDeploy\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use LaravelCloudDeploy\Support\CloudApi;
use LaravelCloudDeploy\Support\Output;
use LaravelCloudDeploy\Support\UrlHelper;

#[AsCommand(
    name: 'deploy',
    description: 'Deploy a Laravel Cloud environment and report progress.',
)]
class DeployCommand extends Command
{
    private CloudApi $api;
    private Output $out;

    public function __construct(?CloudApi $api = null, ?Output $out = null)
    {
        parent::__construct();
        $this->api = $api ?? new CloudApi();
        $this->out = $out ?? new Output();
    }

    protected function configure(): void
    {
        $this
            ->addOption('api-key', null, InputOption::VALUE_OPTIONAL, 'Laravel Cloud API token (falls back to LARAVEL_CLOUD_API_TOKEN)')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'Environment id (falls back to LARAVEL_CLOUD_ENVIRONMENT)')
            ->addOption('application-name', null, InputOption::VALUE_OPTIONAL, 'Application name (falls back to LARAVEL_CLOUD_APPLICATION_NAME)')
            ->addOption('environment-name', null, InputOption::VALUE_OPTIONAL, 'Environment name (falls back to LARAVEL_CLOUD_ENVIRONMENT_NAME)')
            ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Do not wait for deployment')
            ->addOption('poll-interval', null, InputOption::VALUE_OPTIONAL, 'Poll interval seconds (falls back to LARAVEL_CLOUD_POLL_INTERVAL)')
            ->addOption('timeout', null, InputOption::VALUE_OPTIONAL, 'Timeout seconds (falls back to LARAVEL_CLOUD_TIMEOUT)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = $this->optionOrEnv($input, 'api-key', 'LARAVEL_CLOUD_API_TOKEN');
        $environmentId = $this->optionOrEnv($input, 'environment', 'LARAVEL_CLOUD_ENVIRONMENT');
        $applicationName = $this->optionOrEnv($input, 'application-name', 'LARAVEL_CLOUD_APPLICATION_NAME');
        $environmentName = $this->optionOrEnv($input, 'environment-name', 'LARAVEL_CLOUD_ENVIRONMENT_NAME');
        $shouldWait = ! $input->getOption('no-wait');
        $pollInterval = (int) ($this->optionOrEnv($input, 'poll-interval', 'LARAVEL_CLOUD_POLL_INTERVAL') ?? '10');
        $timeoutSeconds = (int) ($this->optionOrEnv($input, 'timeout', 'LARAVEL_CLOUD_TIMEOUT') ?? '1800');

        $out = $this->out;
        $api = $this->api;

        if ($token === null || $token === '') {
            $out->fail('Missing required env var: LARAVEL_CLOUD_API_TOKEN', 'config_error', 2);
        }

        $debug = getenv('ACTIONS_STEP_DEBUG') === 'true' || getenv('RUNNER_DEBUG') === '1';
        if ($debug) {
            $tokenLen = strlen($token);
            $tokenPrefix = substr($token, 0, 6);
            $tokenSuffix = substr($token, -4);
            fwrite(STDERR, "[debug] token_length={$tokenLen} token_prefix={$tokenPrefix} token_suffix={$tokenSuffix}\n");
        }

        $shouldWait = (bool) $shouldWait;

        if ($environmentId === null || $environmentId === '') {
            if ($applicationName === null || $applicationName === '' || $environmentName === null || $environmentName === '') {
                $out->fail('Provide either LARAVEL_CLOUD_ENVIRONMENT (id) or both LARAVEL_CLOUD_APPLICATION_NAME and LARAVEL_CLOUD_ENVIRONMENT_NAME', 'config_error', 2);
            }

            $environmentId = $this->resolveEnvironmentId($api, $out, $token, $applicationName, $environmentName);
        }

        $environmentUrl = $this->resolveEnvironmentUrl($api, $token, $environmentId);

        $response = $api->request('POST', "/environments/{$environmentId}/deployments", $token);
        if ($response['status'] === 0) {
            $out->fail('Failed to initiate deployment (network error).', 'api_error', 1, $response['raw']);
        }
        if ($response['status'] >= 300) {
            $out->fail("Failed to initiate deployment (HTTP {$response['status']}).", 'api_error', 1, $response['raw']);
        }
        if ($response['payload'] === null) {
            $out->fail('Failed to parse deployment response (invalid JSON).', 'invalid_response', 1, $response['raw']);
        }

        $deployment = $response['payload']['data'] ?? null;
        if (!is_array($deployment) || !isset($deployment['id'])) {
            $out->fail('Deployment response missing id', 'invalid_response', 1, $response['raw']);
        }

        $deploymentId = (string) $deployment['id'];
        $deploymentStatus = (string) ($deployment['attributes']['status'] ?? 'unknown');
        $deploymentLink = UrlHelper::normalizeLink($deployment['links']['self'] ?? null);

        $out->setOutput('deployment_id', $deploymentId);
        if ($deploymentLink) {
            $out->setOutput('deployment_url', $deploymentLink);
        }
        if ($environmentUrl) {
            $out->setOutput('environment_url', $environmentUrl);
        }

        if (!$shouldWait) {
            $out->setOutput('deployment_status', $deploymentStatus);
            $out->setOutput('success', 'false');
            $out->summary("Deployment triggered: `{$deploymentId}`");
            return Command::SUCCESS;
        }

        $terminalSuccess = ['deployment.succeeded'];
        $terminalFailure = ['build.failed', 'failed', 'deployment.failed', 'cancelled'];
        $deploymentLogged = false;
        $start = time();

        fwrite(STDOUT, "Build started. Waiting for deployment to begin...\n");

        while (true) {
            if (time() - $start > $timeoutSeconds) {
                $out->fail('Deployment polling timed out', 'timeout', 1);
            }

            $statusResponse = $api->request('GET', "/deployments/{$deploymentId}", $token);
            if ($statusResponse['status'] === 0) {
                $out->fail('Failed to fetch deployment status (network error).', 'api_error', 1, $statusResponse['raw']);
            }
            if ($statusResponse['status'] >= 300) {
                $out->fail("Failed to fetch deployment status (HTTP {$statusResponse['status']}).", 'api_error', 1, $statusResponse['raw']);
            }
            if ($statusResponse['payload'] === null) {
                $out->fail('Failed to parse deployment status response (invalid JSON).', 'invalid_response', 1, $statusResponse['raw']);
            }

            $deployment = $statusResponse['payload']['data'] ?? null;
            if (!is_array($deployment)) {
                $out->fail('Deployment status response missing data', 'invalid_response', 1, $statusResponse['raw']);
            }

            $deploymentStatus = (string) ($deployment['attributes']['status'] ?? 'unknown');

            if (!$deploymentLogged && str_starts_with($deploymentStatus, 'deployment.')) {
                $deploymentLogged = true;
                fwrite(STDOUT, "Deployment step started.\n");
            }

            if (in_array($deploymentStatus, $terminalSuccess, true)) {
                $out->setOutput('deployment_status', $deploymentStatus);
                $out->setOutput('success', 'true');
                $out->summary("Deployment succeeded: `{$deploymentId}`");
                if ($environmentUrl) {
                    $out->summary("Environment: {$environmentUrl}");
                }
                fwrite(STDOUT, "Deployment completed successfully.\n");
                if ($environmentUrl) {
                    fwrite(STDOUT, "Environment: {$environmentUrl}\n");
                }
                return Command::SUCCESS;
            }

            if (in_array($deploymentStatus, $terminalFailure, true)) {
                $failureReason = $deployment['attributes']['failure_reason'] ?? null;
                $out->setOutput('deployment_status', $deploymentStatus);
                $out->setOutput('success', 'false');
                $out->summary("Deployment failed: `{$deploymentId}`");
                if ($environmentUrl) {
                    $out->summary("Environment: {$environmentUrl}");
                }
                if (is_string($failureReason) && $failureReason !== '') {
                    $out->summary("Failure reason: {$failureReason}");
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

    private function resolveEnvironmentId(CloudApi $api, Output $out, string $token, string $applicationName, string $environmentName): string
    {
        $appQuery = http_build_query(['filter' => ['name' => $applicationName]], '', '&', PHP_QUERY_RFC3986);
        $response = $api->request('GET', "/applications?{$appQuery}", $token);
        if ($response['status'] === 0) {
            $out->fail('Failed to fetch applications (network error).', 'api_error', 1, $response['raw']);
        }
        if ($response['status'] >= 300) {
            $out->fail("Failed to fetch applications (HTTP {$response['status']}).", 'api_error', 1, $response['raw']);
        }
        if ($response['payload'] === null) {
            $out->fail('Failed to parse applications response (invalid JSON).', 'invalid_response', 1, $response['raw']);
        }

        $applications = $response['payload']['data'] ?? [];
        if (!is_array($applications) || count($applications) === 0) {
            $out->fail("No applications found for name: {$applicationName}", 'not_found', 1);
        }

        $application = $this->selectByName($out, $applications, $applicationName, 'application');
        if (!isset($application['id'])) {
            $out->fail('Application response missing id', 'invalid_response', 1);
        }

        $applicationId = (string) $application['id'];
        $envQuery = http_build_query(['filter' => ['name' => $environmentName]], '', '&', PHP_QUERY_RFC3986);
        $envResponse = $api->request('GET', "/applications/{$applicationId}/environments?{$envQuery}", $token);
        if ($envResponse['status'] === 0) {
            $out->fail("Failed to fetch environments for application {$applicationId} (network error).", 'api_error', 1, $envResponse['raw']);
        }
        if ($envResponse['status'] >= 300) {
            $out->fail("Failed to fetch environments for application {$applicationId} (HTTP {$envResponse['status']}).", 'api_error', 1, $envResponse['raw']);
        }
        if ($envResponse['payload'] === null) {
            $out->fail('Failed to parse environments response (invalid JSON).', 'invalid_response', 1, $envResponse['raw']);
        }

        $environments = $envResponse['payload']['data'] ?? [];
        if (!is_array($environments) || count($environments) === 0) {
            $out->fail("No environments found for name: {$environmentName}", 'not_found', 1);
        }

        $environment = $this->selectByName($out, $environments, $environmentName, 'environment');
        if (!isset($environment['id'])) {
            $out->fail('Environment response missing id', 'invalid_response', 1);
        }

        return (string) $environment['id'];
    }

    private function selectByName(Output $out, array $items, string $target, string $label): array
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
            $out->fail("Multiple {$label} matches found for: {$target}", 'not_found', 1);
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
        $out->fail("No unique {$label} match found for: {$target}", 'not_found', 1);
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

    private function optionOrEnv(InputInterface $input, string $option, string $env): ?string
    {
        $value = $input->getOption($option);
        if (is_string($value) && $value !== '') {
            return trim($value);
        }

        $envValue = getenv($env);
        if ($envValue === false || $envValue === '') {
            return null;
        }

        return trim($envValue);
    }

}
