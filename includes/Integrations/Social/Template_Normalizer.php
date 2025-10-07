<?php

namespace DNE\Integrations\Social;

/**
 * Utility helpers for normalising rich templates into plain text suitable for
 * platforms that do not support HTML markup (e.g. X, Facebook).
 */
class Template_Normalizer
{
    /**
     * Convert a template string that may contain simple HTML into readable
     * plain text. Anchors become two-line entries (label then URL), structural
     * tags become newlines, and inline formatting tags are stripped.
     */
    public static function to_plain_text(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $text = str_replace([
            "\r\n",
            "\r",
        ], "\n", $text);

        // Handle empty bold/strong placeholders as deliberate spacing.
        $text = str_replace([
            '<b></b>',
            '<strong></strong>',
        ], "\n", $text);

        // Replace common line-break style tags with real newlines.
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $text);
        $text = preg_replace('/<\/(p|div|h[1-6])\s*>/i', "\n", $text);
        $text = preg_replace('/<\s*(p|div|h[1-6])[^>]*>/i', '', $text);

        // Expand anchors to label + URL, keeping them on separate lines for clarity.
        $text = preg_replace_callback(
            '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            function ($matches) {
                $label = trim(self::to_plain_text($matches[2]));
                $url   = trim($matches[1]);
                if ($label === '') {
                    return $url;
                }
                if (stripos($label, $url) !== false) {
                    return $label;
                }
                return $label . "\n" . $url;
            },
            $text
        );

        // Strip remaining inline formatting tags.
        $text = preg_replace('/<\s*\/?(b|strong|i|em|u)[^>]*>/i', '', $text);

        // Remove any lingering markup.
        $text = strip_tags($text);

        // Decode entities and tidy whitespace/newlines.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\t ]+/', ' ', $text);
        $text = preg_replace('/ ?\n ?/', "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
