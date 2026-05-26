<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    /**
     * Send an OTP email; returns true only if the primary mailer accepted the message.
     */
    private function sendOtpEmail(string $email, string $code, string $subject, string $body): bool
    {
        try {
            Mail::raw($body, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning("Primary mailer failed for {$email}: {$e->getMessage()}");

            try {
                Mail::mailer('log')->raw($body, function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                });
            } catch (\Throwable $logError) {
                Log::error("Log mailer failed for {$email}: {$logError->getMessage()}");
            }

            return false;
        }
    }

    /**
     * Build OTP API response — includes the code when email did not send or in local/debug.
     */
    private function otpDeliveryResponse(string $email, string $code, bool $emailSent, string $successMessage): array
    {
        Log::info("OTP for {$email}: {$code}" . ($emailSent ? ' (emailed)' : ' (email failed — use code below)'));

        $exposeCode = !$emailSent || config('app.env') === 'local' || (bool) config('app.debug');

        $response = [
            'message' => $emailSent
                ? $successMessage
                : 'Email could not be delivered. Enter the verification code shown below.',
            'email_sent' => $emailSent,
        ];

        if ($exposeCode) {
            $response['verification_code'] = $code;
            $response['code'] = $code;
        }

        return $response;
    }

    /**
     * Handle authentication login request.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password.'
            ], 401);
        }

        // Generate a Sanctum token
        $token = $user->createToken('pos_auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'role' => $user->role
        ]);
    }

    /**
     * Send email verification code during registration.
     */
    public function sendVerificationCode(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:255|unique:users,email',
        ]);

        $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put('email_verification_' . $request->email, $code, now()->addMinutes(15));

        $emailSent = $this->sendOtpEmail(
            $request->email,
            $code,
            'Apex POS Email Verification Code',
            "Your Apex POS verification code is: {$code}\n\nThis code expires in 15 minutes."
        );

        return response()->json(
            $this->otpDeliveryResponse(
                $request->email,
                $code,
                $emailSent,
                'Verification code sent. Please check your email inbox (and spam folder).'
            )
        );
    }

    /**
     * Verify the sent registration code.
     */
    public function verifyCode(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
            'code' => 'required|string|size:6',
        ]);

        $cached = Cache::get('email_verification_' . $request->email);

        if (!$cached || $cached !== $request->code) {
            return response()->json([
                'message' => 'Invalid or expired verification code.'
            ], 422);
        }

        return response()->json([
            'message' => 'Email verified successfully.'
        ]);
    }

    /**
     * Handle user registration request.
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|string|in:admin,cashier',
            'verification_code' => 'required|string|size:6',
        ]);

        $cached = Cache::get('email_verification_' . $request->email);

        if (!$cached || $cached !== $request->verification_code) {
            return response()->json([
                'message' => 'Email verification code is invalid or expired.'
            ], 422);
        }

        // Clean cache verification entry
        Cache::forget('email_verification_' . $request->email);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

        $token = $user->createToken('pos_auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'role' => $user->role,
            'message' => 'User registered successfully.'
        ], 201);
    }

    /**
     * Send password reset code.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:255|exists:users,email',
        ]);

        $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put('password_reset_' . $request->email, $code, now()->addMinutes(15));

        $emailSent = $this->sendOtpEmail(
            $request->email,
            $code,
            'Apex POS Password Reset Code',
            "Your Apex POS password reset code is: {$code}\n\nThis code expires in 15 minutes."
        );

        return response()->json(
            $this->otpDeliveryResponse(
                $request->email,
                $code,
                $emailSent,
                'Password reset code sent. Please check your email inbox.'
            )
        );
    }

    /**
     * Reset the user password using OTP.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:255|exists:users,email',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $cached = Cache::get('password_reset_' . $request->email);

        if (!$cached || $cached !== $request->code) {
            return response()->json([
                'message' => 'Invalid or expired password reset code.'
            ], 422);
        }

        // Clean cache reset entry
        Cache::forget('password_reset_' . $request->email);

        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json([
            'message' => 'Password reset successfully.'
        ]);
    }

    /**
     * List all operators/users.
     */
    public function listOperators(Request $request)
    {
        $users = User::select('id', 'name', 'email', 'role', 'created_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($users);
    }

    /**
     * Create a new operator/user account.
     */
    public function createOperator(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|string|in:admin,cashier',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

        return response()->json([
            'message' => 'Operator registered successfully.',
            'user' => $user
        ], 201);
    }
}

