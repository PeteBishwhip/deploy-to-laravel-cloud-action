<?php

declare(strict_types=1);

namespace App\Support;

class CloudApi
{
    public const BASE_URL = 'https://cloud.laravel.com/api';

    /**
     * @return array{status:int,payload:array|null,raw:string}
     */
    public function request(string $method, string $path, string $token, ?array $body = null): array
    {
        $url = self::BASE_URL . $path;
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
            return ['status' => 0, 'payload' => null, 'raw' => $error];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $payload = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            }
        }

        return ['status' => $status, 'payload' => $payload, 'raw' => (string) $raw];
    }
}
