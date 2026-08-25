<?php

const persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
const arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
const english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

function toEnglishDigits(string $text): string
{
    $cleanedText = str_replace(persian, english, $text);
    $cleanedText = str_replace(arabic, english, $cleanedText);
    return $cleanedText;
}

/**
 * Cleans and validates a message text, converting it into a normalized numeric string format (e.g., "123.45").
 * Handles various numeral systems and common delimiters.
 *
 * @param string $messageText The input string containing a potential number.
 * @return string|null The cleaned and validated number string, or null if validation fails.
 */
function cleanAndValidateNumber(string $messageText): ?string
{
    // Persian & Arabic digits mapping to Western Arabic (0-9)
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٫', '٬'];
    $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', ','];

    // 1. Normalize Persian/Arabic numerals and separators
    $cleaned = str_replace($persian, $english, trim($messageText));
    $cleaned = str_replace($arabic, array_slice($english, 0, 10), $cleaned);

    // Remove any spaces
    $cleaned = str_replace(' ', '', $cleaned);

    // 2. Determine delimiter roles (thousands separator vs. decimal point)
    $hasComma = str_contains($cleaned, ',');
    $hasDot = str_contains($cleaned, '.');

    if ($hasComma && $hasDot) {
        // Both exist: the last one is the decimal point
        if (strrpos($cleaned, '.') > strrpos($cleaned, ',')) {
            // E.g., "1,234.56" -> remove commas
            $cleaned = str_replace(',', '', $cleaned);
        } else {
            // E.g., "1.234,56" -> remove dots, replace comma with dot
            $cleaned = str_replace('.', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
        }
    } elseif ($hasComma) {
        // Only commas exist:
        // If multiple commas, they are thousands separators (e.g. "1,234,567")
        if (substr_count($cleaned, ',') > 1) {
            $cleaned = str_replace(',', '', $cleaned);
        } else {
            // Single comma: treat as decimal separator (e.g. "1234,56")
            $cleaned = str_replace(',', '.', $cleaned);
        }
    } elseif ($hasDot) {
        // Only dots exist:
        // If multiple dots, treat them as thousands separators (e.g. "1.234.567")
        if (substr_count($cleaned, '.') > 1) {
            $cleaned = str_replace('.', '', $cleaned);
        }
    }

    // 3. Remove any remaining invalid characters (keeping only digits, one dot, and optional leading minus)
    $cleaned = preg_replace('/[^\d.-]/', '', $cleaned);

    // 4. Validate and return
    if (is_numeric($cleaned)) {
        return (string)(float)$cleaned; // Or return (float) $cleaned if you change return type to ?float
    }

    return null;
}

/**
 * Cleans, validates, and formats a number string by adding thousands delimiters.
 * Preserves the original number of decimal places if present.
 *
 * @param string $text The raw input number string (e.g., "1125000000", "123.45").
 * @return string|null The formatted string (e.g., "1,125,000,000") or null on invalid input.
 */
function beautifulNumber(string $text, string|null $delimiter = ',', bool $persianNumbers = true): ?string
{
    if ($delimiter) {
        // 1. Clean and validate the input using the existing function.
        $cleanedNumberString = cleanAndValidateNumber($text);

        if ($cleanedNumberString === null) {
            return null; // Return null if validation fails
        }

        // 2. Determine the number of decimal places to preserve.

        $parts = explode('.', $cleanedNumberString, 2);
        $decimalPart = $parts[1] ?? null;

        $decimals = 0;
        if ($decimalPart !== null) {
            $decimals = strlen($decimalPart);
        }

        // 3. Convert the cleaned string to a float and use PHP's number_format.
        $numberAsFloat = (float)$cleanedNumberString;
        // Use number_format(number, decimals, dec_point, thousands_sep)
        $beautifiedNumber = number_format($numberAsFloat, $decimals, '.', $delimiter);
    } else $beautifiedNumber = $text;

    if ($persianNumbers) {
        return str_replace(english, persian, $beautifiedNumber);
    } else {
        return $beautifiedNumber;
    }
}

function markdownScape(?string $text): string
{
    return $text ? str_replace(["(", ")", ".", "-", "!", "<", ">"], ["\(", "\)", "\.", "\-", "\!", "\<", "\>"], $text) : '';
}
