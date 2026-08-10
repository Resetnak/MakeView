<?php

declare(strict_types=1);

namespace Makeview\Value;

use Makeview\CredentialKeys;

/** One credential extracted from a compose file or a README. */
final readonly class Credential
{
    /**
     * @param string $kind One of: user, password, token.
     * @param string $label Human-facing name, e.g. POSTGRES_PASSWORD or "heslo".
     * @param string $value The credential value.
     * @param bool $isPlaceholder True when the value is a substitution marker
     *                              or an instruction to the reader, not a secret.
     */
    public function __construct(
        public string $kind,
        public string $label,
        public string $value,
        public bool $isPlaceholder,
    ) {
    }

    /** Build from an environment-variable-style key, deriving kind from the key. */
    public static function fromKey(string $key, string $value): self
    {
        return new self(
            CredentialKeys::kindFor($key),
            $key,
            $value,
            CredentialKeys::isPlaceholder($value),
        );
    }
}
