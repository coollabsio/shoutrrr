<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Database\Factories\ConversationFactory;
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
 * @property string $connected_account_id
 * @property Platform $platform
 * @property string $remote_conversation_id
 * @property string|null $counterpart_handle
 * @property string|null $counterpart_name
 * @property string|null $counterpart_avatar_url
 * @property string|null $counterpart_remote_id
 * @property CarbonImmutable|null $last_message_at
 * @property string|null $last_message_preview
 * @property int $unread_count
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $messaging_window_expires_at
 * @property string|null $sync_cursor
 * @property CarbonImmutable|null $last_synced_at
 */
#[Fillable([
    'workspace_id', 'connected_account_id', 'platform', 'remote_conversation_id',
    'counterpart_handle', 'counterpart_name', 'counterpart_avatar_url', 'counterpart_remote_id',
    'last_message_at', 'last_message_preview', 'unread_count', 'read_at', 'archived_at',
    'messaging_window_expires_at', 'sync_cursor', 'last_synced_at',
])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasUuids;

    use HasWorkspaceScope;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'unread_count' => 'integer',
            'last_message_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'messaging_window_expires_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * Whether we're still inside the platform's messaging window and may send
     * a reply. Meta (IG/FB) enforces a 24h window from the counterpart's last
     * inbound message; platforms without a window leave this null.
     */
    public function canReplyNow(): bool
    {
        return $this->messaging_window_expires_at === null
            || $this->messaging_window_expires_at->isFuture();
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
    }

    /**
     * @return HasMany<DirectMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(DirectMessage::class);
    }
}
