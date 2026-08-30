<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Write;

use Otium\Yachtfolio\Support\Secrets;

final class FieldDiff
{
    public function __construct(
        public string $key,
        public mixed $old,
        public mixed $new,
        public string $owner,
        public bool $changed,
        public bool $skipped = false,
        public string $reason = ''
    ) {
    }

    public function is_noop(): bool
    {
        return !$this->changed && !$this->skipped;
    }

    /** @return array{key:string,owner:string,old:string,new:string,changed:bool,skipped:bool,reason:string} */
    public function as_row(): array
    {
        return [
            'key'     => $this->key,
            'owner'   => $this->owner,
            'old'     => self::stringify($this->old),
            'new'     => self::stringify($this->new),
            'changed' => $this->changed,
            'skipped' => $this->skipped,
            'reason'  => $this->reason,
        ];
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_array($value)) {
            $value = (string) wp_json_encode($value);
        } else {
            $value = (string) $value;
        }

        $value = (string) Secrets::scrub($value);
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > 200 ? mb_substr($value, 0, 197) . '…' : $value;
    }
}
