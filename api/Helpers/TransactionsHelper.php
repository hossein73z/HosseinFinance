<?php

function extractTransactionFromText(string $text): ?array
{
    // --- Bank Name ---
    preg_match('/بلو/u', $text, $bank); // Blu
    if ($bank) $bank = $bank[0];

    $transaction = [];
    if ($bank == 'بلو') {
        $transaction['bank'] = $bank;

        // --- Amount ---
        preg_match('/ (.+?) ریال به حساب شما نشست\./um', $text, $amount);
        if ($amount) {
            $amount = cleanAndValidateNumber(preg_replace('/\D+/', '', $amount[1]));
            $transaction['amount'] = $amount;
            $transaction['type'] = 'inward';
        } else {
            preg_match('/ (.+?) ریال از حساب شما پرید\./um', $text, $amount);
            if ($amount) {
                $amount = cleanAndValidateNumber(preg_replace('/\D+/', '', $amount[1]));
                $transaction['amount'] = $amount;
                $transaction['type'] = 'outward';
            } else return null;
        }

        // --- Balance ---
        preg_match('/موجودی: (.+?) ریال/um', $text, $balance);
        if ($balance) {
            $balance = cleanAndValidateNumber(preg_replace('/\D+/', '', $balance[1]));
            $transaction['balance'] = $balance;
        } else return null;

        // --- Date ---
        preg_match('/^(....)\.(..)\.(..)$/um', $text, $date);

        if ($date) {
            $year = cleanAndValidateNumber($date[1]);
            $month = cleanAndValidateNumber($date[2]);
            $day = cleanAndValidateNumber($date[3]);

            $date = $year . '/' . $month . '/' . $day;
            $date = JalaliDate::fromString($date);
            $transaction['date'] = $date;
        } else $transaction['date'] = JalaliDate::fromGregorian();

        // --- Time ---
        preg_match('/^(..):(..)$/um', $text, $time);
        if ($time) {
            $time = cleanAndValidateNumber($time[1]) . ':' . cleanAndValidateNumber($time[2]);
            $transaction['time'] = $time;
        } else $transaction['time'] = (new DateTime())->format('H:i');
    }
    return $transaction;
}
