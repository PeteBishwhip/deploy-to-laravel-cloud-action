#!/usr/bin/env php
<?php

declare(strict_types=1);

const BASE_URL = 'https://cloud.laravel.com/api';

function env(string $name, bool $required = false, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        $value = $default;
    }
    if ($required && ($value === null || $value === '')) {
        return null;
    }
    return $value;
}

function set_output(string $name, string $value): void
{
    $outputPath = getenv('GITHUB_OUTPUT');
    if ($outputPath === false || $outputPath === '') {
        return;
    }
    file_put_contents($outputPath, $name . '=' . $value . "\n", FILE_APPEND);
}

function normalize_link(mixed $link): ?string
{
    if ($link === null) {
        return null;
    }
    if (is_string($link)) {
        return $link;
    }
    if (is_array($link)) {
        $href = $link['href'] ?? null;
        if (is_string($href)) {
            return $href;
        }
    }
    return null;
}

function append_summary(string $line): void
{
    $summaryPath = getenv('GITHUB_STEP_SUMMARY');
    if ($summaryPath === false || $summaryPath === '') {
        return;
    }
    file_put_contents($summaryPath, $line . "\n", FILE_APPEND);
}

function fail(string $message, string $status = 'error', int $exitCode = 1, ?string $raw = null): void
{
    set_output('deployment_status', $status);
    set_output('success', 'false');
    append_summary("Deployment error: {$message}");
    fwrite(STDERR, $message . "\n");
    if ($raw !== null && $raw !== '') {
        fwrite(STDERR, $raw . "\n");
    }
    exit($exitCode);
}

/**
 * @return array{0:int,1:array|null,2:string}
 */
function request(string $method, string $path, string $token, ?array $body = null): array
{
    $url = BASE_URL . $path;
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.api+json',
        'Content-Type: application/json',
        'User-Agent: laravel-cloud-deploy-action',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($body !== null) {
        $payload = json_encode($body);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    } elseif (in_array(strtoupper($method), ['POST', 'PATCH', 'PUT'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return [0, null, $error];
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $payload = null;
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $payload = $decoded;
        }
    }

    return [$status, $payload, $raw];
}

if (defined('LARAVEL_CLOUD_DEPLOY_TEST')) {
    return;
}

$token = env('LARAVEL_CLOUD_API_TOKEN', true);
$environmentId = env('LARAVEL_CLOUD_ENVIRONMENT');
$applicationName = env('LARAVEL_CLOUD_APPLICATION_NAME');
$environmentName = env('LARAVEL_CLOUD_ENVIRONMENT_NAME');
$wait = strtolower(env('LARAVEL_CLOUD_WAIT', false, 'true') ?? 'true');
$pollInterval = (int) (env('LARAVEL_CLOUD_POLL_INTERVAL', false, '10') ?? '10');
$timeoutSeconds = (int) (env('LARAVEL_CLOUD_TIMEOUT', false, '180') ?? '180');

if ($token === null || $token === '') {
    fail('Missing required env var: LARAVEL_CLOUD_API_TOKEN', 'config_error', 2);
}

$shouldWait = in_array($wait, ['1', 'true', 'yes'], true);

if ($environmentId === null || $environmentId === '') {
    if ($applicationName === null || $applicationName === '' || $environmentName === null || $environmentName === '') {
        fail('Provide either LARAVEL_CLOUD_ENVIRONMENT (id) or both LARAVEL_CLOUD_APPLICATION_NAME and LARAVEL_CLOUD_ENVIRONMENT_NAME', 'config_error', 2);
    }

    $appQuery = http_build_query(['filter' => ['name' => $applicationName]], '', '&', PHP_QUERY_RFC3986);
    [$status, $payload, $raw] = request('GET', "/applications?{$appQuery}", $token);
    if ($status === 0) {
        fail('Failed to fetch applications (network error).', 'api_error', 1, $raw);
    }
    if ($status >= 300) {
        fail("Failed to fetch applications (HTTP {$status}).", 'api_error', 1, $raw);
    }
    if ($payload === null) {
        fail('Failed to parse applications response (invalid JSON).', 'invalid_response', 1, $raw);
    }

    $applications = $payload['data'] ?? [];
    if (!is_array($applications) || count($applications) === 0) {
        fail("No applications found for name: {$applicationName}", 'not_found', 1);
    }

    $selectByName = function (array $items, string $target, string $label): array {
        $exact = array_values(array_filter($items, function ($item) use ($target) {
            $name = $item['attributes']['name'] ?? null;
            $slug = $item['attributes']['slug'] ?? null;
            return $name === $target || $slug === $target;
        }));

        if (count($exact) === 1) {
            return $exact[0];
        }
        if (count($exact) > 1) {
            fwrite(STDERR, "Multiple {$label} matches found for: {$target}\\n");
            foreach ($exact as $match) {
                $name = $match['attributes']['name'] ?? 'unknown';
                $slug = $match['attributes']['slug'] ?? 'unknown';
                $id = $match['id'] ?? 'unknown';
                fwrite(STDERR, "- name: {$name}, slug: {$slug}, id: {$id}\\n");
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

        fwrite(STDERR, "No unique {$label} match found for: {$target}\\n");
        if (count($ci) > 1) {
            fwrite(STDERR, "Case-insensitive matches:\\n");
            foreach ($ci as $match) {
                $name = $match['attributes']['name'] ?? 'unknown';
                $slug = $match['attributes']['slug'] ?? 'unknown';
                $id = $match['id'] ?? 'unknown';
                fwrite(STDERR, "- name: {$name}, slug: {$slug}, id: {$id}\\n");
            }
        } elseif (count($items) > 0) {
            fwrite(STDERR, "Available {$label}s:\\n");
            foreach ($items as $match) {
                $name = $match['attributes']['name'] ?? 'unknown';
                $slug = $match['attributes']['slug'] ?? 'unknown';
                $id = $match['id'] ?? 'unknown';
                fwrite(STDERR, "- name: {$name}, slug: {$slug}, id: {$id}\\n");
            }
        }
        exit(1);
    };

    $application = $selectByName($applications, $applicationName, 'application');
    if (!isset($application['id'])) {
        fail('Application response missing id', 'invalid_response', 1);
    }

    $applicationId = $application['id'];
    $envQuery = http_build_query(['filter' => ['name' => $environmentName]], '', '&', PHP_QUERY_RFC3986);
    [$status, $payload, $raw] = request('GET', "/applications/{$applicationId}/environments?{$envQuery}", $token);
    if ($status === 0) {
        fail("Failed to fetch environments for application {$applicationId} (network error).", 'api_error', 1, $raw);
    }
    if ($status >= 300) {
        fail("Failed to fetch environments for application {$applicationId} (HTTP {$status}).", 'api_error', 1, $raw);
    }
    if ($payload === null) {
        fail('Failed to parse environments response (invalid JSON).', 'invalid_response', 1, $raw);
    }

    $environments = $payload['data'] ?? [];
    if (!is_array($environments) || count($environments) === 0) {
        fail("No environments found for name: {$environmentName}", 'not_found', 1);
    }

    $environment = $selectByName($environments, $environmentName, 'environment');
    if (!isset($environment['id'])) {
        fail('Environment response missing id', 'invalid_response', 1);
    }

    $environmentId = $environment['id'];
}

[$status, $payload, $raw] = request('POST', "/environments/{$environmentId}/deployments", $token);
if ($status === 0) {
    fail('Failed to initiate deployment (network error).', 'api_error', 1, $raw);
}
if ($status >= 300) {
    fail("Failed to initiate deployment (HTTP {$status}).", 'api_error', 1, $raw);
}
if ($payload === null) {
    fail('Failed to parse deployment response (invalid JSON).', 'invalid_response', 1, $raw);
}

$deployment = $payload['data'] ?? null;
if (!$deployment || !isset($deployment['id'])) {
    fail('Deployment response missing id', 'invalid_response', 1, $raw);
}

$deploymentId = $deployment['id'];
$deploymentStatus = $deployment['attributes']['status'] ?? 'unknown';
$deploymentLink = normalize_link($deployment['links']['self'] ?? null);

set_output('deployment_id', $deploymentId);
if ($deploymentLink) {
    set_output('deployment_url', $deploymentLink);
}

fwrite(STDOUT, "Deployment started: {$deploymentId} (status: {$deploymentStatus})\n");

if (!$shouldWait) {
    set_output('deployment_status', $deploymentStatus);
    set_output('success', 'false');
    append_summary("Deployment triggered: `{$deploymentId}`");
    exit(0);
}

$terminalSuccess = ['deployment.succeeded'];
$terminalFailure = ['build.failed', 'failed', 'deployment.failed', 'cancelled'];

$start = time();
while (true) {
    if (time() - $start > $timeoutSeconds) {
        fail('Deployment polling timed out', 'timeout', 1);
    }

    [$status, $payload, $raw] = request('GET', "/deployments/{$deploymentId}", $token);
    if ($status === 0) {
        fail('Failed to fetch deployment status (network error).', 'api_error', 1, $raw);
    }
    if ($status >= 300) {
        fail("Failed to fetch deployment status (HTTP {$status}).", 'api_error', 1, $raw);
    }
    if ($payload === null) {
        fail('Failed to parse deployment status response (invalid JSON).', 'invalid_response', 1, $raw);
    }

    $deployment = $payload['data'] ?? null;
    if (!$deployment) {
        fail('Deployment status response missing data', 'invalid_response', 1, $raw);
    }

    $deploymentStatus = $deployment['attributes']['status'] ?? 'unknown';
    fwrite(STDOUT, "Deployment status: {$deploymentStatus}\n");

    if (in_array($deploymentStatus, $terminalSuccess, true)) {
        set_output('deployment_status', $deploymentStatus);
        set_output('success', 'true');
        append_summary("Deployment succeeded: `{$deploymentId}`");
        exit(0);
    }

    if (in_array($deploymentStatus, $terminalFailure, true)) {
        $failureReason = $deployment['attributes']['failure_reason'] ?? null;
        set_output('deployment_status', $deploymentStatus);
        set_output('success', 'false');
        append_summary("Deployment failed: `{$deploymentId}`");
        if ($failureReason) {
            append_summary("Failure reason: {$failureReason}");
        }
        fwrite(STDERR, "Deployment failed: {$deploymentStatus}\n");
        if ($failureReason) {
            fwrite(STDERR, "Failure reason: {$failureReason}\n");
        }
        exit(1);
    }

    sleep($pollInterval);
}
