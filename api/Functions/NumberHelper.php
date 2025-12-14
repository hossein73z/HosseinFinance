<?php

const persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
const arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
const english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

/**
 * Cleans and validates a message text, converting it into a normalized numeric string format (e.g., "123.45").
 * Handles various numeral systems and common delimiters.
 *
 * @param string $messageText The input string containing a potential number.
 * @return string|null The cleaned and validated number string, or null if validation fails.
 */
function cleanAndValidateNumber(string $messageText): ?string
{
    // 1. Normalize Persian/Arabic Numerals to English (ASCII) Numerals
    $cleanedText = str_replace(persian, english, $messageText);
    $cleanedText = str_replace(arabic, english, $cleanedText);

    // 2. Normalize Delimiters
    $cleanedText = str_replace([' ', ','], '.', $cleanedText); // Replace space and comma with dot as a common simplification

    // 3. Remove/Normalize potential thousands separators (if they are not the main decimal dot)
    $sanitized = preg_replace('/[^\d.]/', '', $cleanedText);

    // Remove multiple dots, keeping only the first one (or none if no dot is present)
    $parts = explode('.', $sanitized, 2);
    $finalNumberString = $parts[0];
    if (isset($parts[1])) {
        $finalNumberString .= '.' . $parts[1];
    }

    // Check if the resulting string is a number
    if (is_numeric($finalNumberString)) {
        // Ensure it's returned as a string (e.g., "123.45")
        return floatval($finalNumberString);
    }

    // If validation fails, return null
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
