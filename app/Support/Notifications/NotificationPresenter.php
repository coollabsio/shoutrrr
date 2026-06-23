<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\Cursor;

class NotificationPresenter
{
    /**
     * Number of notifications returned per page. The bell dropdown seeds the
     * first page and lazily loads subsequent pages as the user scrolls.
     */
    public const int PER_PAGE = 10;

    /**
     * Build a cursor-paginated page of notifications for the bell dropdown.
     *
     * The first page (`$cursor === null`) also carries `unreadCount` so the
     * badge can render without a second request; load-more pages omit the count
     * since the badge does not change while scrolling.
     *
     * @return array{items: array<int, array<string, mixed>>, unreadCount: int, nextCursor: string|null}
     */
    public static function collection(User $user, ?string $workspaceId, ?string $cursor = null): array
    {
        if ($workspaceId === null) {
            return ['items' => [], 'unreadCount' => 0, 'nextCursor' => null];
        }

        $base = $user->notifications()->where(function ($query) use ($workspaceId): void {
            $query->where('data->workspace_id', $workspaceId)
                ->orWhereNull('data->workspace_id');
        });

        $paginator = (clone $base)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(
                self::PER_PAGE,
                ['*'],
                'cursor',
                $cursor !== null ? Cursor::fromEncoded($cursor) : null,
            );

        $items = collect($paginator->items())
            ->map(static fn (DatabaseNotification $n): array => self::item($n))
            ->all();

        return [
            'items' => $items,
            'unreadCount' => $cursor === null ? (clone $base)->whereNull('read_at')->count() : 0,
            'nextCursor' => $paginator->nextCursor()?->encode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        $item = [
            'id' => $notification->id,
            'event' => $data['event'] ?? '',
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'href' => $data['href'] ?? null,
            'icon' => $data['icon'] ?? 'bell',
            'read' => $notification->read_at !== null,
            'timeLabel' => $notification->created_at?->diffForHumans() ?? '',
        ];

        $actions = self::actions($data);

        return $actions === [] ? $item : [...$item, 'actions' => $actions];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{key: string, label: string, variant: string, method: string, href: string}>
     */
    private static function actions(array $data): array
    {
        if (($data['event'] ?? null) !== NotificationType::WorkspaceInvite->value) {
            return [];
        }

        if (! is_string($data['invitation_id'] ?? null)) {
            return [];
        }

        $invitationId = $data['invitation_id'];

        return [
            [
                'key' => 'accept',
                'label' => 'Accept',
                'variant' => 'primary',
                'method' => 'post',
                'href' => route('workspace.invitations.accept', $invitationId, absolute: false),
            ],
            [
                'key' => 'deny',
                'label' => 'Deny',
                'variant' => 'secondary',
                'method' => 'delete',
                'href' => route('workspace.invitations.deny', $invitationId, absolute: false),
            ],
        ];
    }
}
