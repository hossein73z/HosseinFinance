<?php

function level_1(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null,
    ?string         $command_data = null): void
{
    // Create keyboards
    $level_button = $level_button ?: $user->getButton();
    $keyboard = refineKeyboardForTelegram($level_button->getKeyboard());

    $data = [
        'chat_id' => $user->getid(),
        'text' => $level_button->getText(),
        'reply_markup' => [
            'keyboard' => &$keyboard,
            'resize_keyboard' => true,
            'is_persistent' => false,
            'input_field_placeholder' => $level_button->getText()
        ]
    ];

    // Add '➕ افزودن دارایی جدید' button to the keyboard
    array_unshift($keyboard, [createWebAppBtn('➕ افزودن دارایی جدید', '/assets/holding.html', add_api: true)]);

    if ($callback_query) handleHoldingsCallback($user, $callback_query, $data, $message, $db);
    if ($message && isset($message['web_app_data'])) handleHoldingsWebAppData($user, $data, $message, $db);
    if ($message && !isset($message['web_app_data'])) handleHoldingsTextMessage($user, $data, $message, $db);

    // Update user's level and progress
    $db->update('users', ['button' => json_encode($level_button), 'progress' => null], ['id' => $user->getId()]);

    if ($command_data) {
        $holding = getHoldingsWithAssetDetails(['h.id' => $command_data, 'h.user_id' => $user->getId()], $db, true);
        if ($holding) sendHoldingDetail($holding, $data, $user->getBaseCurrency());
    }
    if (!$command_data) {
        sendAllHoldings($user, $db, $data);
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

    $query_data = $callback_query['data'];

    $query_key = array_key_first($query_data);
    $data['message_id'] = $message['message_id'];

    switch ($query_key) {

        case 'show_holding':
            $holding_id = $query_data[$query_key];
            $holding = getHoldingsWithAssetDetails(['h.id' => $holding_id], $db, true);
            if ($holding) {
                sendHoldingDetail($holding, $data, $user->getBaseCurrency());
                $db->update(
                    table: 'users',
                    data: ['progress' => json_encode(['view_holding' => ['holding_id' => $holding['id']]])],
                    conditions: ['id' => $user->getId()]
                );
                sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
                exit;
            }
            break;

        case 'holdings_list':
            $db->update('users', ['progress' => null], ['id' => $user->getId()]);
            sendAllHoldings($user, $db, $data);
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
            exit();

        default:
            sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id'], 'text' => 'این پیام منقضی شده است!']);
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);
            exit();
    }

    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
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
        sendAllHoldings($user, $db, $data);
    } else {
        $data['text'] = 'داده‌های ارسالی قابل پردازش نیستند!';
        $data = checkAndAddEditHoldingButton($data, $user, $db);
        sendToTelegram('sendMessage', $data);
    }
    exit;
}

function handleHoldingsTextMessage(User $user, array $data, array $message, DatabaseManager $db): void
{
    $data['text'] = 'پیام نامفهوم است!';
    sendToTelegram('sendMessage', $data);
    exit;
}
