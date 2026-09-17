<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LoginIdentifierResolver
{
    /**
     * @var array<string, string>
     */
    private const DigitTranslations = [
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
    ];

    public function resolve(string $identifier): LoginIdentifierResolution
    {
        $identifier = trim($identifier);
        $phoneVariants = $this->phoneVariants($identifier);

        $candidates = User::withTrashed()
            ->where(function (Builder $query) use ($identifier, $phoneVariants): void {
                $query
                    ->whereRaw('LOWER(username) = ?', [Str::lower($identifier)])
                    ->orWhereRaw('LOWER(email) = ?', [Str::lower($identifier)]);

                if ($phoneVariants !== []) {
                    $placeholders = implode(', ', array_fill(0, count($phoneVariants), '?'));
                    $query->orWhereRaw($this->phoneDigitsExpression()." IN ({$placeholders})", $phoneVariants);
                }
            })
            ->limit(2)
            ->get();

        if ($candidates->count() !== 1) {
            return new LoginIdentifierResolution(
                user: null,
                ambiguous: $candidates->count() > 1,
            );
        }

        return new LoginIdentifierResolution($candidates->first());
    }

    /**
     * @return list<string>
     */
    private function phoneVariants(string $identifier): array
    {
        $translated = strtr($identifier, self::DigitTranslations);

        if (preg_match('/^[0-9+\s().\-]+$/u', $translated) !== 1) {
            return [];
        }

        $compact = preg_replace('/[\s().\-]+/u', '', $translated);

        if (! is_string($compact) || $compact === '') {
            return [];
        }

        if (str_starts_with($compact, '+')) {
            $compact = substr($compact, 1);
        }

        if (! ctype_digit($compact)) {
            return [];
        }

        $canonical = match (true) {
            preg_match('/^01[0-9]{9}$/D', $compact) === 1 => '20'.substr($compact, 1),
            preg_match('/^201[0-9]{9}$/D', $compact) === 1 => $compact,
            preg_match('/^00201[0-9]{9}$/D', $compact) === 1 => substr($compact, 2),
            default => null,
        };

        if (! is_string($canonical)) {
            return [];
        }

        return [
            $canonical,
            '0'.substr($canonical, 2),
            '00'.$canonical,
        ];
    }

    private function phoneDigitsExpression(): string
    {
        $column = DB::connection()->getQueryGrammar()->wrap('phone');
        $expression = "COALESCE({$column}, '')";
        $replacements = [
            ...self::DigitTranslations,
            ' ' => '',
            "\t" => '',
            "\n" => '',
            "\r" => '',
            "\u{00A0}" => '',
            '+' => '',
            '-' => '',
            '(' => '',
            ')' => '',
            '.' => '',
        ];

        foreach ($replacements as $from => $to) {
            $from = str_replace("'", "''", $from);
            $to = str_replace("'", "''", $to);
            $expression = "REPLACE({$expression}, '{$from}', '{$to}')";
        }

        return $expression;
    }
}
