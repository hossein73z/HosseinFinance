<?php

function level_2(
    User            $user,
    DatabaseManager $db,
    ?Button         $level_button = null,
    ?array          $message = null,
    ?array          $callback_query = null,
    ?string         $command_data = null
): void {
    // Initialize button object if null is given
    $level_button = $level_button ?? Button::fromDbRow($db->read('buttons', ['id' => 2], true));

    // Create keyboards
    $keyboard = createKeyboardsArray(parent_btn_id: $level_button->getId(), admin: $user->isAdmin(), db: $db);

    // Add '➕ افزودن وام جدید' button to the keyboard
    array_unshift($keyboard, [createWebAppBtn('➕ افزودن وام جدید', '/assets/loan.html')]);

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

    if ($callback_query) handleLoansCallback($user, $callback_query, $data, $message, $db);
    if ($message && isset($message['web_app_data'])) handleLoansWebAppData($user, $data, $message, $db);
    if ($message && !isset($message['web_app_data'])) handleLoansTextMessage($user, $data, $message, $db);

    // Send initial message
    $response = sendToTelegram('sendMessage', $data);

    // Update user's level and progress
    if ($response) {
        $db->update('users', ['last_btn' => $level_button->getId(), 'progress' => null], ['id' => $user->getId()]);
        if ($command_data) {
            $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, loan_id: $command_data);
            if ($loan) sendLoanDetail($loan, $data, $response['result']['message_id']);
        }
        sendAllLoans($user, $db, null, null, $user->getDetailedLoan());
    }

    exit;
}

function handleLoansCallback(User $user, array $callback_query, array $data, array $message, DatabaseManager $db): void
{
    $query_data = $callback_query['data'];

    $query_key = array_key_first($query_data);
    $data['message_id'] = $message['message_id'];

    sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);

    switch ($query_key) {
        case 'loans_list':
            sendToTelegram('deleteMessage', ['chat_id' => $user->getId(), 'message_id' => $message['message_id']]);
            sendToTelegram('sendMessage', $data);
            sendAllLoans($user, $db, null, null, $user->getDetailedLoan());
            break;

        case 'detailed_loans':
            sendAllLoans($user, $db, null, $message['message_id'], $query_data[$query_key]);
            $user->setDetailedLoan($query_data[$query_key]);
            $db->update('users', ['settings' => json_encode($user->getSettings())], ['id' => $user->getId()]);
            break;

        case 'view_loan':
            $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, loan_id: $query_data[$query_key]);
            if ($loan) {

                $data['text'] = 'جزئیات وام «' . beautifulNumber($loan['name'], null) . '»';
                array_unshift($data['reply_markup']['keyboard'], [createWebAppBtn('✏ ویرایش وام «' . $loan['name'] . '»', '/assets/loan.html', ['data' => base64_encode(json_encode(prepareLoanForWebApp($loan)))])]);

                $response = sendToTelegram('sendMessage', $data);
                if ($response) {
                    $db->update(
                        table: 'users',
                        data: ['progress' => json_encode(['view_loan' => ['loan_id' => $loan['id']]])],
                        conditions: ['id' => $user->getId()]
                    );
                    sendToTelegram('deleteMessage', ['chat_id' => $data['chat_id'], 'message_id' => $message['message_id']]);
                    sendLoanDetail($loan, $data);
                }
            }
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

function handleLoansWebAppData(User $user, array $data, array $message, DatabaseManager $db): void
{
    $web_app_data = json_decode($message['web_app_data']['data'], true);
    // Add new loan and installments
    if (
        isset($web_app_data['loan']) &&
        isset($web_app_data['installments'])
    ) {

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
    if (
        isset($web_app_data['id']) &&
        isset($web_app_data['updates'])
    ) {

        $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, loan_id: $web_app_data['id']);

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
                $alert_offset = $web_app_data['updates']['alert_offset'] ?? $loan['alert_offset'];
                $new_inst['loan_id'] = $web_app_data['id'];
                $new_inst['due_date'] = JalaliDate::fromString($new_inst['due_date'])->toGregorian()->format('Y-m-d');
                $new_inst['alert_date'] = JalaliDate::fromGregorianString($new_inst['due_date'])->toGregorian()->modify("-" . $alert_offset . " days")->format('Y-m-d');
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

        array_unshift($data['reply_markup']['keyboard'], [createWebAppBtn('✏ ویرایش وام «' . $loan['name'] . '»', '/assets/loan.html', ['data' => base64_encode(json_encode(prepareLoanForWebApp($loan)))])]);
        sendToTelegram('sendMessage', $data);
        sendLoanDetail($loan, $data);
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

function handleLoansTextMessage(User $user, array $data, array $message, DatabaseManager $db): void
{

    // Show loan detail
    $matched = preg_match("/^\/start showLoan_loanId(\d+?)(_loansMssgId(\d+?))?(_initMssgId(\d+?))?$/m", $message['text'], $matches);
    if ($matched && !empty($matches[1])) {

        $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, loan_id: $matches[1]);

        if ($loan) {
            /** else: Send default Irrelevance message */

            // Delete redundant messages
            if (isset($matches[5]))
                sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $matches[5]]); ######## Initial
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $matches[3]]); ############ Loans View
            sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]); # Deep-Link

            $data['text'] = 'جزئیات وام «' . beautifulNumber($loan['name'], null) . '»';
            array_unshift($data['reply_markup']['keyboard'], [createWebAppBtn('✏ ویرایش وام «' . $loan['name'] . '»', '/assets/loan.html', ['data' => base64_encode(json_encode(prepareLoanForWebApp($loan)))])]);

            $response = sendToTelegram('sendMessage', $data);
            if ($response) {
                $db->update(
                    table: 'users',
                    data: ['progress' => json_encode(['view_loan' => ['loan_id' => $loan['id']]])],
                    conditions: ['id' => $user->getId()]
                );
                sendLoanDetail($loan, $data);
            }
        }
    }

    // Toggle Installment Payment
    $matched = preg_match("/^\/start toggleInstPayment_instId(\d+?)_mssgId(\d+?)$/m", $message['text'], $matches);
    if ($matched && !empty($matches[1])) {

        // Delete deep-link message
        sendToTelegram('deleteMessage', ['chat_id' => $user->getid(), 'message_id' => $message['message_id']]);

        $installment = $db->read(
            table: 'installments i',
            conditions: ['i.id' => $matches[1], 'l.user_id' => $user->getId()],
            single: true,
            selectColumns: 'i.*, l.user_id',
            join: 'LEFT JOIN loans l ON i.loan_id = l.id'
        );

        if ($installment) {
            /** else: Default Irrelevance message will be sent */

            $db->update(
                table: 'installments',
                data: ['is_paid' => !$installment['is_paid']],
                conditions: ['id' => $installment['id']]
            );

            $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, loan_id: $installment['loan_id']);

            if ($loan) {
                sendToTelegram('editMessageText', [
                    'chat_id' => $user->getid(),
                    'message_id' => $matches[2],
                    'text' => createLoanDetailText($loan, 'MarkdownV2', $matches[2]),
                    'parse_mode' => 'MarkdownV2',
                    'reply_markup' => ['inline_keyboard' => createLoanDetailKeyboard($loan)]
                ]);
            }
            exit;
        }
    }

    /**
     * Add '✏ ویرایش' button to the keyboard if usee is viewing a loan.
     * This works with irreverent texts and wrong loan or installment id.
     */
    $progress = $user->getProgress();
    if ($progress && key($progress) === 'view_loan') {
        $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, loan_id: $progress['view_loan']['loan_id']);
        if ($loan) {
            array_unshift($data['reply_markup']['keyboard'], [
                createWebAppBtn(
                    text: '✏ ویرایش وام «' . $loan['name'] . '»',
                    path: '/assets/loan.html',
                    params: ['data' => base64_encode(json_encode(prepareLoanForWebApp($loan)))]
                )
            ]);
        }
    }

    $data['text'] = 'پیام نامفهوم است!';
    sendToTelegram('sendMessage', $data);
    exit;
}

function sendAllLoans(User $user, DatabaseManager $db, ?string $initial_mssg_id = null, ?string $mssg_id_to_edit = null, bool $summerized = true): void
{

    $loans = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true);

    if ($loans) {
        if (!$mssg_id_to_edit) {
            $temp_mssg = sendLoadingMessage($user->getid(), 'در حال دریافت اطلاعات وام‌ها ...');
            if ($temp_mssg) $mssg_id_to_edit = $temp_mssg['result']['message_id'];
            else exit;
        }

        $keyboard = [[[
            'text' => $summerized ? 'نمایش توضیحات وام‌ها' : 'پنهان کردن توضیحات وام‌ها',
            'callback_data' => json_encode(['detailed_loans' => !$summerized])
        ]]];
        $keyboard[] = [[
            'text' => 'اقساط ۳۰ روز آینده',
            'callback_data' => json_encode(['insts_for_n_days' => 30])
        ]];
        $keyboard_row = [];
        $btn_in_row = 3;
        foreach ($loans as $loan) {
            $keyboard_row[] = [
                'text' => beautifulNumber($loan['name'], null),
                'callback_data' => json_encode(['view_loan' => $loan['id']])
            ];

            if (sizeof($keyboard_row) >= $btn_in_row) {
                $keyboard[] = $keyboard_row;
                $keyboard_row = [];
            }
        }
        if ($keyboard_row) $keyboard[] = $keyboard_row;

        sendToTelegram('editMessageText', [
            'chat_id' => $user->getid(),
            'message_id' => $mssg_id_to_edit,
            'text' => createLoansView($loans, $mssg_id_to_edit, $initial_mssg_id, $summerized),
            'parse_mode' => 'MarkdownV2',
            'reply_markup' => ['inline_keyboard' => $keyboard]
        ]);
    } else {
        sendToTelegram('sendMessage', ['chat_id' => $user->getid(), 'text' => 'هیچ وام یا قسطی برای شما ثبت نشده است!']);
    }

    $db->update('users', ['progress' => null], ['id' => $user->getId()]);
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
        order by i.due_date asc
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

function sendLoanDetail(array $loan, array $data, string|int|null $mssg_id_to_edit = null): void
{

    if (!$mssg_id_to_edit) {
        $temp_mssg = sendLoadingMessage($data['chat_id'], 'در حال دریافت اطلاعات اقساط ...');
        if ($temp_mssg) $mssg_id_to_edit = $temp_mssg['result']['message_id'];
        else exit;
    }

    $data['message_id'] = $mssg_id_to_edit;
    $data['text'] = createLoanDetailText($loan, 'MarkdownV2', $mssg_id_to_edit);
    $data['parse_mode'] = 'MarkdownV2';
    $data['reply_markup'] = ['inline_keyboard' => createLoanDetailKeyboard($loan)];

    sendToTelegram('editMessageText', $data);

    exit;
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

function inplaceInstallmentPaymentToggle(User $user, array $callback_query, array $message, DatabaseManager $db): void
{

    $installment_id = $callback_query['data']['inplace_inst_pay_toggle'];

    // FIXME: Duplicate code fragment
    $db->query("update installments set is_paid = !is_paid where id = $installment_id")->fetch();

    $loan = getLoanWithInstallments(user_id: $user->getId(), db: $db, jalali: true, installment_id: $installment_id);

    if ($loan) {
        sendToTelegram('editMessageText', [
            'chat_id' => $user->getid(),
            'message_id' => $message['message_id'],
            'text' => createLoanDetailText($loan, 'MarkdownV2', $message['message_id']),
            'parse_mode' => 'MarkdownV2',
            'reply_markup' => ['inline_keyboard' => createLoanDetailKeyboard($loan)]
        ]);
    }
    exit();
}
