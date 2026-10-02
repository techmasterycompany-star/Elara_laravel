<?php

namespace App\Http\Controllers\Api\Newsletter;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = $request->user('sanctum');  
        $subscriber = NewsletterSubscriber::where('email', $validated['email'])->first();

        if ($subscriber && $subscriber->isActive()) {
            return response()->json([
                'message' => 'This email is already subscribed.',
            ], 409);
        }

        if ($subscriber) {
            $subscriber->update([
                'subscribed_at'   => now(),
                'unsubscribed_at' => null,
                'user_id'         => $user?->id ?? $subscriber->user_id,
            ]);
        } else {
            $subscriber = NewsletterSubscriber::create([
                'email'   => $validated['email'],
                'user_id' => $user?->id,
            ]);
        }

        return response()->json([
            'message' => 'Subscribed to the newsletter successfully.',
        ], 201);
    }
    public function unsubscribe(string $token)
    {
        $subscriber = NewsletterSubscriber::where('unsubscribe_token', $token)->first();

        if (! $subscriber) {
            return response()->json([
                'message' => 'Invalid unsubscribe link.',
            ], 404);
        }

        if (! $subscriber->isActive()) {
            return response()->json([
                'message' => 'You are already unsubscribed.',
            ]);
        }

        $subscriber->update(['unsubscribed_at' => now()]);

        return response()->json([
            'message' => 'You have been unsubscribed successfully.',
        ]);
    }
}