<?php

namespace App\Support\Ui;

/**
 * Accent color of the organization (TZ 04 Э26 «Бренд»; architecture 1а). Blue is the default, graphite the
 * second approved variant; four more contrast-checked variants (Допущение Э26: «+ 4 варианта»).
 *
 * The accent is applied twice: our tokens (--primary, --ring, …; resources/css/tokens.css) and the Filament
 * primary palette (--primary-50…950). Both are rendered into <head> by the shell and the class
 * `accent-<value>` is put on <html>.
 */
enum Accent: string
{
    case Blue = 'blue';
    case Graphite = 'graphite';
    case Navy = 'navy';
    case Teal = 'teal';
    case Violet = 'violet';
    case Plum = 'plum';

    public function label(): string
    {
        return match ($this) {
            self::Blue => __('Синий'),
            self::Graphite => __('Графит'),
            self::Navy => __('Тёмно-синий'),
            self::Teal => __('Морская волна'),
            self::Violet => __('Фиолетовый'),
            self::Plum => __('Сливовый'),
        };
    }

    /** Main accent (button background) in light mode. */
    public function hex(): string
    {
        return match ($this) {
            self::Blue => '#2A4BD7',
            self::Graphite => '#18181B',
            self::Navy => '#1E3A8A',
            self::Teal => '#0B6E79',
            self::Violet => '#5B3FD9',
            self::Plum => '#8A2C6F',
        };
    }

    /** Accent in dark mode (lighter; graphite becomes near-white with dark text, as on the dark mockup). */
    public function darkHex(): string
    {
        return match ($this) {
            self::Blue => '#6E8AFF',
            self::Graphite => '#F2F2F3',
            self::Navy => '#7C9CF0',
            self::Teal => '#4FB6C1',
            self::Violet => '#9A86F5',
            self::Plum => '#D178B4',
        };
    }

    public function darkForeground(): string
    {
        return $this === self::Graphite ? '#111113' : '#FFFFFF';
    }

    public static function fromSetting(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Blue;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }

    /** "42 75 215" — RGB channels for rgb(var(--x) / a) tokens. */
    public static function channels(string $hex): string
    {
        return implode(' ', self::rgb($hex));
    }

    /**
     * Filament palette (50…950) with the accent at 600 (Filament buttons use 600, hover 500).
     *
     * @return array<int, string> shade => "r, g, b"
     */
    public function filamentPalette(): array
    {
        if ($this === self::Graphite) {
            return [
                50 => '250, 250, 250', 100 => '244, 244, 245', 200 => '228, 228, 231', 300 => '212, 212, 216',
                400 => '161, 161, 170', 500 => '82, 82, 91', 600 => '24, 24, 27', 700 => '17, 17, 19',
                800 => '12, 12, 14', 900 => '9, 9, 11', 950 => '5, 5, 6',
            ];
        }
        $base = self::rgb($this->hex());
        $mix = fn (array $to, float $t) => implode(', ', array_map(
            fn ($c, $o) => (int) round($c + ($o - $c) * $t), $base, $to));
        $white = [255, 255, 255];
        $black = [0, 0, 0];

        return [
            50 => $mix($white, 0.94), 100 => $mix($white, 0.88), 200 => $mix($white, 0.75), 300 => $mix($white, 0.56),
            400 => $mix($white, 0.32), 500 => $mix($white, 0.14), 600 => $mix($white, 0.0), 700 => $mix($black, 0.14),
            800 => $mix($black, 0.28), 900 => $mix($black, 0.42), 950 => $mix($black, 0.58),
        ];
    }

    /** Inline CSS (tokens + Filament palette) for <head>. `html.accent-x` beats Filament's own `:root` palette. */
    public function css(): string
    {
        $vars = '';
        foreach ($this->filamentPalette() as $shade => $rgb) {
            $vars .= "--primary-{$shade}:{$rgb};";
        }
        $light = self::channels($this->hex());
        $dark = self::channels($this->darkHex());
        $darkFg = self::channels($this->darkForeground());
        $class = 'accent-'.$this->value;

        return "html.{$class}{{$vars}--primary:{$light};--ring:{$light};--store-a:{$light};--primary-foreground:255 255 255}"
            ."html.dark.{$class}{--primary:{$dark};--ring:{$dark};--store-a:{$dark};--primary-foreground:{$darkFg}}";
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
