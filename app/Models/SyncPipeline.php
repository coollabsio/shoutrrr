<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Database\Factories\SyncPipelineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $source_connected_account_id
 * @property string $name
 * @property bool $enabled
 * @property string|null $created_by
 */
class SyncPipeline extends Model
{
    /** @use HasFactory<SyncPipelineFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    protected $fillable = [
        'workspace_id',
        'source_connected_account_id',
        'name',
        'enabled',
        'created_by',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'source_connected_account_id');
    }

    /**
     * @return BelongsToMany<ConnectedAccount, $this>
     */
    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(
            ConnectedAccount::class,
            'sync_pipeline_destinations',
            'sync_pipeline_id',
            'connected_account_id',
        )->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
