<?php

namespace App\Services\User;

/**
 * Utility service standardizing Sri Lankan telephone numbers to E.164 format (+94XXXXXXXXX).
 */
class SriLankaPhoneNormalizer
{
    /**
     * Normalize a phone number to standard Sri Lankan E.164 format (+94XXXXXXXXX).
     *
     * @param string|null $phone
     * @return string|null
     */
    public static function normalize(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $cleaned = trim($phone);

        // Handle scientific notation from spreadsheets (e.g. 9.48E+10)
        if (stripos($cleaned, 'e+') !== false || stripos($cleaned, 'e-') !== false) {
            $cleaned = number_format((float) $cleaned, 0, '', '');
        }

        // Remove non-digit characters except leading plus
        $hasLeadingPlus = str_starts_with($cleaned, '+');
        $digits = preg_replace('/\D/', '', $cleaned);

        if (empty($digits)) {
            return null;
        }

        // Format for Sri Lanka
        if (str_starts_with($digits, '94')) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+94' . substr($digits, 1);
        }

        if (strlen($digits) === 9) {
            return '+94' . $digits;
        }

        return $hasLeadingPlus ? '+' . $digits : $digits;
    }
}
