<?php

use JetBrains\PhpStorm\NoReturn;

#[NoReturn]
function loans_menu(
    User            $user,
    DatabaseManager $db,
    ?array          $message = null,
    ?array          $callback_query = null): void
{

    if ($callback_query)
        handleLoansCallback($user, $callback_query, $message, $db);
    elseif (!$message)
        $user->setButton(new Button(
            id: 'loans',
            attrs: ['text' => '🏦 وام و اقساط'],
            adminKey: false,
            belongTo: 'main_menu',
            keyboard: [
                [createWebAppBtn('➕ افزودن وام جدید', '/assets/loan.html')],
                [['id' => 'main_menu', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0],],
            ]
        ))->setProgress(null);
    elseif (isset($message['web_app_data']))
        handleLoansWebAppData($user, $message, $db);
    elseif ($pressed_button_id = getPressedButtonID($message['text'], $user))
        levelHandler($user, $db, button_id: $pressed_button_id);
    else
        $data['text'] = 'پیام نامفهوم است.';

    sendInitialLevelMessage($user, $db, $data ?? null);
    if (!$message) sendAllLoans($user, $db, $user->getDetailedLoan());
    exit($user->getButton()->getText());
}

#[NoReturn]
function handleLoansCallback(
    User            $user,
    array           $callback_query,
    array           $message,
    DatabaseManager $db): void
{
    $query_data = $callback_query['data'];
    $query_key = array_key_first($query_data);

    switch ($query_key) {
        case 'loans_list':
            $user->setButton(new Button(
                id: 'loans',
                attrs: ['text' => '🏦 وام و اقساط'],
                adminKey: false,
                belongTo: 'main_menu',
                keyboard: [
                    [createWebAppBtn('➕ افزودن وام جدید', '/assets/loan.html')],
                    [['id' => 'main_menu', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0],],
                ]
            ))->setProgress(null);

            $data = [
                'chat_id' => $user->getid(),
                'text' => $user->getButton()->getText(),
                'reply_markup' => [
                    'keyboard' => $user->getKeyboard(),
                    'resize_keyboard' => true,
                    'is_persistent' => false,
                    'input_field_placeholder' => $user->getButton()->getText()
                ]
            ];
            sendToTelegram('sendMessage', $data);
            sendAllLoans($user, $db, $user->getDetailedLoan());
            sendToTelegram('deleteMessage', ['chat_id' => $user->getId(), 'message_id' => $message['message_id']]);
            break;

        case 'detailed_loans':
            sendAllLoans($user, $db, $query_data[$query_key], $message['message_id']);
            $user->setDetailedLoan($query_data[$query_key]);
            $db->update('users', $user->toDbArray(), ['id' => $user->getId()]);
            break;

        case 'view_loan':
            $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, loan_id: $query_data[$query_key]);
            if ($loan) {

                $user->setKeyboard(
                    [
                        [createWebAppBtn('✏ ویرایش وام «' . $loan['name'] . '»', '/assets/loan.html', ['data' => base64_encode(json_encode(prepareLoanForWebApp($loan)))])],
                        [createWebAppBtn('➕ افزودن وام جدید', '/assets/loan.html')],
                        [['id' => 'loans', 'text' => '🔙 برگشت 🔙', 'style' => 'primary', 'admin_key' => 0],]
                    ]
                );
                $data = [
                    'chat_id' => $user->getid(),
                    'text' => 'جزئیات وام «' . beautifulNumber($loan['name'], null) . '»',
                    'reply_markup' => [
                        'keyboard' => $user->getKeyboard(),
                        'resize_keyboard' => true,
                        'is_persistent' => false,
                        'input_field_placeholder' => $user->getButton()->getText(),
                    ]
                ];

                $response = sendToTelegram('sendMessage', $data);
                if ($response) {
                    $db->update('users', $user->setProgress(null)->toDbArray(), ['id' => $user->getId()]);
                    sendLoanDetail($user->getId(), $loan);
                    sendToTelegram('deleteMessage', ['chat_id' => $data['chat_id'], 'message_id' => $message['message_id']]);
                }
            }
            break;

        case 'inplace_inst_pay_toggle':
            inplaceInstallmentPaymentToggle($user, $callback_query['data']['inplace_inst_pay_toggle'], $message, $db);

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

function handleLoansWebAppData(
    User            $user,
    array           $message,
    DatabaseManager $db): void
{
    $web_app_data = json_decode($message['web_app_data']['data'], true);

    // Add new loan and installments
    if (isset($web_app_data['loan']) && isset($web_app_data['installments'])) {

        $new_loan = $web_app_data['loan'];
        try {

            $received_date = JalaliDate::fromString($new_loan['received_date'], '-')->toGregorian();
            $loan_id = $db->create(
                table: 'loans',
                data: [
                    'user_id' => $user->getId(),
                    'name' => $new_loan['name'],
                    'total_amount' => $new_loan['total_amount'],
                    'received_date' => $received_date->format('Y-m-d'),
                    'alert_offset' => $new_loan['alert_offset'],
                ]
            );

            $count = 0;
            foreach ($web_app_data['installments'] as $inst) {
                try {

                    $due_date = JalaliDate::fromString($inst['due_date'])->toGregorian();
                    $alert_date = clone($due_date);
                    $alert_date = $alert_date->modify("-" . $new_loan['alert_offset'] . " days");

                    $db->create(
                        table: 'installments',
                        data: [
                            'loan_id' => $loan_id,
                            'amount' => $inst['amount'],
                            'due_date' => $due_date->format('Y-m-d'),
                            'alert_date' => $alert_date->format('Y-m-d'),
                            'is_paid' => $inst['is_paid']
                        ]
                    );
                    $count++;
                } catch (Exception $e) {
                    error_log(
                        'Installment: ' . json_encode($inst) . "\n" .
                        'Error: ' . $e->getMessage()
                    );
                }
            }
            $data['text'] = "✅ وام «{$new_loan['name']}» با موفقیت ثبت شد.\n📊 تعداد اقساط: " . beautifulNumber($count);
            sendToTelegram('sendMessage', $data);
        } catch (Exception $e) {
            sendToTelegram('sendMessage', [
                'text' => '❌ خطای پایگاه داده در ثبت دارایی جدید: ' . $e->getMessage(),
                'chat_id' => $user->getid()
            ]);
            error_log(
                'Loan: ' . json_encode($new_loan) . "\n" .
                'Error: ' . $e->getMessage()
            );
        }
        sendAllLoans($user, $db, summerized: $user->getDetailedLoan());
        exit;
    }

    // Edit existing loan and related installments
    if (isset($web_app_data['id']) && isset($web_app_data['updates'])) {

        $new_insts = $web_app_data['updates']['installments'] ?? null;
        unset($web_app_data['updates']['installments']);

        $loan_modified = false;
        $data['text'] = "نتیجه ویرایش وام: ";

        if ($web_app_data['updates']) {
            try {
                $db->update(
                    table: 'loans',
                    data: $web_app_data['updates'],
                    conditions: ['id' => $web_app_data['id'], 'user_id' => $user->getId()]
                );
                $loan_modified = true;
            } catch (Exception $e) {
                error_log(
                    'Loan Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                    'Error: ' . $e->getMessage()
                );
            }
        }
        $data['text'] .= $loan_modified ? "\nویرایش اطلاعات وام: ✅" : "\nویرایش اطلاعات وام: ❌";

        if ($new_insts) {

            foreach ($new_insts as &$new_inst) {
                $alert_offset = $web_app_data['updates']['alert_offset'] ?? 3;
                $due_date = JalaliDate::fromString($new_inst['due_date'])->toGregorian();
                $alert_date = (clone $due_date)->modify("-" . $alert_offset . " days");

                $new_inst['loan_id'] = $web_app_data['id'];
                $new_inst['due_date'] = $due_date->format('Y-m-d');
                $new_inst['alert_date'] = $alert_date->format('Y-m-d');
            }

            // Update the Existing installments, based on their IDs or dates
            try {
                $db->upsertBatch(
                    table: 'installments',
                    dataRows: $new_insts
                );
                $data['text'] .= "\nویرایش اطلاعات اقساط: ✅";
            } catch (Exception $e) {
                $data['text'] .= "\nویرایش اطلاعات اقساط: ❌";
                error_log(
                    'New Installments: ' . json_encode($new_insts) . "\n" .
                    'Error: ' . $e->getMessage()
                );
            }

            // Delete redundant installments
            try {
                $deleted_rows = $db->delete(
                    table: 'installments',
                    conditions: ['loan_id' => $web_app_data['id'], '!due_date' => array_column($new_insts, 'due_date')]
                );
                $data['text'] .= "\nتعداد قسط حذف شده: " . beautifulNumber($deleted_rows);
            } catch (Exception $e) {
                $data['text'] .= "\nحذف اقساط با خطا مواجه شد! ";
                error_log(
                    'delete Installment: ' . json_encode(array_column($new_insts, 'due_date')) . "\n" .
                    'Error: ' . $e->getMessage()
                );
            }
        }

        $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, loan_id: $web_app_data['id']);
        $encoded_loan = base64_encode(json_encode(prepareLoanForWebApp($loan)));
        array_unshift(
            $data['reply_markup']['keyboard'],
            [createWebAppBtn('✏ ویرایش وام «' . $loan['name'] . '»', '/assets/loan.html', ['data' => $encoded_loan])]);
        sendToTelegram('sendMessage', $data);
        sendLoanDetail($user->getId(), $loan);
        exit;
    }

    // Delete existing loan and related installments
    if (isset($web_app_data['delete']) && $web_app_data['delete']) {

        try {
            $db->delete(
                table: 'loans',
                conditions: ['id' => $web_app_data['id']]
            );
            $data['text'] = '✅ حذف وام با موفقیت انجام شد!';
        } catch (Exception $e) {
            $data['text'] = '❌ خطای پایگاه داده در حذف وام!';
            error_log(
                'Updates: ' . json_encode($web_app_data['updates']) . "\n" .
                'Error: ' . $e->getMessage()
            );
        }

        sendToTelegram('sendMessage', $data);
        sendAllLoans($user, $db, summerized: $user->getDetailedLoan());
        exit;
    }

    error_log('Unprocessable WebApp Data Received: ' . "\n" . json_encode($web_app_data));

    $data['text'] = 'داده‌های ارسالی قابل پردازش نیستند!';
    sendToTelegram('sendMessage', $data);

    sendAllLoans($user, $db, summerized: $user->getDetailedLoan());
    exit;
}

function sendAllLoans(
    User            $user,
    DatabaseManager $db,
    bool            $summerized = true,
    string|int|null $message_id = null
): void
{

    $loans = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true);

    if ($loans) {
        $data = [
            'chat_id' => $user->getid(),
            'rich_message' => createLoansRichMessage($loans, $summerized),
            'reply_markup' => ['inline_keyboard' => [
                [[
                    'text' => $summerized ? 'نمایش توضیحات وام‌ها' : 'پنهان کردن توضیحات وام‌ها',
                    'callback_data' => json_encode(['detailed_loans' => !$summerized])
                ]], [[
                    'text' => 'اقساط ۳۰ روز آینده',
                    'callback_data' => json_encode(['insts_for_n_days' => 30])
                ]]
            ]]
        ];
        if ($message_id) {
            $data['message_id'] = $message_id;
            sendToTelegram('editMessageText', $data);
        } else
            sendToTelegram('sendRichMessage', $data);
    } else {
        if ($message_id) sendToTelegram('sendMessage', ['chat_id' => $user->getid(), 'message_id' => $message_id, 'text' => 'هیچ وام یا قسطی برای شما ثبت نشده است!']);
        else sendToTelegram('sendMessage', ['chat_id' => $user->getid(), 'text' => 'هیچ وام یا قسطی برای شما ثبت نشده است!']);
    }

    $db->update('users', $user->toDbArray(), ['id' => $user->getId()]);
}

function sendInstallmentsForNextNDays(User $user, DatabaseManager $db, int $n = 30, ?string $mssg_id_to_edit = null): bool
{
    $installments = $db->query("
        select i.*, l.name as loan_name
        from installments i
        join loans l on i.loan_id = l.id
        where
            l.user_id = " . $user->getId() . " and
            i.due_date between curdate() and date_add(curdate(), interval $n day)
        order by i.due_date
        ")->fetchAll();

    if ($installments) {
        if (!$mssg_id_to_edit) {
            $temp_mssg = sendLoadingMessage($user->getid(), 'در حال دریافت اطلاعات وام‌ها ...');
            if ($temp_mssg) $mssg_id_to_edit = $temp_mssg['result']['message_id'];
            else exit;
        }

        $keyboard = [[[
            'text' => 'لیست کامل وام‌ها',
            'callback_data' => json_encode(['loans_list' => null])
        ]]];

        $text = 'اقساط ' . beautifulNumber($n, null) . ' روز آینده' . "\n";
        foreach ($installments as $installment) {

            $due_date = JalaliDate::fromGregorianString($installment['due_date']);
            $due_today = $due_date->diffInDays(JalaliDate::fromGregorian()) == 0;

            if ($installment['is_paid']) $payment_emoji = "🟢";
            else $payment_emoji = $due_today ? "🟡" : "⚪";

            $text .= "\n" .
                $payment_emoji . ' ' . $installment['loan_name'] . ': ' .
                beautifulNumber($installment['amount']) . ' در ' .
                beautifulNumber(JalaliDate::fromGregorianString($installment['due_date'])->format(), null);
        }

        sendToTelegram('editMessageText', [
            'chat_id' => $user->getid(),
            'message_id' => $mssg_id_to_edit,
            'text' => $text,
            'parse_mode' => 'MarkdownV2',
            'reply_markup' => ['inline_keyboard' => $keyboard]
        ]);
        return true;
    } else
        return false;
}

function sendLoanDetail(string|int $chat_id, array $loan, string|int|null $message_id = null): void
{
    $data = [
        'chat_id' => $chat_id,
        'rich_message' => createLoanDetailRichMessage($loan),
        'reply_markup' => [
            'inline_keyboard' => createLoanDetailInlineKeyboard($loan['installments']),
        ]
    ];
    if ($message_id) {
        $data['message_id'] = $message_id;
        sendToTelegram('editMessageText', $data);
    } else {
        sendToTelegram('sendRichMessage', $data);
    }
}

function payInstallmentFromCronJob(User $user, array $callback_query, array $message, DatabaseManager $db): void
{
    $installment_id = $callback_query['data']['cron_inst_paid'];
    $user_id = $user->getId();
    try {
        $db->query("
        UPDATE installments i
        JOIN loans l ON i.loan_id = l.id
        SET i.is_paid = true
        WHERE i.id = $installment_id
        AND l.user_id = $user_id;
    ");
        $text = $message['text'] . "\n\n" . "✅ پرداخت قسط ثبت شد.";
    } catch (Exception $e) {
        error_log('Error adding new favorite: ' . $e->getMessage());
        $text = $message['text'] . "\n\n" . "❌ خطای پایگاه داده!";
    }

    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
    sendToTelegram('editMessageText', ['chat_id' => $user->getId(), 'text' => $text, 'message_id' => $message['message_id']]);
    exit();
}

#[NoReturn]
function inplaceInstallmentPaymentToggle(User $user, string|int $installment_id, array $message, DatabaseManager $db): void
{

    $db->query("update installments set is_paid = !is_paid where id = $installment_id")->fetch();

    $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, installment_id: $installment_id);

    if ($loan) sendLoanDetail($user->getId(), $loan, $message['message_id']);
    exit();
}
