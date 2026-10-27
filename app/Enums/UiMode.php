<?php

declare(strict_types=1);

namespace App\Enums;

enum UiMode: string
{
    case Agentic = 'agentic';
    case Classic = 'classic';

    public function homeRoute(): string
    {
        return match ($this) {
            self::Agentic => 'agentic.home',
            self::Classic => 'dashboard',
        };
    }
}
