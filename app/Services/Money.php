<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** All persisted money is an integer number of billionths of a US dollar. */
class Money
{
    public static function parse(string $dollars): int
    {
        return BigDecimal::of($dollars)->multipliedBy(1_000_000_000)->toScale(0, RoundingMode::Unnecessary)->toInt();
    }

    public static function format(int $nanos): string
    {
        return (string) BigDecimal::of($nanos)->dividedBy(1_000_000_000, 9, RoundingMode::Unnecessary);
    }

    public static function cost(array $usage, array $rates): int
    {
        $cost = BigDecimal::of(0);
        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $type) {
            $cost = $cost->plus(BigDecimal::of($rates[$type])->multipliedBy($usage[$type])->multipliedBy(1000));
        }

        return $cost->toScale(0, RoundingMode::Unnecessary)->toInt();
    }

    /** Round the 25% markup up by at most one nanodollar per generation. */
    public static function charge(int $providerNanos): int
    {
        return intdiv($providerNanos * 5 + 3, 4);
    }

    public static function estimate(array $parameters, array $pricing): array
    {
        $tokens = $pricing['tokens'];
        $text = (int) ceil(mb_strlen($parameters['prompt']) / $tokens['characters_per_text_token']);
        $reasoning = $tokens['reasoning'][$parameters['quality']];
        $usage = [
            'input_text_tokens' => $text,
            'input_image_tokens' => count($parameters['reference_images'] ?? []) * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$parameters['quality']][$parameters['size']],
            'reasoning_tokens' => intdiv($reasoning['min'] + $reasoning['max'], 2),
        ];
        $estimated = self::charge(self::cost($usage, $pricing['rates']));
        $usage['input_text_tokens'] = BigDecimal::of((string) $tokens['text_token_variance'])->plus(1)
            ->multipliedBy($text)->toScale(0, RoundingMode::Ceiling)->toInt();
        $usage['reasoning_tokens'] = $reasoning['max'];

        return ['estimated_nanos' => $estimated, 'maximum_nanos' => self::charge(self::cost($usage, $pricing['rates']))];
    }
}
