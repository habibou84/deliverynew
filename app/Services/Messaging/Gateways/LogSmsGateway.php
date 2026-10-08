<?php

namespace App\Services\Messaging\Gateways;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $text): string
    {
        Log::info("[SMS simulé] → {$to}", ['text' => $text]);

        return 'log-'.Str::uuid();
    }
}
