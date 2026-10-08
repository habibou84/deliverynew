<?php

namespace App\Enums;

enum MessageChannel: string
{
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::Sms => 'SMS',
        };
    }
}
