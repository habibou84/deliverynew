<?php

namespace App\Services\Messaging\Gateways;

use App\Services\Messaging\MessagingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TwilioSmsGateway implements SmsGateway
{
    public function send(string $to, string $text): string
    {
        ['sid' => $sid, 'token' => $token, 'from' => $from] = config('messaging.sms.twilio');

        if (blank($sid) || blank($token) || blank($from)) {
            throw new MessagingException('SMS non configuré (TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM).');
        }

        try {
            $response = Http::withBasicAuth($sid, $token)->asForm()->timeout(15)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'To' => $to,
                    'From' => $from,
                    'Body' => $text,
                ]);
        } catch (ConnectionException $e) {
            throw new MessagingException('Twilio injoignable : '.$e->getMessage(), retryable: true);
        }

        if ($response->failed()) {
            throw new MessagingException(
                'Twilio : '.($response->json('message') ?? 'erreur HTTP '.$response->status()),
                retryable: $response->serverError() || $response->status() === 429,
            );
        }

        return (string) $response->json('sid');
    }
}
