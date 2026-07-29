<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use App\Enums\MessageDirection;
use App\Enums\SendStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DirectMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $conversation_id
 * @property string $remote_message_id
 * @property MessageDirection $direction
 * @property string|null $author_remote_id
 * @property string|null $text
 * @property array<string, mixed>|null $attachments
 * @property CarbonImmutable|null $remote_created_at
 * @property bool $is_ours
 * @property SendStatus|null $send_status
 * @property string|null $our_remote_id
 */
#[Fillable([
    'workspace_id', 'conversation_id', 'remote_message_id', 'direction', 'author_remote_id',
    'text', 'attachments', 'remote_created_at', 'is_ours', 'send_status', 'our_remote_id',
])]
class DirectMessage extends Model
{
    /** @use HasFactory<DirectMessageFactory> */
    use HasFactory, HasUuids;

    use HasWorkspaceScope;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'attachments' => 'array',
            'remote_created_at' => 'immutable_datetime',
            'is_ours' => 'boolean',
            'send_status' => SendStatus::class,
        ];
    }

    /**
     * Attachments we delivered with this message. Empty for inbound messages —
     * the fetch connectors do not parse the platform's own attachments yet.
     *
     * @return HasMany<PostMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
