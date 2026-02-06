<?php

declare(strict_types=1);

namespace App\Support;

class UrlHelper
{
    public static function normalizeLink(mixed $link): ?string
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

    public static function buildEnvironmentUrl(?string $vanityDomain, ?string $apiLink): ?string
    {
        if (is_string($vanityDomain) && $vanityDomain !== '') {
            if (str_starts_with($vanityDomain, 'http://') || str_starts_with($vanityDomain, 'https://')) {
                return $vanityDomain;
            }
            return 'https://' . $vanityDomain;
        }

        return $apiLink;
    }
}
