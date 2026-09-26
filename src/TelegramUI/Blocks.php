<?php
declare(strict_types=1);
namespace App\TelegramUI;

/**
 * Small builders for Telegram Bot API Rich Messages.
 * These create presentation payloads only; they intentionally know nothing
 * about users, subscriptions, or payment services.
 */
final class Blocks
{
    public static function heading(string $text, int $size = 2): array
    {
        return ['type' => 'heading', 'text' => $text, 'size' => max(1, min(6, $size))];
    }

    public static function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'text' => $text];
    }

    public static function divider(): array
    {
        return ['type' => 'divider'];
    }

    public static function footer(string $text): array
    {
        return ['type' => 'footer', 'text' => $text];
    }

    /** @param list<list<string|array>> $rows */
    public static function table(array $rows, bool $bordered = true, bool $striped = false, bool $compact = true, ?string $caption = null, bool $hasHeader = true): array
    {
        $cells = [];
        foreach ($rows as $rowIndex => $row) {
            $cells[] = array_map(static function (string|array $value) use ($rowIndex, $hasHeader): array {
                if (is_array($value)) return $value;
                $cell = ['text' => $value];
                if ($hasHeader && $rowIndex === 0) $cell['is_header'] = true;
                return $cell;
            }, $row);
        }
        $table = [
            'type' => 'table',
            'cells' => $cells,
            'is_bordered' => $bordered,
            'is_compact' => $compact,
        ];
        if ($striped) $table['is_striped'] = true;
        if ($caption !== null) $table['caption'] = $caption;
        return $table;
    }

    /** @param list<array> $blocks */
    public static function details(string $summary, array $blocks): array
    {
        return ['type' => 'details', 'summary' => $summary, 'blocks' => $blocks];
    }

    /** @param list<array{blocks:list<array>,value?:int|string}> $items */
    public static function list(array $items, bool $ordered = true): array
    {
        return ['type' => 'list', 'items' => $items, 'is_ordered' => $ordered];
    }

    public static function expandableQuote(string $text, ?string $credit = null): array
    {
        $quote = ['type' => 'expandable_blockquote', 'text' => $text];
        if ($credit !== null) $quote['credit'] = $credit;
        return $quote;
    }

    public static function button(string $text, string $callback, ?string $style = null): array
    {
        $button = ['text' => $text, 'callback_data' => $callback];
        if ($style !== null) $button['style'] = $style;
        return $button;
    }

    public static function urlButton(string $text, string $url, ?string $style = null): array
    {
        $button = ['text' => $text, 'url' => $url];
        if ($style !== null) $button['style'] = $style;
        return $button;
    }

    public static function copyButton(string $text, string $value): array
    {
        return ['text' => $text, 'copy_text' => ['text' => $value]];
    }

    /** @param list<array> $buttons */
    public static function buttons(array $buttons, string $align = 'center'): array
    {
        return ['type' => 'buttons', 'buttons' => $buttons, 'align' => $align];
    }
}
