<?php

namespace App\Services;

class ComposerJsonRenderer
{
    public function render(array $composer): string
    {
        return json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
