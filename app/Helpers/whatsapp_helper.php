<?php

if (! function_exists('wa_to_html')) {
    /**
     * Renders WhatsApp formatting as safe HTML for the demo page:
     * *bold*, _italic_, ~strike~ and line breaks. Everything else is escaped.
     */
    function wa_to_html(string $text): string
    {
        $html = esc($text);
        $html = preg_replace('/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/u', '<strong>$1</strong>', $html);
        $html = preg_replace('/(?<![\w_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w_])/u', '<em>$1</em>', $html);
        $html = preg_replace('/(?<![\w~])~(?!\s)([^~\n]+?)(?<!\s)~(?![\w~])/u', '<del>$1</del>', $html);

        return nl2br($html, false);
    }
}
