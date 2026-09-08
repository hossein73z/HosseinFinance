<?php

function sendAllHoldings(User $user, DatabaseManager $db, array $data): void
{
    $holdings = getHoldingsWithAssetDetails(['user_id' => $user->getId()], $db);
    if ($holdings) {
        $html = "<h1>دارایی‌های ثبت شده‌ی شما:</h1>";
        $html .= '<p><br></p>';
        $total_pro_los = 0;
        foreach ($holdings as $holding) {
            $total_pro_los += $holding['amount'] * ($holding['current_price'] - $holding['avg_price']) * $holding['exchange_rate'];
            $html .= createHoldingDetailRichHTML(
                holding: $holding,
                user_base_currency: $user->getBaseCurrency(),
                attributes: ['org_amount', 'org_total_price', 'profit']
            );
            $html .= '<hr>';
        }

        $pro_los_html =
            ($total_pro_los == 0) ?
                "<p>🟤 جمع سود/زیان: ۰ " . $user->getBaseCurrency() . '</p>' : (
            ($total_pro_los > 0) ?
                "<p>🟢 جمع سود: " . beautifulNumber($total_pro_los) . ' ' . $user->getBaseCurrency() . '</p>' :
                "<p>🔴 جمع ضرر: " . beautifulNumber($total_pro_los) . ' ' . $user->getBaseCurrency() . '</p>'
            );
        $html .= '<p><br></p>' . $pro_los_html;

        $data['rich_message'] = ['is_rtl' => true, 'html' => $html];
        sendToTelegram('sendRichMessage', $data);
    } else {
        sendToTelegram('sendMessage', ['chat_id' => $user->getid(), 'text' => 'شما هیچ دارایی‌ای ثبت نکرده‌اید.']);
    }
}

function sendHoldingDetail(array $holding, array $data, string $user_base_currency = 'ریال', string|int|null $message_id = null): void
{

    array_unshift($data['reply_markup']['keyboard'], [
        createWebAppBtn(
            text: '✏ ویرایش ' . beautifulNumber($holding['asset_name'], null),
            path: '/assets/holding.html',
            params: ['holding' => base64_encode(json_encode($holding))],
            add_api: true
        )
    ]);

    $html = createHoldingDetailRichHTML($holding, user_base_currency: $user_base_currency, detail_btn: false);
    $html .= '<hr>';
    $callback_data = json_encode(['holdings_list' => null]);
    $html .= "<tg-button-row><tg-button type='callback_data' style='primary' data='$callback_data'>" . 'برگشت به لیست دارایی‌ها' . "</tg-button></tg-button-row>";

    $data['rich_message'] = ['is_rtl' => true, 'html' => $html];

    if ($message_id) {
        $data['message_id'] = $message_id;
        sendToTelegram('editMessageText', $data);
    } else sendToTelegram('sendRichMessage', $data);
}

function checkAndAddEditHoldingButton(array $data, User $user, DatabaseManager $db): array
{
    $progress = $user->getProgress();
    if ($progress && key($progress) === 'view_holding') {
        $holding = getHoldingsWithAssetDetails(['h.id' => $progress['view_holding']['holding_id'], 'h.user_id' => $user->getId()], $db, true);

        if ($holding) {
            array_unshift($data['reply_markup']['keyboard'], [
                createWebAppBtn(
                    text: '✏ ویرایش ' . $holding['asset_name'],
                    path: '/assets/holding.html',
                    params: ['holding' => base64_encode(json_encode($holding))],
                    add_api: true
                )
            ]);
        }
    }

    return $data;
}

/**
 * Return a list of holdings (Or just one, if `Single == true`) containing `asset_name`,
 * `current_price`, `base_currency` and `exchange_rate` (Based on user's base currency).
 * 'date' column is also converted to Jalali string in 'yyyy/mm/dd' format.
 */
function getHoldingsWithAssetDetails(array $conditions, DatabaseManager $db, bool $single = false): ?array
{
    $holdings = $db->read(
        table: 'holdings h',
        conditions: $conditions,
        single: $single,
        selectColumns: "
            h.*,
            a.name                               AS asset_name,
            a.price                              AS current_price,
            a.base_currency                      AS base_currency,
            base_asset.price / user_asset.price  AS exchange_rate",
        join: "
            LEFT JOIN assets a ON h.asset_id = a.id
            LEFT JOIN assets base_asset ON base_asset.name = a.base_currency
            LEFT JOIN (
                SELECT id, IFNULL(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.base_currency')), 'ریال') AS base_currency
                FROM users) u ON h.user_id = u.id
            LEFT JOIN assets user_asset ON user_asset.name = u.base_currency"
    );

    if (!$holdings) {
        return null;
    }

    if ($single) {
        $holdings['date'] = JalaliDate::fromGregorianString($holdings['date'])->format();
    } else {
        foreach ($holdings as &$holding)
            $holding['date'] = JalaliDate::fromGregorianString($holding['date'])->format();
        unset($holding);
    }

    return $holdings;
}

function createHoldingDetailRichHTML(
    array  $holding,
    string $user_base_currency = 'ریال',
    bool   $detail_btn = true,
    array  $attributes = [
        'space',
        'date',
        'org_amount',
        'org_price',
        'new_price',
        'org_total_price',
        'new_total_price',
        'space',
        'profit'
    ]
): string
{
    $html = '<h3>' . beautifulNumber($holding['asset_name'], null);
    if ($detail_btn) {
        $callback_data = json_encode(['show_holding' => $holding['id']]);
        $html .= " <tg-button type='callback_data' style='link' data='$callback_data'>" . 'جزئیات و ویرایش' . '</tg-button>';
    }
    $html .= '</h3>';
    $html .= '<ul>';
    foreach ($attributes as $attribute) {

        if ($attribute == 'space') {
            $html .= '<li>';
            $html .= '</li>';
        }

        if ($attribute == 'date' && isset($holding['date'])) {
            $date = JalaliDate::fromString($holding['date'])->toPersianMonths();
            $html .= '<li>';
            $html .= "تاریخ خرید: " . beautifulNumber("$date[day] $date[month] $date[year]", null);
            $html .= '</li>';
        }

        if ($attribute == 'org_amount') {
            $html .= '<li>';
            $html .= "مقدار / تعداد: " . beautifulNumber(floatval($holding['amount']));
            $html .= '</li>';
        }

        if ($attribute == 'org_price') {
            $html .= '<li>';
            $html .= "قیمت خرید هر واحد: " . beautifulNumber(floatval($holding['avg_price'])) . " " . $holding['base_currency'];
            $html .= '</li>';
        }

        if ($attribute == 'new_price') {
            $html .= '<li>';
            $html .= "قیمت لحظه‌ای هر واحد: " . beautifulNumber($holding['current_price']) . " " . $holding['base_currency'];
            $html .= '</li>';
        }

        if ($attribute == 'org_total_price') {
            $html .= '<li>';
            $html .= "قیمت خرید کل دارایی: " . beautifulNumber($holding['avg_price'] * $holding['amount']) . " " . $holding['base_currency'];
            $html .= '</li>';
        }

        if ($attribute == 'new_total_price') {
            $html .= '<li>';
            $html .= "قیمت لحظه‌ای کل دارایی: " . beautifulNumber($holding['current_price'] * $holding['amount']) . " " . $holding['base_currency'];
            $html .= '</li>';
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
            $html .= '<li>';
            $html .= $pro_los_string;
            $html .= '</li>';
        }
    }

    return $html;
}

function calculateProLos(float $p1, float $p2, float $amount = 1, float $conversion_rate = 1): float
{
    $total_price_def = $amount * ($p2 - $p1);
    return $total_price_def * $conversion_rate;
}
