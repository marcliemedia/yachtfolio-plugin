<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Api;

use Otium\Yachtfolio\Support\Logger;
use Otium\Yachtfolio\Support\Secrets;
use Otium\Yachtfolio\Support\Settings;

final class Client
{
    public const REFERENCE_TYPES = [
        'seasons',
        'equipments',
        'operating_areas_new',
        'special_requests',
        'licences_registrations',
        'licences_registrations_statuses',
    ];

    private int $calls = 0;
    private int $mediaCalls = 0;

    public function __construct(
        private Settings $settings,
        private RateBudget $budget,
        private Logger $log
    ) {
    }

    /* ------------------------------------------------------------------ *
     * Endpoints
     * ------------------------------------------------------------------ */

    /**
     * @return array<int|string,mixed> unwrapped rows (handles {"data":{"data":[…]}})
     * @throws ApiError
     */
    public function reference(string $type): array
    {
        if (!in_array($type, self::REFERENCE_TYPES, true)) {
            throw new ApiError(ApiError::PARAM, "unknown reference type: $type");
        }
        $json = $this->basic(['type' => $type]);
        return $this->unwrap($json);
    }

    /**
     * Yacht index: id, name, registry_port, last_modified.
     *
     * @return array<int,array<string,mixed>>
     * @throws ApiError
     */
    public function list(): array
    {
        $rows = $this->unwrap($this->basic(['type' => 'list']));
        if (!is_array($rows) || $rows === []) {
            throw new ApiError(ApiError::EMPTY_PAYLOAD, 'type=list returned no rows', ['type' => 'list']);
        }
        return array_values(array_filter($rows, static fn($r) => is_array($r) && isset($r['id'])));
    }

    /**
     * Bulk detail. The live key returns only the yachts it owns (5 for Otium),
     * with 89 fields each.
     *
     * @return array<int,array<string,mixed>> keyed by yacht id
     * @throws ApiError
     */
    public function bulk_yachts(): array
    {
        $rows = $this->unwrap($this->basic(['type' => 'yachts']));
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['id'])) {
                $out[(int) $row['id']] = $row;
            }
        }
        return $out;
    }

    /**
     * Single detail. Usable for owned yachts only; for anything else the API
     * answers {"data":[],"errors":[]} which is a failure, not an empty yacht.
     *
     * @return array<string,mixed>
     * @throws ApiError
     */
    public function single_yacht(int $yfId): array
    {
        $rows = $this->unwrap($this->basic(['type' => 'yachts', 'id_yacht' => $yfId]));
        $row = is_array($rows) ? reset($rows) : false;
        if (!is_array($row) || $row === []) {
            throw new ApiError(
                ApiError::EMPTY_PAYLOAD,
                sprintf('type=yachts&id_yacht=%d returned an empty data[] with an empty errors[]', $yfId),
                ['yf_id' => $yfId]
            );
        }
        return $row;
    }

    /**
     * Brochure. Unlike api_basic.cgi the payload has no {data,errors} envelope.
     * scenario is a no-op on this key (auto/manual/text are byte-identical).
     *
     * @return array<string,mixed>
     * @throws ApiError
     */
    public function brochure(int $yfId, ?string $scenario = null): array
    {
        $scenario = $scenario ?: (string) $this->settings->get('brochure_scenario', 'auto');
        $json = $this->request(
            (string) $this->settings->get('endpoint_brochure'),
            ['id_yacht' => $yfId, 'scenario' => $scenario],
            'brochure',
            ['yf_id' => $yfId]
        );

        $body = $json;
        if (!isset($body['specifications']) && !isset($body['galleries']) && !isset($body['general'])) {
            $unwrapped = $this->unwrap($json);
            $body = is_array($unwrapped) ? $unwrapped : [];
        }

        if (!is_array($body) || (!isset($body['specifications']) && !isset($body['galleries']))) {
            throw new ApiError(
                ApiError::EMPTY_PAYLOAD,
                sprintf('brochure for yacht %d carried neither specifications nor galleries', $yfId),
                ['yf_id' => $yfId, 'keys' => is_array($body) ? array_keys($body) : []]
            );
        }

        return $body;
    }

    /**
     * Raw media bytes. The filename extension lies (WebP is served as .jpg), so
     * callers must sniff the real format before writing anything.
     *
     * @throws ApiError
     */
    public function media_bytes(string $filename): string
    {
        $this->budget->consume();
        $this->mediaCalls++;

        $url = add_query_arg(
            ['f' => $filename, 'api' => $this->passkey()],
            (string) $this->settings->get('endpoint_media')
        );

        $response = wp_remote_get($url, $this->http_args(max(60, $this->settings->int('request_timeout', 45))));

        if (is_wp_error($response)) {
            throw new ApiError(ApiError::TRANSPORT, $response->get_error_message(), ['file' => $filename]);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 429) {
            $this->budget->penalise($this->retry_after($response, 60));
            throw new ApiError(ApiError::RATELIMIT, 'media download rate limited', ['file' => $filename], 429);
        }
        if ($status !== 200) {
            throw new ApiError(ApiError::TRANSPORT, "media download returned HTTP $status", ['file' => $filename], $status);
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            throw new ApiError(ApiError::EMPTY_PAYLOAD, 'media download returned an empty body', ['file' => $filename]);
        }

        return $body;
    }

    /**
     * Connection check used by the Tools screen and `wp otium-yf check`.
     *
     * @return array<int,array{probe:string,ok:bool,detail:string}>
     */
    public function ping(): array
    {
        $out = [];

        foreach (self::REFERENCE_TYPES as $type) {
            $out[] = $this->probe("reference/$type", function () use ($type): string {
                $rows = $this->reference($type);
                if (isset($rows['groups']) || isset($rows['areas'])) {
                    return sprintf(
                        'groups=%d areas=%d',
                        is_array($rows['groups'] ?? null) ? count($rows['groups']) : 0,
                        is_array($rows['areas'] ?? null) ? count($rows['areas']) : 0
                    );
                }
                return 'rows=' . count($rows);
            });
        }

        $out[] = $this->probe('list', function (): string {
            $rows = $this->list();
            $stamps = array_unique(array_map(static fn($r) => (string) ($r['last_modified'] ?? ''), $rows));
            return sprintf('yachts=%d distinct_last_modified=%d', count($rows), count($stamps));
        });

        $out[] = $this->probe('yachts (bulk, owned only)', function (): string {
            $rows = $this->bulk_yachts();
            $fields = $rows === [] ? 0 : count((array) reset($rows));
            return sprintf('yachts=%d fields=%d ids=%s', count($rows), $fields, implode(',', array_keys($rows)));
        });

        return $out;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function media_calls(): int
    {
        return $this->mediaCalls;
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     * @throws ApiError
     */
    private function basic(array $query): array
    {
        return $this->request((string) $this->settings->get('endpoint_basic'), $query, 'basic', $query);
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     * @throws ApiError
     */
    private function request(string $endpoint, array $query, string $stage, array $context = []): array
    {
        $passkey = $this->passkey();
        if ($passkey === '') {
            throw new ApiError(ApiError::AUTH, 'no passkey configured for the active mode', ['mode' => $this->settings->mode()]);
        }

        $this->budget->consume();
        $this->calls++;

        $url = add_query_arg(array_merge($query, ['passkey' => $passkey]), $endpoint);
        $started = microtime(true);
        $response = wp_remote_get($url, $this->http_args($this->settings->int('request_timeout', 45)));
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($response)) {
            throw new ApiError(ApiError::TRANSPORT, $response->get_error_message(), $context);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 429) {
            $this->budget->penalise($this->retry_after($response, 60));
            throw new ApiError(ApiError::RATELIMIT, 'rate limited by Yachtfolio', $context, 429);
        }

        $body = (string) wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if (!is_array($json)) {
            throw new ApiError(
                ApiError::SHAPE,
                sprintf('HTTP %d with a non-JSON body (%d bytes)', $status, strlen($body)),
                $context + ['excerpt' => (string) Secrets::scrub(substr($body, 0, 200))],
                $status
            );
        }

        // B3: the transport says 200, the body decides.
        $errors = $json['errors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            throw ApiError::fromErrors($errors, $status, $context);
        }

        $this->log->debug($stage, 'api call ok', $context + ['ms' => $elapsed, 'bytes' => strlen($body)]);

        return $json;
    }

    /**
     * Handles both the plain {"data":[…]} and the double-wrapped
     * {"data":{"data":[…]}} shape used by the reference tables.
     *
     * @param array<string,mixed> $json
     * @return array<int|string,mixed>
     */
    private function unwrap(array $json): array
    {
        $data = $json['data'] ?? null;
        if (is_array($data) && array_key_exists('data', $data) && is_array($data['data'])) {
            return $data['data'];
        }
        return is_array($data) ? $data : [];
    }

    /** @return array<string,mixed> */
    private function http_args(int $timeout): array
    {
        return [
            'timeout'     => $timeout,
            'redirection' => 3,
            'user-agent'  => 'otium-yachtfolio-sync/' . (defined('OY_YF_VERSION') ? OY_YF_VERSION : '0') . '; ' . home_url('/'),
            'headers'     => ['Accept' => 'application/json'],
            'sslverify'   => true,
        ];
    }

    private function retry_after(mixed $response, int $fallback): int
    {
        $header = wp_remote_retrieve_header($response, 'retry-after');
        if (is_array($header)) {
            $header = reset($header);
        }
        $seconds = (int) $header;
        return $seconds > 0 ? $seconds : $fallback;
    }

    private function passkey(): string
    {
        return $this->settings->passkey();
    }

    /**
     * @param callable():string $probe
     * @return array{probe:string,ok:bool,detail:string}
     */
    private function probe(string $name, callable $probe): array
    {
        try {
            return ['probe' => $name, 'ok' => true, 'detail' => $probe()];
        } catch (ApiError $e) {
            return ['probe' => $name, 'ok' => false, 'detail' => $e->kind . ': ' . Secrets::scrub($e->getMessage())];
        }
    }
}
