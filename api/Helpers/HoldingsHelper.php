<?php

function sendAllHoldings(User $user, DatabaseManager $db, string|int|null $message_id = null): void
{
    $holdings = getHoldingsWithAssetDetails(['user_id' => $user->getId()], $db);
    if ($holdings) {

        $title_html = "دارایی‌های ثبت شده‌ی شما:";

        $holdings_html = "";
        $total_pro_los = 0;
        foreach ($holdings as $holding) {
            $total_pro_los += $holding['amount'] * ($holding['current_price'] - $holding['avg_price']) * $holding['exchange_rate'];
            $holding_html = createHoldingDetailRichHTML(
                holding: $holding,
                user_base_currency: $user->getBaseCurrency(),
                attributes: ['org_amount', 'org_total_price', 'profit']
            );
            $holdings_html .= $holding_html;
        }

        $pro_los_string =
            ($total_pro_los == 0) ?
                "🟤 جمع سود/زیان: ۰ " . $user->getBaseCurrency() : (
            ($total_pro_los > 0) ?
                "🟢 جمع سود: " . beautifulNumber($total_pro_los) . ' ' . $user->getBaseCurrency() :
                "🔴 جمع ضرر: " . beautifulNumber($total_pro_los) . ' ' . $user->getBaseCurrency()
            );

        $html = "<h3>$title_html<br></h3><ul>$holdings_html</ul><hr><p>$pro_los_string</p>";

    } else {
        $html = '<p>.شما هیچ دارایی‌ای ثبت نکرده‌اید</p>';
    }

    $data = ['chat_id' => $user->getId(), 'rich_message' => ['is_rtl' => true, 'html' => $html]];
    if (!$message_id) sendToTelegram('sendRichMessage', $data);
    else {
        $data['message_id'] = $message_id;
        sendToTelegram('editMessageText', $data);
    }
}

function sendHoldingDetail(User $user, array $holding, string|int|null $message_id = null, bool $is_editing = false, bool $is_deleting = false): void
{
    $user_base_currency = $user->getBaseCurrency() ?? 'ریال';

    $html = createHoldingDetailRichHTML($holding, user_base_currency: $user_base_currency, detail_btn: false);
    $html .= '<hr>';

    if ($is_editing) {

        $html .= '<h3>قصد ویرایش کدام ویژگی از این دارایی را داری؟</h3>';

        $html .= '<tg-button-row>';

        // asset_name
        $edit_callback = json_encode(['edit_holding_name' => $holding['id']]);
        $html .= "<tg-button type='callback_data' style='link' data='$edit_callback'>" . 'نوع دارایی' . "</tg-button>";

        // amount
        $edit_callback = json_encode(['edit_holding_amount' => $holding['id']]);
        $html .= "<tg-button type='callback_data' style='link' data='$edit_callback'>" . 'مقدار دارایی' . "</tg-button>";

        // avg_price
        $edit_callback = json_encode(['edit_holding_price' => $holding['id']]);
        $html .= "<tg-button type='callback_data' style='link' data='$edit_callback'>" . 'قیمت خرید' . "</tg-button>";

        $html .= '</tg-button-row>';
        $html .= '<tg-button-row>';

        // note
//        $edit_callback = json_encode(['edit_holding_note' => $holding['id']]);
        $html .= "<tg-button type='disabled'>" . 'یادداشت' . "</tg-button>";

        // date
//        $edit_callback = json_encode(['edit_holding_date' => $holding['id']]);
        $html .= "<tg-button type='disabled'>" . 'تاریخ' . "</tg-button>";

        // time
//        $edit_callback = json_encode(['edit_holding_time' => $holding['id']]);
        $html .= "<tg-button type='disabled'>" . 'ساعت' . "</tg-button>";

        $html .= '</tg-button-row>';

        // Cancel button
        $edit_callback = json_encode(['view_holding' => $holding['id']]);
        $html .= "<tg-button-row><tg-button type='callback_data' style='danger' data='$edit_callback'>" . 'لغو' . "</tg-button></tg-button-row>";


    } elseif ($is_deleting) {

        // Delete confirm button
        $confirm_callback = json_encode(['delete_holding_conf' => $holding['id']]);
        $confirm_button = "<tg-button type='callback_data' style='danger' data='$confirm_callback'>" . 'تأیید حذف' . "</tg-button>";
        // Cancel delete button
        $cancel_callback = json_encode(['view_holding' => $holding['id']]);
        $cancel_button = "<tg-button type='callback_data' style='success' data='$cancel_callback'>" . 'لغو' . "</tg-button>";

        $html .= "<tg-button-row>$confirm_button $cancel_button</tg-button-row>";
    } else {

        // Edit button
        $edit_callback = json_encode(['edit_holding' => $holding['id']]);
        $edit_button = "<tg-button type='callback_data' data='$edit_callback'>" . 'ویرایش' . "</tg-button>";
        // Delete button
        $delete_callback = json_encode(['delete_holding' => $holding['id']]);
        $delete_button = "<tg-button type='callback_data' data='$delete_callback'>" . 'حذف دارایی' . "</tg-button>";

        $html .= "<tg-button-row>$edit_button $delete_button</tg-button-row>";
    }

    // Back button
    $back_callback = json_encode(['show_all_holdings' => null]);
    $html .= "<tg-button-row><tg-button type='callback_data' style='primary' data='$back_callback'>" . 'برگشت به لیست دارایی‌ها' . "</tg-button></tg-button-row>";

    $data['chat_id'] = $user->getId();
    $data['rich_message'] = ['is_rtl' => true, 'html' => $html];
    if ($message_id) {
        $data['message_id'] = $message_id;
        sendToTelegram('editMessageText', $data);
    } else {
        sendToTelegram('sendRichMessage', $data);
    }
}

/**
 * Return a list of holdings (Or just one, if `Single == true`) containing `asset_name`,
 * `asset_type`, `current_price`, `base_currency` and `exchange_rate` (Based on user's base currency).
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
            a.asset_type                         AS asset_type,
            a.price                              AS current_price,
            a.base_currency                      AS base_currency,
            base_asset.price / user_asset.price  AS exchange_rate",
        join: "
            LEFT JOIN assets a ON h.asset_name = a.name
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
    ]): string
{

    $holding_name = beautifulNumber($holding['asset_name'], null);
    if ($detail_btn) {
        $detail_callback = json_encode(['view_holding' => $holding['id']], JSON_UNESCAPED_UNICODE);
        $name_html = "<tg-button type='callback_data' style='link' data='$detail_callback'>$holding_name</tg-button>";
    } else
        $name_html = "<h2>$holding_name:</h2>";

    $detail_html = '';
    foreach ($attributes as $index => $attribute) {

        $detail_html .= "<li>";

        if ($attribute == 'date' && isset($holding['date'])) {
            $date = JalaliDate::fromString($holding['date'])->toPersianMonths();
            $date_string = beautifulNumber("$date[day] $date[month] $date[year]", null);
            $detail_html .= "تاریخ خرید: " . $date_string;
        }

        if ($attribute == 'org_amount')
            $detail_html .= "مقدار / تعداد: " . beautifulNumber(floatval($holding['amount']));

        if ($attribute == 'org_price') {
            $avg_price_string = beautifulNumber(floatval($holding['avg_price']));
            $detail_html .= "قیمت خرید هر واحد: " . "$avg_price_string $holding[base_currency]";
        }

        if ($attribute == 'new_price') {
            $cur_price_string = beautifulNumber($holding['current_price']);
            $detail_html .= "قیمت لحظه‌ای هر واحد: " . "$cur_price_string $holding[base_currency]";
        }

        if ($attribute == 'org_total_price') {
            $org_total_price = beautifulNumber($holding['avg_price'] * $holding['amount']);
            $detail_html .= "قیمت خرید کل دارایی: " . "$org_total_price $holding[base_currency]";
        }

        if ($attribute == 'new_total_price') {
            $new_total_price = beautifulNumber($holding['current_price'] * $holding['amount']);
            $detail_html .= "قیمت لحظه‌ای کل دارایی: " . "$new_total_price $holding[base_currency]";
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
            $detail_html .= $pro_los_string;
        }

        $detail_html .= (array_key_last($attributes) == $index) ? "<br></li>" : "</li>";
    }

    return "<li>$name_html<ul>$detail_html</ul></li>";
}

function calculateProLos(float $p1, float $p2, float $amount = 1, float $conversion_rate = 1): float
{
    $total_price_def = $amount * ($p2 - $p1);
    return $total_price_def * $conversion_rate;
}
