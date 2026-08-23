<?php

function level_1(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null,
    ?string         $command_data = null): void
{
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 1], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $level_button->getId(), admin: $user->isAdmin(), db: $db);

    // Add '➕ افزودن دارایی جدید' button to the keyboard
    array_unshift($keyboard, [createWebAppBtn('➕ افزودن دارایی جدید', '/assets/holding.html', add_api: true)]);

    $data = [
        'chat_id' => $user->getid(),
        'text' => $level_button->getText(),
        'reply_markup' => [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => false,
            'input_field_placeholder' => $level_button->getText()
        ]
    ];

    if ($callback_query) handleHoldingsCallback($user, $callback_query, $data, $message, $db);
    if ($message && isset($message['web_app_data'])) handleHoldingsWebAppData($user, $data, $message, $db);
    if ($message && !isset($message['web_app_data'])) handleHoldingsTextMessage($user, $data, $message, $db);

    // Send initial message
    $response = sendToTelegram('sendMessage', $data);

    // Update user's level and progress
    if ($response) {
        $db->update('users', ['last_btn' => $level_button->getId(), 'progress' => null], ['id' => $user->getId()]);

        if ($command_data) {
            $holding = getHoldingsWithAssetDetails(['h.id' => $command_data, 'h.user_id' => $user->getId()], $db, true);
            if ($holding) sendHoldingDetail($holding, $data, $user->getBaseCurrency());
            else sendAllHoldings($user, $db, $response['result']['message_id']);
        } else sendAllHoldings($user, $db, $response['result']['message_id']);
    }

    exit;
}

function handleHoldingsCallback(
    User            $user,
    array           $callback_query,
    array           $data,
    array           $message,
    DatabaseManager $db): void
{
    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);

    $query_data = $callback_query['data'];

    $query_key = array_key_first($query_data);
    $data['message_id'] = $message['message_id'];

    switch ($query_key) {
        case 'holdings_list':
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);
            $response = sendToTelegram('sendMessage', $data);
            sendAllHoldings($user, $db, $response['result']['message_id']);
            break;

        default:
            sendToTelegram('editMessageText', [
                'chat_id' => $user->getid(),
                'message_id' => $message['message_id'],
                'text' => 'این پیام منقضی شده است.'
            ]);
            break;
    }
    exit;
}

function handleHoldingsWebAppData(User $user, array $data, array $message, DatabaseManager $db): void
{
    $web_app_data = json_decode($message['web_app_data']['data'], true);

    $action = $web_app_data['action'] ?? null;

    $expected_data = false;

    if ($action == 'add') {

        $new_holding = $web_app_data['holding'];
        try {
            $db->create(
                table: 'holdings',
                data: [
                    "user_id" => $user->getId(),
                    "asset_id" => $new_holding["asset_id"],
                    "amount" => $new_holding["amount"],
                    "avg_price" => $new_holding["avg_price"],
                    "date" => JalaliDate::fromString($new_holding["date"])->toGregorian()->format('Y-m-d'),
                    "time" => $new_holding["time"],
                    "note" => $new_holding["note"],
                ]
            );
            $data['text'] = '✅ دارایی جدید با موفقیت ثبت شد.';
        } catch (PDOException $e) {

            if ($e->errorInfo[1] == 1062) {
                /**
                 * Duplicate Entry.
                 *
                 * Informs user of existing holding, redirects
                 * them to the holding and breaks the process.
                 */

                $data['text'] = 'شما از قبل این دارایی را در سیستم ثبت کرده اید.' . "\n" .
                    'درصورت تمایل برای ثبت تغییرات، دارایی ثبت شده را ویرایش کنید.';

                sendToTelegram('sendMessage', $data);

                $holding = getHoldingsWithAssetDetails(['h.asset_id' => $new_holding["asset_id"], 'h.user_id' => $user->getId()], $db, true);
                if ($holding) {
                    $db->update(
                        table: 'users',
                        data: ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                        conditions: ['id' => $user->getId()]
                    );
                    sendHoldingDetail($holding, $data, $user->getBaseCurrency());
                }
                exit;
            }

            error_log(
                'Holding: ' . json_encode($new_holding) . "\n" .
                'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT)
            );
            $data['text'] = '❌ خطای پایگاه داده در ثبت دارایی جدید: ' . $e->errorInfo[2];
        }
        $expected_data = true;
    }
    if ($action == 'edit') {

        try {
            $updates = $web_app_data['updates'];
            if (isset($updates['date'])) $updates['date'] = JalaliDate::fromString($updates['date'])->toGregorian()->format('Y-m-d');
            $db->update(
                table: 'holdings',
                data: $updates,
                conditions: ['id' => $web_app_data['id']]
            );
            $data['text'] = '✅ دارایی با موفقیت ویرایش شد.';
        } catch (PDOException $e) {
            error_log(
                'Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT)
            );
            $data['text'] = '❌ خطای پایگاه داده در ثبت دارایی جدید: ' . $e->errorInfo[2];
        }
        $expected_data = true;
    }
    if ($action == 'delete') {

        try {
            $db->delete(
                table: 'holdings',
                conditions: ['id' => $web_app_data['id']],
                resetAutoIncrement: true
            );
            $data['text'] = '✅ دارایی با موفقیت حذف شد.';
        } catch (PDOException $e) {
            error_log(
                'Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                'Error: ' . json_encode($e->errorInfo, JSON_PRETTY_PRINT)
            );
            $data['text'] = '❌ خطای پایگاه داده درحذف دارایی: ' . $e->errorInfo[2];
        }
        $expected_data = true;
    }

    if ($expected_data) {
        // Send success/failure message
        sendToTelegram('sendMessage', $data);

        // Clear user progress and show all holdings
        $db->update('users', ['progress' => null], ['id' => $user->getId()]);
        sendAllHoldings($user, $db);
    } else {
        $data['text'] = 'داده‌های ارسالی قابل پردازش نیستند!';
        $data = checkAndAddEditHoldingButton($data, $user, $db);
        sendToTelegram('sendMessage', $data);
    }
    exit;
}

function handleHoldingsTextMessage(User $user, array $data, array $message, DatabaseManager $db): void
{

    // Show holding detail
    $matched = preg_match('/^\/start viewHolding_holdingId(\d+)(_holdingsMssgId(\d+))?(_initMssgId(\d+))?$/m', $message['text'], $matches);
    if ($matched && !empty($matches[1])) {

        $holding_id = $matches[1];

        $holding = getHoldingsWithAssetDetails(['h.id' => $holding_id, 'h.user_id' => $user->getId()], $db, true);
        if ($holding) {

            // Delete redundant messages
            if (isset($matches[5]))
                sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $matches[5]]); ######## Initial
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $matches[3]]); ############ Holdings
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]); # Deep-Link

            sendHoldingDetail($holding, $data, $user->getBaseCurrency());
            $db->update(
                table: 'users',
                data: ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                conditions: ['id' => $user->getId()]
            );
            exit;
        } else $data['text'] = 'دارایی با این مشخصه یافت نشد!';
    } else $data['text'] = 'پیام نامفهوم است!';

    // Only irreverent texts and deep-links with wrong holding id reach here.
    $data = checkAndAddEditHoldingButton($data, $user, $db);
    sendToTelegram('sendMessage', $data);
    exit;
}

function sendAllHoldings(User $user, DatabaseManager $db, int|string|null $initial_mssg_id = null): void
{
    $holdings = getHoldingsWithAssetDetails(['user_id' => $user->getId()], $db);
    if ($holdings) {
        $temp_mssg = sendLoadingMessage($user->getid(), 'در حال دریافت اطلاعات دارایی‌ها ...');
        if ($temp_mssg) {

            $text = "دارایی‌های ثبت شده‌ی شما:\n";
            $total_pro_los = 0;
            foreach ($holdings as $holding) {
                $total_pro_los += $holding['amount'] * ($holding['current_price'] - $holding['avg_price']) * $holding['exchange_rate'];
                $text .= "\n";
                $text .= createHoldingDetailText(
                    holding: $holding,
                    markdown: 'MarkdownV2',
                    user_base_currency: $user->getBaseCurrency(),
                    attributes: ['org_amount', 'org_total_price', 'profit'],
                    holding_mssg_id: $temp_mssg['result']['message_id'],
                    initial_mssg_id: $initial_mssg_id
                );
            }

            $pro_los_string =
                ($total_pro_los == 0) ?
                    "🟤 جمع سود/زیان: ۰ " . $user->getBaseCurrency() : (
                ($total_pro_los > 0) ?
                    "🟢 جمع سود: " . beautifulNumber($total_pro_los) . ' ' . $user->getBaseCurrency() :
                    "🔴 جمع ضرر: " . beautifulNumber($total_pro_los) . ' ' . $user->getBaseCurrency()
                );
            $text .= "\n" . markdownScape($pro_los_string);

            sendToTelegram('editMessageText', [
                'chat_id' => $user->getid(),
                'message_id' => $temp_mssg['result']['message_id'],
                'text' => $text,
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    } else {
        sendToTelegram('sendMessage', ['chat_id' => $user->getid(), 'text' => 'شما هیچ دارایی‌ای ثبت نکرده‌اید.']);
    }
}

/**
 * Automatically adds edit button to the message.
 *
 * @param array $holding
 * @param array $data
 * @param string $user_base_currency
 * @return void
 */
function sendHoldingDetail(array $holding, array $data, string $user_base_currency = 'ریال'): void
{
    $data['text'] = "/holding_$holding[id]\n";
    $data['text'] .= 'جزئیات دارایی «' . $holding['asset_name'] . '»';

    array_unshift($data['reply_markup']['keyboard'], [
        createWebAppBtn(
            text: '✏ ویرایش ' . beautifulNumber($holding['asset_name'], null),
            path: '/assets/holding.html',
            params: ['holding' => base64_encode(json_encode($holding))],
            add_api: true
        )
    ]);

    sendToTelegram('sendMessage', $data);

    $temp_mssg = sendLoadingMessage($data['chat_id'], 'در حال دریافت اطلاعات دارایی ' . $holding['asset_name'] . ' ...');
    if ($temp_mssg) {

        $data['message_id'] = $temp_mssg['result']['message_id'];
        $data['text'] = createHoldingDetailText($holding, user_base_currency: $user_base_currency);
        //        $data['parse_mode'] = 'MarkdownV2';
        $data['reply_markup'] = ['inline_keyboard' => [[['text' => 'برگشت به لیست دارایی‌ها', 'callback_data' => json_encode(['holdings_list' => null])]]]];

        sendToTelegram('editMessageText', $data);
    }
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
