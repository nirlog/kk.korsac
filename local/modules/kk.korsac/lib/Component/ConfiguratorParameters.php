<?php

declare(strict_types=1);

namespace KK\Korsac\Component;

final class ConfiguratorParameters
{
    public const DEFAULT_DEBOUNCE_MS = 150;
    public const MAX_DEBOUNCE_MS = 2000;

    /**
     * @param array<string, mixed> $parameters
     * @param null|callable(): string $randomId
     * @return array{valid: bool, iblockId: int, productId: int, debounceMs: int, domId: string}
     */
    public static function normalize(array $parameters, ?callable $randomId = null): array
    {
        $iblockId = self::positiveInteger($parameters['IBLOCK_ID'] ?? null);
        $productId = self::positiveInteger($parameters['PRODUCT_ID'] ?? null);

        return [
            'valid' => $iblockId !== null && $productId !== null,
            'iblockId' => $iblockId ?? 0,
            'productId' => $productId ?? 0,
            'debounceMs' => self::debounce($parameters['DEBOUNCE_MS'] ?? null),
            'domId' => self::domId($parameters['DOM_ID'] ?? null, $randomId),
        ];
    }

    private static function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($number) ? $number : null;
    }

    private static function debounce(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_DEBOUNCE_MS;
        }
        if (is_int($value)) {
            return max(0, min(self::MAX_DEBOUNCE_MS, $value));
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            $number = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($number)) {
                return max(0, min(self::MAX_DEBOUNCE_MS, $number));
            }
        }

        return self::DEFAULT_DEBOUNCE_MS;
    }

    private static function domId(mixed $value, ?callable $randomId): string
    {
        if (is_string($value) && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,127}$/D', $value) === 1) {
            return $value;
        }

        $suffix = $randomId !== null ? (string)$randomId() : bin2hex(random_bytes(8));
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $suffix) !== 1) {
            throw new \UnexpectedValueException('Generated configurator DOM ID suffix is invalid.');
        }

        return 'kk-korsac-configurator-' . $suffix;
    }
}
