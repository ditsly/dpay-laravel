<?php

declare(strict_types=1);

namespace DPay\Laravel\Exceptions;

use DPay\Exceptions\DPayException;

/**
 * The package is missing a setting it needs for the current call — a token
 * for the configured mode, a webhook secret, a store uid. The message is
 * bilingual (Arabic first) and names the .env key to set.
 */
final class NotConfiguredException extends \RuntimeException implements DPayException
{
    public static function token(string $mode, string $envKey): self
    {
        return new self(sprintf(
            'لم يُضبط رمز الوصول لوضع %1$s — ضع %2$s في ملف .env. / No API token for %1$s mode — set %2$s in .env.',
            $mode,
            $envKey,
        ));
    }

    public static function webhookSecret(): self
    {
        return new self('لم يُضبط سرّ الـ webhook — ضع DPAY_WEBHOOK_SECRET (whsec_…) في ملف .env. / No webhook secret — set DPAY_WEBHOOK_SECRET (whsec_…) in .env.');
    }

    public static function storeUid(): self
    {
        return new self('لم يُضبط DPAY_STORE_UID — شغّل php artisan dpay:doctor لتوليد قيمة عشوائية واحفظها في .env. / DPAY_STORE_UID is not set — run php artisan dpay:doctor to generate one and keep it in .env.');
    }

    public static function mode(string $mode): self
    {
        return new self(sprintf(
            'قيمة DPAY_MODE «%1$s» غير صالحة — استخدم live أو sandbox. / DPAY_MODE "%1$s" is not valid — use live or sandbox.',
            $mode,
        ));
    }
}
