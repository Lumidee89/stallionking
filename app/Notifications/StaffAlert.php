<?php

namespace App\Notifications;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifier;

/** In-app notification shown under the bell in the staff header. */
class StaffAlert extends Notification
{
    public function __construct(private string $title, private string $body, private ?string $url = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }

    /** Notify every active admin and superadmin, except the person who caused it. */
    public static function admins(string $title, string $body, ?string $url = null, ?User $except = null): void
    {
        $admins = User::where('active', true)
            ->whereIn('role', [Role::Admin->value, Role::Superadmin->value])
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->get();
        Notifier::send($admins, new self($title, $body, $url));
    }
}
