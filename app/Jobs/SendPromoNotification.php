<?php

namespace App\Jobs;

use App\Models\Banner;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPromoNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $bannerId) {}

    public function handle(): void
    {
        $banner = Banner::find($this->bannerId);

        if (! $banner || ! $banner->is_active) {
            return;
        }

        $type    = 'promotion';
        $message = 'New offer: ' . $banner->title;
        $now     = now();

        User::query()
            ->where('is_active', true)
            ->where('promo_notifications', true)
            ->whereDoesntHave('notifications', fn ($q) => $q
                ->where('type', $type)
                ->where('message', $message))
            ->chunkById(500, function ($users) use ($type, $message, $now) {
                $rows = $users->map(fn ($user) => [
                    'user_id'    => $user->id,
                    'type'       => $type,
                    'message'    => $message,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                Notification::insert($rows);
            });
    }
}