<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Api;

/**
 * Shared call budget for api_basic.cgi, api_brochure.cgi and every media
 * download: Yachtfolio allows 800 calls per 5 minutes across all three and
 * sends no rate-limit headers, so the counter lives here.
 */
final class RateBudget
{
    private const OPTION = 'oy_yf_budget';

    public function __construct(
        private int $limit = 600,
        private int $window = 300
    ) {
    }

    /** @return array{window_start:int,count:int,blocked_until:int} */
    private function state(): array
    {
        $state = get_option(self::OPTION);
        if (!is_array($state) || !isset($state['window_start'], $state['count'])) {
            $state = ['window_start' => time(), 'count' => 0, 'blocked_until' => 0];
        }
        if (time() - (int) $state['window_start'] >= $this->window) {
            $state['window_start'] = time();
            $state['count'] = 0;
        }
        $state['blocked_until'] = (int) ($state['blocked_until'] ?? 0);
        return $state;
    }

    /** @param array{window_start:int,count:int,blocked_until:int} $state */
    private function save(array $state): void
    {
        update_option(self::OPTION, $state, false);
    }

    /**
     * @throws ApiError when the budget or a 429 penalty blocks the call.
     */
    public function consume(int $n = 1): void
    {
        $state = $this->state();

        if ($state['blocked_until'] > time()) {
            throw new ApiError(
                ApiError::RATELIMIT,
                sprintf('rate limited, blocked for %d more seconds', $state['blocked_until'] - time()),
                ['blocked_until' => $state['blocked_until']]
            );
        }

        if ($state['count'] + $n > $this->limit) {
            throw new ApiError(
                ApiError::BUDGET,
                sprintf('call budget exhausted (%d/%d in %ds window)', $state['count'], $this->limit, $this->window),
                ['retry_after' => $this->window - (time() - $state['window_start'])]
            );
        }

        $state['count'] += $n;
        $this->save($state);
    }

    public function used(): int
    {
        return (int) $this->state()['count'];
    }

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used());
    }

    public function window_resets_in(): int
    {
        $state = $this->state();
        return max(0, $this->window - (time() - (int) $state['window_start']));
    }

    public function blocked_for(): int
    {
        return max(0, (int) $this->state()['blocked_until'] - time());
    }

    /** Called after an HTTP 429: stop issuing calls for a while. */
    public function penalise(int $seconds): void
    {
        $state = $this->state();
        $state['blocked_until'] = time() + max(5, $seconds);
        $this->save($state);
    }

    public function reset(): void
    {
        delete_option(self::OPTION);
    }

    public function limit(): int
    {
        return $this->limit;
    }
}
