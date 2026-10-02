<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\NewsletterCampaignMail;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AdminNewsletterController extends Controller
{
   
    public function subscribers(Request $request)
    {
        $subscribers = NewsletterSubscriber::whereNull('unsubscribed_at')
            ->latest('subscribed_at')
            ->paginate(50);

        return response()->json($subscribers);
    }

   
    public function sendCampaign(Request $request)
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body'    => ['required', 'string', 'max:10000'],
        ]);

        $subscribers = NewsletterSubscriber::whereNull('unsubscribed_at')->get();

        if ($subscribers->isEmpty()) {
            return response()->json([
                'message' => 'No active subscribers to send to.',
            ], 422);
        }

        foreach ($subscribers as $subscriber) {
            $unsubscribeUrl = url("/api/newsletter/unsubscribe/{$subscriber->unsubscribe_token}");

            Mail::to($subscriber->email)->send(
                new NewsletterCampaignMail(
                    subjectLine: $validated['subject'],
                    body: $validated['body'],
                    unsubscribeUrl: $unsubscribeUrl,
                )
            );
        }

        return response()->json([
            'message'          => 'Campaign queued for sending.',
            'recipients_count' => $subscribers->count(),
        ]);
    }
}