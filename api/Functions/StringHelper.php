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


function getJalaliDate(
    int|string|null $g_y = null,
    int|string|null $g_m = null,
    int|string|null $g_d = null,
    string|null     $delimiter = '/',
): string
{
    // Get current Gregorian Date
    $g_y = $g_y ?? date('Y');
    $g_m = $g_m ?? date('m');
    $g_d = $g_d ?? date('d');

    $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

    // Check for leap year
    $gy = $g_y - 1600;
    $gm = $g_m - 1;
    $gd = $g_d - 1;

    $g_day_no = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);

    for ($i = 0; $i < $gm; ++$i)
        $g_day_no += $g_days_in_month[$i];

    if ($gm > 1 && (($g_y % 4 == 0 && $g_y % 100 != 0) || ($g_y % 400 == 0)))
        $g_day_no++; // leap and after Feb

    $g_day_no += $gd;
    $j_day_no = $g_day_no - 79;
    $j_np = floor($j_day_no / 12053);
    $j_day_no = $j_day_no % 12053;
    $jy = 979 + 33 * $j_np + 4 * floor($j_day_no / 1461);
    $j_day_no %= 1461;

    if ($j_day_no >= 366) {
        $jy += floor(($j_day_no - 1) / 365);
        $j_day_no = ($j_day_no - 1) % 365;
    }

    for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i)
        $j_day_no -= $j_days_in_month[$i];

    $jm = $i + 1;
    $jd = $j_day_no + 1;

    // Return formatted as YYYY/MM/DD
    return sprintf('%04d' . $delimiter . '%02d' . $delimiter . '%02d', $jy, $jm, $jd);
}