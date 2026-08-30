<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Api;

/**
 * Every Yachtfolio failure mode, classified.
 *
 * The API answers HTTP 200 for errors (constraint B3) and, for yachts the key
 * does not own, HTTP 200 with an empty data[] and an empty errors[] (B2 on the
 * live key). Both are failures here, never "success with no data".
 */
final class ApiError extends \RuntimeException
{
    public const AUTH          = 'auth';
    public const PERMISSION    = 'permission';
    public const PARAM         = 'param';
    public const EMPTY_PAYLOAD = 'empty_payload';
    public const SHAPE         = 'shape';
    public const TRANSPORT     = 'transport';
    public const RATELIMIT     = 'ratelimit';
    public const BUDGET        = 'budget';

    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly array $context = [],
        public readonly int $status = 0
    ) {
        parent::__construct($message);
    }

    /** @param array<int,string> $errors */
    public static function fromErrors(array $errors, int $status, array $context = []): self
    {
        $message = implode('; ', array_map('strval', $errors));
        $lower = strtolower($message);

        $kind = match (true) {
            str_contains($lower, 'passkey')                                  => self::AUTH,
            str_contains($lower, "don't have permissions"),
            str_contains($lower, 'permission')                               => self::PERMISSION,
            str_contains($lower, 'invalid value'),
            str_contains($lower, 'has not been provided')                    => self::PARAM,
            default                                                          => self::SHAPE,
        };

        return new self($kind, $message, $context, $status);
    }

    public function is(string $kind): bool
    {
        return $this->kind === $kind;
    }

    /** Retrying only helps for these. */
    public function retryable(): bool
    {
        return in_array($this->kind, [self::TRANSPORT, self::RATELIMIT, self::BUDGET], true);
    }
}
