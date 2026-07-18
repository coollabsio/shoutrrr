<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use App\Enums\LegalPageType;
use Carbon\CarbonImmutable;
use Database\Factories\LegalPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * A workspace's public legal presence: one owner-chosen slug plus the sanitized
 * HTML body and publish state for each {@see LegalPageType} document.
 *
 * Reads through the authenticated app are constrained to the current workspace
 * by {@see HasWorkspaceScope}. The public routes intentionally bypass that scope
 * (there is no workspace context for a guest) and resolve strictly by slug.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $slug
 * @property string|null $terms_body
 * @property CarbonImmutable|null $terms_published_at
 * @property string|null $privacy_body
 * @property CarbonImmutable|null $privacy_published_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'workspace_id',
    'slug',
    'terms_body',
    'terms_published_at',
    'privacy_body',
    'privacy_published_at',
])]
class LegalPage extends Model
{
    /** @use HasFactory<LegalPageFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The sanitized HTML body for the given document, if any.
     */
    public function bodyFor(LegalPageType $type): ?string
    {
        return $this->getAttribute($type->bodyColumn());
    }

    /**
     * The publish timestamp for the given document, or null when it is an
     * unpublished draft.
     */
    public function publishedAtFor(LegalPageType $type): ?CarbonImmutable
    {
        return $this->getAttribute($type->publishedAtColumn());
    }

    /**
     * Whether the given document is currently published and safe to serve
     * publicly. A document with no body is never considered published.
     */
    public function isPublished(LegalPageType $type): bool
    {
        return $this->publishedAtFor($type) !== null
            && filled($this->bodyFor($type));
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'terms_published_at' => 'immutable_datetime',
            'privacy_published_at' => 'immutable_datetime',
        ];
    }
}
