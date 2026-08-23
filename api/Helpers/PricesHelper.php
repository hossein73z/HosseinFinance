<?php

function CreateNamePricePairs(array $asset_names, DatabaseManager $db): array
{
    // Read prices for all base currencies
    $base_prices = $db->read('assets', ['name' => $asset_names]);
    // Create an array of [$name => $price] pairs
    return array_combine(
        array_column($base_prices, 'name'),
        array_map('floatval', array_column($base_prices, 'price'))
    );
}

function createPricesTextForSingleAssetType(array $assets, array $base_prices, string $user_base_currency): string
{
    $date = preg_split('/-/u', $assets[0]['date']);
    $date[1] = str_replace(
        ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'],
        ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
        $date[1]
    );

    $text = "آخرین قیمت ها در $date[2] $date[1] $date[0] ساعت " . $assets[0]['time'] . "\n";
    $text = beautifulNumber($text, null);

    // Create price texts and add them to the text
    foreach ($assets as $asset) {
        $asset_price = beautifulNumber($asset['price']);
        $asset_name = beautifulNumber($asset['name'], null);
        $asset_base_currency = beautifulNumber($asset['base_currency'], null);
        $text .= "\n$asset_name: $asset_price $asset_base_currency";

        if (
            $asset['base_currency'] != $user_base_currency &&
            $base_prices[$user_base_currency]
        ) {
            $exchange_rate = $base_prices[$asset['base_currency']] / $base_prices[$user_base_currency];
            $based_price = $asset['price'] * $exchange_rate;
            $text .= ' --> ' . beautifulNumber($based_price) . ' ' . $user_base_currency;
        }
    }
    return $text;
}

function deleteOldActiveLiveMessage(User $user, int|string $message_id, DatabaseManager $db): bool|array
{
    /**
     * Finds user's **active** live message in the database with `$message_id`
     * different from the one provided, and sends delete request to telegram.
     **/

    $live_mssg = $db->read(
        table: 'special_messages',
        conditions: [
            'user_id' => $user->getId(),
            'type' => 'live_price',
            'status' => 'active',
            '!message_id' => $message_id,
        ],
        single: true
    );
    if ($live_mssg)
        return sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $live_mssg['message_id']]);
    else
        return false;
}
