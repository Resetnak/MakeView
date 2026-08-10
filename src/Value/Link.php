<?php

declare(strict_types=1);

namespace Makeview\Value;

/** One link (or credential group) extracted from a README. */
final readonly class Link
{
    /**
     * @param string $context The README heading this was found under; shown in the
     *                        UI so a wrong proximity pairing stays traceable.
     * @param string $confidence One of: table, definition, env, proximity.
     * @param Credential[] $credentials
     */
    public function __construct(
        public string $label,
        public ?string $url,
        public string $context,
        public string $confidence,
        public array $credentials,
    ) {
    }

    /** @param Credential[] $credentials */
    public function withCredentials(array $credentials): self
    {
        return new self($this->label, $this->url, $this->context, $this->confidence, $credentials);
    }
}
