<?php
namespace Gitcd\Helpers;

use Gitcd\Helpers\AnsiColors as C;

class DashboardFormatter
{
    /**
     * Write a raw line to stdout.
     */
    public static function w(string $line): void
    {
        echo $line . PHP_EOL;
    }

    /**
     * Write a section header line.
     */
    public static function sec(string $label, int $pw): void
    {
        $lineR = max(4, $pw - strlen($label) - 8);
        self::w(
            C::B . "│" . C::X . " "
            . C::GD . "──── " . C::X
            . C::G . C::BD . $label . C::X
            . " " . C::GD . str_repeat('─', $lineR) . C::X
        );
    }

    /**
     * Write an indented content line inside a box.
     */
    public static function ln(string $content): void
    {
        self::w(C::B . "│" . C::X . "  {$content}");
    }

    /**
     * Render a horizontal progress bar.
     */
    public static function bar(float $pct, int $w): string
    {
        $filled = (int)round($pct / 100 * $w);
        return self::clr($pct) . str_repeat('━', $filled) . C::B . str_repeat('━', $w - $filled) . C::X;
    }

    /**
     * Return the ANSI color code for a percentage threshold.
     */
    public static function clr(float $pct): string
    {
        if ($pct >= 90) return C::R;
        if ($pct >= 75) return C::Y;
        return C::G;
    }

    /**
     * Truncate a path/string intelligently, preserving tail segments.
     */
    public static function trunc(string $path, int $max): string
    {
        if ($max < 10) $max = 10;
        if (mb_strwidth($path) <= $max) return $path;
        $parts = explode('/', $path);
        if (count($parts) > 2) {
            $tail = implode('/', array_slice($parts, -2));
            if (mb_strwidth($tail) + 2 <= $max) return '…/' . $tail;
        }
        return '…' . mb_substr($path, -(($max) - 1));
    }

    /**
     * Format bytes into a human-readable size string.
     */
    public static function humanSize(int $bytes): string
    {
        $u = ['B', 'K', 'M', 'G', 'T'];
        $i = 0;
        $s = (float)$bytes;
        while ($s >= 1024 && $i < 4) { $s /= 1024; $i++; }
        return sprintf('%.1f%s', $s, $u[$i]);
    }

    /**
     * Pad a string (with ANSI codes) to a visible width.
     */
    public static function pad(string $str, int $w): string
    {
        $need = max(0, $w - self::visLen($str));
        return $str . str_repeat(' ', $need);
    }

    /**
     * Get the visible length of a string (stripping ANSI codes).
     */
    public static function visLen(string $str): int
    {
        $clean = preg_replace('/\033\[[^m]*m/', '', $str);
        return mb_strwidth($clean);
    }
}
