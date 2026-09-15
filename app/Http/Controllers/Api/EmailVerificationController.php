<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * لما اليوزر يفتح الرابط اللي جاله بالإيميل.
     */
    public function verify(EmailVerificationRequest $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $request->fulfill();

        return response()->json(['message' => 'Email verified successfully.']);
    }

    /**
     * لو اليوزر ضاع منه الإيميل أو خلصت مدته، يقدر يطلب واحد جديد.
     */
    public function resend(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        if (is_null($request->user()->email)) {
            return response()->json(['message' => 'No email on this account to verify.'], 422);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.']);
    }
}