<?php

/**
 * Return a list of holdings (Or just one, if `Single == true`) containing `asset_name`,
 * `current_price`, `base_currency` and `exchange_rate` (Based on user's base currency).
 * 'date' column is also converted to Jalali string in 'yyyy/mm/dd' format.
 */
function getHoldingsWithAssetDetails(array $conditions, DatabaseManager $db, bool $single = false): bool|array
{
    $select_price = "select price from assets where assets.name";

    $asset_base = "a.base_currency";
    $asset_base_price = "$select_price = $asset_base";

    $user_base = "ifnull(json_unquote(json_extract(u.settings, '$.base_currency')), 'ریال')";
    $user_base_price = "$select_price = $user_base";

    $holdings = $db->read(
        table: 'holdings h',
        conditions: $conditions,
        single: $single,
        selectColumns: "
            h.*,
            a.name                                   as asset_name,
            a.price                                  as current_price,
            a.base_currency                          as base_currency,
            ($asset_base_price) / ($user_base_price) as exchange_rate",
        join: '
            LEFT JOIN assets a ON h.asset_id = a.id
            LEFT JOIN users u ON h.user_id = u.id'
    );

    if ($single) $holdings['date'] = JalaliDate::fromGregorianString($holdings['date'])->format();
    else foreach ($holdings as $holding) {
        $holding['date'] = JalaliDate::fromGregorianString($holding['date'])->format();
    }

    return $holdings;
}

function createHoldingDetailText(
    array   $holding,
    ?string $markdown = null,
    string  $user_base_currency = 'ریال',
    array   $attributes = [
        'space',
        'date',
        'org_amount',
        'org_price',
        'new_price',
        'org_total_price',
        'new_total_price',
        'space',
        'profit'
    ],
    ?string $holding_mssg_id = null,
    ?string $initial_mssg_id = null
): string
{
    // Create tree view for each presented attribute
    $tree = '';
    foreach ($attributes as $attribute) {

        if ($attribute == 'space') {
            $tree .= "\n   │ " . "‏";
        }

        if ($attribute == 'date' && isset($holding['date'])) {
            $date = JalaliDate::fromString($holding['date'])->toPersianMonths();
            $tree .=
                "\n   ┤── تاریخ خرید: " .
                beautifulNumber("$date[day] $date[month] $date[year]", null);
        }

        if ($attribute == 'org_amount') {
            $tree .=
                "\n   ┤── مقدار / تعداد: " .
                beautifulNumber(floatval($holding['amount']));
        }

        if ($attribute == 'org_price') {
            $tree .=
                "\n   ┤── قیمت خرید هر واحد: " .
                beautifulNumber(floatval($holding['avg_price'])) . " " . $holding['base_currency'];
        }

        if ($attribute == 'new_price') {
            $tree .=
                "\n   ┤── قیمت لحظه‌ای هر واحد: " .
                beautifulNumber($holding['current_price']) . " " . $holding['base_currency'];
        }

        if ($attribute == 'org_total_price') {
            $tree .=
                "\n   ┤── قیمت خرید کل دارایی: " .
                beautifulNumber($holding['avg_price'] * $holding['amount']) . " " . $holding['base_currency'];
        }

        if ($attribute == 'new_total_price') {
            $tree .=
                "\n   ┤── قیمت لحظه‌ای کل دارایی: " .
                beautifulNumber($holding['current_price'] * $holding['amount']) . " " . $holding['base_currency'];
        }

        if ($attribute == 'profit') {

            // Calculate and create profit string
            $pro_los = calculateProLos($holding['avg_price'], $holding['current_price'], $holding['amount'], $holding['exchange_rate']);
            $pro_los_string =
                ($pro_los == 0) ?
                    "🟤 سود/زیان: ۰ " . $user_base_currency : (
                ($pro_los > 0) ?
                    "🟢 سود: " . beautifulNumber($pro_los) . ' ' . $user_base_currency :
                    "🔴 ضرر: " . beautifulNumber($pro_los) . ' ' . $user_base_currency
                );

            $tree .= "\n   ┘── " . $pro_los_string;
        }
    }

    // Manage deep-link and Markdown escaping
    if ($markdown === 'MarkdownV2') {

        $tree = markdownScape($tree);

        $asset_name = beautifulNumber(markdownScape($holding['asset_name']), null);
        $holding['asset_name'] = "[$asset_name](https://t.me/" . BOT_ID . "?start=viewHolding_holdingId{$holding['id']}" . ($holding_mssg_id ? "_holdingsMssgId" . $holding_mssg_id : '') . ($initial_mssg_id ? "_initMssgId" . $initial_mssg_id : '') . ")" . '‏';
    } else $holding['asset_name'] = beautifulNumber($holding['asset_name'], null);

    return $holding['asset_name'] . $tree . "\n";
}

function calculateProLos(float $p1, float $p2, float $amount = 1, float $conversion_rate = 1): float
{
    $total_price_def = $amount * ($p2 - $p1);
    return $total_price_def * $conversion_rate;
}
