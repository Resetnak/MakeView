<?php

declare(strict_types=1);

namespace Makeview\Value;

/** One service from a compose file. */
final readonly class Service
{
    /**
     * @param ?string $url Absolute http(s) URL, or null for a service with no
     *                     browser-reachable address (a database, for instance).
     * @param ?string $urlSource Where the URL came from: traefik or port. Null when $url is null.
     * @param Credential[] $credentials
     */
    public function __construct(
        public string $name,
        public ?string $url,
        public ?string $urlSource,
        public array $credentials,
    ) {
    }
}
