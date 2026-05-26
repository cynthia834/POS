<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class MPesaGateway implements PaymentGatewayInterface
{
    protected $consumerKey;
    protected $consumerSecret;
    protected $shortcode;
    protected $passkey;
    protected $callbackUrl;
    protected $baseUrl;

    public function __construct()
    {
        $config = config('services.mpesa');
        $this->consumerKey = $config['consumer_key'] ?? '';
        $this->consumerSecret = $config['consumer_secret'] ?? '';
        $this->shortcode = $config['shortcode'] ?? '174379';
        $this->passkey = $config['passkey'] ?? '';
        $this->callbackUrl = $config['callback_url'] ?? '';
        
        $env = $config['env'] ?? 'sandbox';
        $this->baseUrl = ($env === 'production') 
            ? 'https://api.safaricom.co.ke' 
            : 'https://sandbox.safaricom.co.ke';
    }

    /**
     * Initiate M-Pesa STK Push charge.
     */
    public function charge(Order $order, float $amount, array $data = [])
    {
        $phone = $data['phone_number'] ?? $order->phone ?? '';
        if (empty($phone)) {
            throw new Exception("M-Pesa payment requires a phone number.");
        }

        $formattedPhone = $this->formatPhoneNumber($phone);
        $useMock = ($data['simulate'] ?? false) && app()->environment('testing');

        if ($useMock) {
            $mockCheckoutRequestId = 'ws_CO_' . date('dmYHis') . '_' . rand(100, 999);
            $payment = $order->payments()->create([
                'amount' => $amount,
                'status' => 'pending',
                'payment_method' => 'mpesa',
                'checkout_request_id' => $mockCheckoutRequestId,
                'phone_number' => $formattedPhone,
            ]);

            return [
                'payment' => $payment,
                'mpesa_response' => [
                    'MerchantRequestID' => 'mock_merchant_id',
                    'CheckoutRequestID' => $mockCheckoutRequestId,
                    'ResponseCode' => '0',
                    'ResponseDescription' => 'Mock STK Push initiated successfully',
                    'CustomerMessage' => 'Mock STK Push initiated successfully',
                ],
            ];
        }

        if (empty($this->consumerKey) || empty($this->consumerSecret)) {
            throw new Exception('M-Pesa API credentials are not configured. Cannot send STK push to customer phone.');
        }

        // 1. Generate access token
        $accessToken = $this->generateAccessToken();

        // 2. Initiate STK Push
        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int) $amount,
            'PartyA' => $formattedPhone,
            'PartyB' => $this->shortcode,
            'PhoneNumber' => $formattedPhone,
            'CallBackURL' => $this->callbackUrl,
            'AccountReference' => 'Order-' . $order->id,
            'TransactionDesc' => 'Payment for Order ' . $order->id,
        ];

        Log::info('Initiating M-Pesa STK Push', ['payload' => $payload]);

        $response = Http::withToken($accessToken)
            ->post($this->baseUrl . '/mpesa/stkpush/v1/processrequest', $payload);

        if (!$response->successful()) {
            Log::error('M-Pesa STK Push API Error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new Exception("M-Pesa STK Push failed: " . ($response->json('errorMessage') ?? 'Unknown error'));
        }

        $resData = $response->json();
        
        if (($resData['ResponseCode'] ?? '') !== '0') {
            Log::error('M-Pesa STK Push rejected', ['response' => $resData]);
            throw new Exception("M-Pesa STK Push rejected: " . ($resData['CustomerMessage'] ?? 'Unknown error'));
        }

        // 3. Create pending payment record
        $payment = $order->payments()->create([
            'amount' => $amount,
            'status' => 'pending',
            'payment_method' => 'mpesa',
            'checkout_request_id' => $resData['CheckoutRequestID'],
            'phone_number' => $formattedPhone,
        ]);

        return [
            'payment' => $payment,
            'mpesa_response' => $resData
        ];
    }

    /**
     * Generate OAuth Access Token from Safaricom Daraja
     */
    protected function generateAccessToken(): string
    {
        $url = $this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials';
        
        $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->get($url);

        if (!$response->successful()) {
            Log::error('M-Pesa OAuth Error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new Exception("Failed to generate M-Pesa Access Token.");
        }

        return $response->json('access_token');
    }

    /**
     * Format phone number to Safaricom standard (2547XXXXXXXX or 2541XXXXXXXX)
     */
    protected function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        } elseif (str_starts_with($phone, '7') || str_starts_with($phone, '1')) {
            $phone = '254' . $phone;
        }

        return $phone;
    }

    /**
     * Query Daraja for STK status and update the payment record (fallback when webhook is unreachable).
     */
    public function queryAndSyncPayment(Payment $payment): string
    {
        if ($payment->status !== 'pending' || empty($payment->checkout_request_id)) {
            return $payment->status;
        }

        if (empty($this->consumerKey) || empty($this->consumerSecret)) {
            return $payment->status;
        }

        try {
            $accessToken = $this->generateAccessToken();
            $timestamp = date('YmdHis');
            $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

            $response = Http::withToken($accessToken)
                ->post($this->baseUrl . '/mpesa/stkpushquery/v1/query', [
                    'BusinessShortCode' => $this->shortcode,
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'CheckoutRequestID' => $payment->checkout_request_id,
                ]);

            if (!$response->successful()) {
                Log::warning('M-Pesa STK Query HTTP error', [
                    'payment_id' => $payment->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return $payment->status;
            }

            $data = $response->json();
            $handler = new MpesaStkResultHandler();

            return $handler->apply(
                $payment->fresh(),
                $data['ResultCode'] ?? null,
                $data['ResultDesc'] ?? '',
                null
            );
        } catch (Exception $e) {
            Log::warning('M-Pesa STK Query exception', [
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);
            return $payment->status;
        }
    }
}
