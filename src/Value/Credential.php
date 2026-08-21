<?php

declare(strict_types=1);

namespace Makeview\Value;

use Makeview\CredentialKeys;
use Makeview\Readme\Scorer;

/** One credential extracted from a compose file or a README. */
final readonly class Credential
{
    /**
     * @param string $kind One of: user, password, token.
     * @param string $label Human-facing name, e.g. POSTGRES_PASSWORD or "heslo".
     * @param string $value The credential value.
     * @param bool $isPlaceholder True when the value is a substitution marker
     *                              or an instruction to the reader, not a secret.
     * @param float $score 0.0-1.0 detection confidence. Defaults to 1.0 for parsers that
     *                       state the credential outright — a compose env key, a credential
     *                       table — which need not compute one.
     * @param string $evidence Why this was reported, shown in the UI on hover.
     */
    public function __construct(
        public string $kind,
        public string $label,
        public string $value,
        public bool $isPlaceholder,
        public float $score = 1.0,
        public string $evidence = '',
    ) {
    }

    /** True when the finding is a weak guess below the threshold worth checking. */
    public function isUncertain(): bool
    {
        return $this->score < Scorer::THRESHOLD_LIKELY;
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
