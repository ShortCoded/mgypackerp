<?php

namespace Modules\Core\Services;

use Illuminate\Support\Carbon;
use Throwable;

class DateFormatService
{
    public function __construct(
        private readonly SettingService $settings,
    ) {}

    public function dateFormat(): string
    {
        return $this->settings->dateFormat();
    }

    public function dateTimeFormat(): string
    {
        return $this->settings->dateTimeFormat();
    }

    public function jsDateFormat(): string
    {
        return $this->mapPhpDateFormatToFlatpickr($this->dateFormat());
    }

    public function jsDateTimeFormat(): string
    {
        return $this->mapPhpDateFormatToFlatpickr($this->dateTimeFormat());
    }

    public function formatDate(mixed $date, ?string $fallback = null): string
    {
        return $this->settings->formatDate($date, $fallback);
    }

    public function formatDateTime(mixed $date, ?string $fallback = null): string
    {
        return $this->settings->formatDateTime($date, $fallback);
    }

    public function parseDate(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach ($this->dateFormatsForParsing() as $format) {
            $date = $this->parseStrict($value, $format);

            if ($date instanceof Carbon) {
                return $date;
            }
        }

        return null;
    }

    public function parseDateTime(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach ($this->dateTimeFormatsForParsing() as $format) {
            $date = $this->parseStrict($value, $format);

            if ($date instanceof Carbon) {
                return $date;
            }
        }

        return null;
    }

    public function isValidDate(?string $value): bool
    {
        $value = trim((string) $value);

        return $value === '' || $this->parseDate($value) instanceof Carbon;
    }

    public function isValidDateTime(?string $value): bool
    {
        $value = trim((string) $value);

        return $value === '' || $this->parseDateTime($value) instanceof Carbon;
    }

    public function normalizeForStorage(?string $value): ?string
    {
        return $this->parseDate($value)?->toDateString();
    }

    public function normalizeDateTimeForStorage(?string $value): ?string
    {
        return $this->parseDateTime($value)?->toDateTimeString();
    }

    private function parseStrict(string $value, string $format): ?Carbon
    {
        $timezone = str_contains($format, '\Z') ? 'UTC' : config('app.timezone');

        try {
            $date = Carbon::createFromFormat('!'.$format, $value, $timezone);
        } catch (Throwable) {
            return null;
        }

        $errors = Carbon::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        if (! $date instanceof Carbon || $date->format($format) !== $value) {
            return null;
        }

        if (str_contains($format, '\Z')) {
            $date = $date->setTimezone(config('app.timezone'));
        }

        return strpbrk($format, 'HhGgisAa') === false
            ? $date->startOfDay()
            : $date;
    }

    /**
     * @return list<string>
     */
    private function dateFormatsForParsing(): array
    {
        return array_values(array_unique([
            $this->dateFormat(),
            'Y-m-d',
        ]));
    }

    /**
     * @return list<string>
     */
    private function dateTimeFormatsForParsing(): array
    {
        $dateFormat = $this->dateFormat();

        return $this->expandFlexibleTimeFormats([
            $this->dateTimeFormat(),
            $dateFormat.' h:i A',
            $dateFormat.' h:i a',
            $dateFormat.' h:i:s A',
            $dateFormat.' h:i:s a',
            $dateFormat.' H:i',
            $dateFormat.' H:i:s',
            $dateFormat,
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d\TH:i:s.uP',
            'Y-m-d\TH:i:s.vP',
            'Y-m-d\TH:i:s.u\Z',
            'Y-m-d\TH:i:s.v\Z',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s\Z',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
            'Y-m-d',
        ]);
    }

    /**
     * @param  list<string>  $formats
     * @return list<string>
     */
    private function expandFlexibleTimeFormats(array $formats): array
    {
        $expanded = [];

        foreach ($formats as $format) {
            foreach ($this->flexibleTimeVariants($format) as $variant) {
                $expanded[] = $variant;
            }
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @return list<string>
     */
    private function flexibleTimeVariants(string $format): array
    {
        $variants = [$format];
        $replacements = [
            'h' => 'g',
            'H' => 'G',
            'A' => 'a',
            'a' => 'A',
        ];

        foreach ($replacements as $from => $to) {
            foreach ($variants as $variant) {
                if (str_contains($variant, $from)) {
                    $variants[] = str_replace($from, $to, $variant);
                }
            }

            $variants = array_values(array_unique($variants));
        }

        return array_values(array_unique($variants));
    }

    private function mapPhpDateFormatToFlatpickr(string $format): string
    {
        $map = [
            'd' => 'd',
            'D' => 'D',
            'j' => 'j',
            'l' => 'l',
            'm' => 'm',
            'n' => 'n',
            'M' => 'M',
            'F' => 'F',
            'y' => 'y',
            'Y' => 'Y',
            'H' => 'H',
            'G' => 'H',
            'h' => 'h',
            'g' => 'h',
            'i' => 'i',
            's' => 'S',
            'A' => 'K',
            'a' => 'K',
        ];

        $result = '';
        $escaped = false;

        foreach (str_split($format) as $character) {
            if ($escaped) {
                $result .= '\\'.$character;
                $escaped = false;

                continue;
            }

            if ($character === '\\') {
                $escaped = true;

                continue;
            }

            $result .= $map[$character] ?? $character;
        }

        return $result;
    }
}
