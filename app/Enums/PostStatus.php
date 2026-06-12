<?php

declare(strict_types=1);

namespace App\Enums;

enum PostStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Partial = 'partial';
    case Failed = 'failed';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Publishing => 'Publishing',
            self::Published => 'Published',
            self::Partial => 'Partially published',
            self::Failed => 'Failed',
            self::Deleted => 'Deleted',
        };
    }

    /**
     * Only drafts (and scheduled posts, in M3) may be edited in the composer.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Scheduled;
    }
}
