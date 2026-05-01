<?php

namespace App\Services;

class ComposerJsonRenderer
{
    public function render(array $composer): string
    {
        $json = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // PHP's JSON_PRETTY_PRINT is fixed at 4 spaces; convert to 2-space indent.
        // Match runs of 4 leading spaces only at line starts so quoted string
        // values containing four spaces are untouched.
        $json = preg_replace_callback(
            '/^(?: {4})+/m',
            fn ($m) => str_repeat('  ', strlen($m[0]) / 4),
            $json,
        );
        return $json."\n";
    }
}
