<?php

require_once 'bootstrap.php';

// Setup Webhook Response
header('Content-Type: application/json');
$input = file_get_contents('php://input');

validateWebhookSecurity($input);
http_response_code(200);

if (empty($input)) {
    error_log("[WARN] No input data received via Webhook.");
    exit;
}

$update = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("[ERROR] Invalid JSON received: " . json_last_error_msg());
    exit;
}

// --- MAIN UPDATE ROUTER ---

if (isset($update['message'])) handleIncomingMessage($update['message'], $db);
elseif (isset($update['callback_query'])) handleCallbackQuery($update['callback_query'], $db);
else error_log("[INFO] Unhandled update type received.");

DatabaseManager::closeConnection();
exit;


// ==========================================
//          LEVEL 2: LOANS & INSTALLMENTS
// ==========================================

function prepareLoanForWebApp(array $loan): array
{
    unset($loan['user_id']);
    unset($loan['created_at']);
    foreach ($loan['installments'] as &$installment) {
        unset($installment['loan_id']);
        unset($installment['alert_date']);
        unset($installment['is_due']);
        unset($installment['remaining_days']);
    }

    return $loan;
}

// ==========================================
//          Admin
// ==========================================

function setBaseCurrency(User $user, array $callback_query, array $message, DatabaseManager $db): void
{
    $data = [
        'chat_id' => $user->getid(),
        'message_id' => $message['message_id'],
        'text' => 'این پیام منقضی شده است.'
    ];

    $query_data = $callback_query['data'];

    $query_key = array_key_first($query_data);
    if ($query_key == 'set_base_currency') {

        $user->setBaseCurrency($query_data['set_base_currency']);
        try {
            $db->update(
                table: 'users',
                data: ['settings' => json_encode($user->getSettings())],
                conditions: ['id' => $user->getId()],
            );
            $data['text'] = '✅ ارز پایه با موفقیت به «' . $query_data['set_base_currency'] . '» تغییر کرد';
        } catch (Exception $e) {
            error_log('Error changing base currency: ' . $e->getMessage());
            $data['text'] = '❌ خطای پایگاه داده!';
        }

        sendToTelegram('answerCallbackQuery', ['callback_query_id' => $callback_query['id']]);
        sendToTelegram('editMessageText', $data);
        exit;
    }

    sendToTelegram('editMessageText', $data);
    exit;
}

function sendSelectBaseCurrencyMessage(User $user, DatabaseManager $db): void
{
    $base_currencies = $db->read(
        table: 'assets',
        conditions: ['asset_type' => 'ارزهای آزاد'],
        selectColumns: 'name',
    );

    if ($base_currencies) {

        $base_currencies = array_column($base_currencies, 'name');

        $keyboard = [];
        foreach ($base_currencies as $base_currency)
            if ($base_currency != $user->getBaseCurrency())
                $keyboard[] = [['text' => $base_currency, 'callback_data' => json_encode(['set_base_currency' => $base_currency])]];

        $data = [
            'reply_markup' => ['inline_keyboard' => $keyboard],
            'text' => 'ارز پایه کنونی شما: ' . $user->getBaseCurrency() . "\n" . 'شما می‌توانید از طریق دکمه‌های شیشه‌ای زیرو ارز پایه‌ی خود را تغییر دهید.',
            'chat_id' => $user->getid()
        ];

        sendToTelegram('sendMessage', $data);
    }
    exit;
}

// ==========================================
//          LEVEL 11: Transactions
// ==========================================

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

// ==========================================
//          LEVEL S3: EMPTY LEVEL
// ==========================================

function empty_level(
    User            $user,
    DatabaseManager $db,
    string|int      $parent_btn_id = 0, // Required to avoid `null` progress bug
    ?array          $message = null,
): void {
    $progress = $user->getProgress();

    if (!$progress) backButton($user, $db, $parent_btn_id);

    // NOTE: Text and keyboard must be initialized within progress handler
    $text = '';
    $$keyboard = [];
    $data = [
        'chat_id' => $user->getid(),
        'text' => &$text,
        'reply_markup' => [
            'keyboard' => [&$keyboard],
            'resize_keyboard' => true,
            'is_persistent' => false
        ]
    ];

    $parent_level = $progress['parent_btn'];
    $progress_data = $progress['data'];

    ##########################
    ##   Progress Handler   ##
    ##########################

    if (array_key_first($progress_data) == 'set_alert') {

        // Create bottom keyboard with just cancel button
        $button = $db->read('buttons', ['id' => ['s1']], true);
        $keyboard[] = json_decode($button['attrs'], true);

        $asset_id = $progress_data['set_alert']['asset_id'];

        // Just entered the level
        // Ask user to give alert's target price
        if (!$message) {

            $asset = $db->read('assets', ['id' => $asset_id], true);

            $text = 'قیمتی که می‌خواهید برای آن هشدار تنظیم کنید را نوشته و ارسال کتید.';
            $text .= "\n";
            $text .= '*قیمت کنونی «' . beautifulNumber($asset['name'], null) . '»*: ';
            $text .= beautifulNumber($asset['price']) . ' ' . beautifulNumber($asset['base_currency'], null);
            $text = markdownScape($text);

            $data['parse_mode'] = 'MarkdownV2';

            // Update user last button to current level (s3)
            $db->update('users', $user->setLastBtn('s3')->toDbArray(), ['id' => $user->getId()]);
        }

        // Received message (Supposed to be alert's target price)
        if ($message) {

            // Check if Received text is cancel button
            $pressed_button = $db->read('buttons', ['id' => 's1', 'attrs->>"$.text"' => $message['text']]);
            if ($pressed_button) backButton($user, $db, $parent_level);

            // Check if received text is a valid number
            $target_price = cleanAndValidateNumber($message['text']);
            if ($target_price) {

                // Read asset from database for price comparison
                $asset = $db->read('assets', ['id' => $asset_id], true);
                $price_diff = floatval($target_price) - floatval($asset['price']);
                $diff_percent = intval(($price_diff / floatval($asset['price'])) * 100);

                // Check if received price different from current price
                if ($price_diff != 0) {

                    $result = $db->upsert('alerts', [
                        'user_id' => $user->getId(),
                        'asset_name' => $asset['name'],
                        'target_price' => $target_price,
                        'is_active' => true,
                        'created_date' => JalaliDate::fromGregorian()->format(),
                        'created_time' => date('H:i')
                    ]);
                    if ($result) {

                        $text = '✅ هشدار قیمت برای «' . beautifulNumber($asset['name'], null) . '» با موفقیت ثبت شد!';
                        $text .= "\n" . 'قیمت کنونی: ' . beautifulNumber($asset['price']);
                        $text .= "\n" . 'قیمت هشدار: ' . beautifulNumber($target_price);
                        $text .= "\n" . 'اختلاف قیمت: ' . ($price_diff > 0 ? '➕' : '➖');
                        $text .= ' ' . beautifulNumber(abs($price_diff));
                        $text .= ' (' . beautifulNumber($diff_percent) . '%)';
                    } else $text = '❌ خطای پایگاه داده!';

                    // Send success/failure message and go back to parent level
                    sendToTelegram('sendMessage', $data);
                    backButton($user, $db, $parent_level);
                } else // Send warning: Received number is the same as current price
                    $text = "قیمت هشدار نمی‌تواند با قیمت کنونی برابر باشد." . "\n" .
                        "قیمت دیگری بنویسید یا در صورت انصراف از دکمه لغو استفاده کنید.";
            } else // Send warning: Received text does not contain a valid number
                $text = "پیام نامفهوم بود." . "\n" .
                    "قیمت را به عدد بنویسید یا در صورت انصراف از دکمه لغو استفاده کنید.";
        }

        // Send default progress related text and bottom keyboard
        // NOTE: Entering level or Wrong number format reaches here
        sendToTelegram('sendMessage', $data);
        exit;
    }
    exit;
}


// ==========================================
//          DATA HANDLING & UI HELPERS
// ==========================================

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

/**
 * Retrieves loans with their related installments for a specific user.
 *
 * Key Features:
 * - Returns all user loans with installments nested under `installments` key
 * - Supports both Gregorian and Jalali date formats (configurable)
 * - Provides comprehensive installment status information
 * - Includes smart sorting based on payment urgency
 *
 * Data Structure Details:
 * - Loans are sorted by remaining days to next payment (soonest first)
 * - All date fields are returned as strings in requested format (Gregorian/Jalali)
 * - Each installment includes:
 *   - Standard fields (id, amount, dates)
 *   - `is_due` boolean flag indicating overdue status
 *   - `is_paid` boolean flag for payment status
 *
 * - Each loan includes:
 *   - `next_payment`: DateTime object of next due date (null if all paid/overdue)
 *   - `insts_summary`: Payment statistics containing:
 *     - Count and sum of paid installments as `paid_count` and `paid_sum`
 *     - Count and sum of overdue installments as `overdue_count` and `overdue_sum`
 *     - Count and sum of remaining (future) installments as `remaining_count` and `remaining_sum`
 *
 * Filtering Options:
 * - Can retrieve all user loans (default)
 * - Can filter by specific loan ID
 * - Can filter by specific installment ID (returns parent loan)
 *
 * @param int|string $user_id The user ID to retrieve loans for
 * @param DatabaseManager $db Database connection instance
 * @param bool $jalali Whether to return dates in Jalali format (default: false)
 * @param int|string|null $loan_id Optional loan ID filter
 * @param int|string|null $installment_id Optional installment ID filter
 * @return bool|array Returns:
 *   - Single loan array when filtered by loan_id/installment_id
 *   - Array of loans sorted by payment urgency
 *   - false on error
 */
function getLoanWithInstallments(int|string $user_id, DatabaseManager $db, bool $jalali = false, int|string|null $loan_id = null, int|string|null $installment_id = null): bool|array
{
    if ($loan_id) $loan_select = "l.id = $loan_id and";
    elseif ($installment_id) $loan_select = "l.id = (select loan_id from installments where id = $installment_id) and";
    else $loan_select = '';

    $query = $db->query("
            select
                l.*,
                CONCAT('[',
                    GROUP_CONCAT(
                        JSON_OBJECT(
                            'id', i.id,
                            'loan_id', i.loan_id,
                            'amount', i.amount,
                            'due_date', i.due_date,
                            'alert_date', i.alert_date,
                            'is_paid', i.is_paid
                        ) ORDER BY due_date ASC
                    ),
                ']') AS installments
            from loans l
            LEFT JOIN installments i on i.loan_id = l.id
            where
                $loan_select
                l.user_id = $user_id
            group by l.id
            ");

    function prepareLoan(array $loan, bool $jalali): array
    {
        // Decode installments JSON into an array of installments
        $loan['installments'] = json_decode($loan['installments'], true);
        if ($loan['installments'][0]['id'] == null) $loan['installments'] = null;

        // Convert received date to Jalali
        if ($jalali) $loan['received_date'] = JalaliDate::fromGregorianString($loan['received_date'])->format();

        if ($loan['installments']) {
            $loan['next_installment'] = null;
            $loan['insts_summary']['paid_count'] = 0;
            $loan['insts_summary']['paid_sum'] = 0;
            $loan['insts_summary']['overdue_count'] = 0;
            $loan['insts_summary']['overdue_sum'] = 0;
            $loan['insts_summary']['remaining_count'] = 0;
            $loan['insts_summary']['remaining_sum'] = 0;
            foreach ($loan['installments'] as &$installment) {

                // Create `due_date` gregorian object just for calculations
                $due_date = DateTime::createFromFormat('Y-m-d', $installment['due_date'])->setTime(0, 0, 0);

                // Create `is_due` and `is_paid` boolean values
                $is_paid = boolval($installment['is_paid']);
                $remaining_days = (new DateTime('today'))->diff($due_date);
                $is_due = $remaining_days->days === 0 || $remaining_days->invert;

                // Initialize installments' summary
                if ($is_paid) $summary_key_word = 'paid';
                elseif ($is_due) $summary_key_word = 'overdue';
                else $summary_key_word = 'remaining';

                // Add installments' summary to loan object
                $loan['insts_summary'][$summary_key_word . '_count'] += 1;
                $loan['insts_summary'][$summary_key_word . '_sum'] += $installment['amount'];

                // Add `is_due` and `is_paid` to the installment
                $installment['is_due'] = $is_due;
                $installment['is_paid'] = $is_paid;
                $installment['remaining_days'] = ($is_due ? -1 : 1) * $remaining_days->days;

                // Store next installment
                // NOTE: Due date is stored as Gregorian object
                if ($loan['next_installment'] === null && $installment['remaining_days'] >= 0 && !$is_paid) {
                    $loan['next_installment'] = $installment;
                    $loan['next_installment']['due_date'] = $due_date;
                }

                // Change dates to Jalali string
                if ($jalali) {
                    $installment['due_date'] = JalaliDate::fromGregorianString($installment['due_date'])->format();
                    $installment['alert_date'] = JalaliDate::fromGregorianString($installment['alert_date'])->format();
                }
            }
        }
        return $loan;
    }

    if ($loan_id || $installment_id) {
        $loan = $query->fetch();
        if ($loan) $loan = prepareLoan($loan, $jalali);
        return $loan;
    } else {
        $loans = $query->fetchAll();
        if ($loans) foreach ($loans as &$loan) $loan = prepareLoan($loan, $jalali);
        try {
            usort($loans, function ($a, $b) {
                if ($a['next_installment'] == null) return 1;
                elseif ($b['next_installment'] == null) return -1;
                else return $a['next_installment']['remaining_days'] <=> $b['next_installment']['remaining_days'];
            });
        } catch (Exception $e) {
            error_log('Error sorting loans: ' . $e->getMessage());
        }
        return $loans;
    }
}

function createWebAppBtn(string $text, string $path, array $params = [], bool $add_api = false): array
{
    $url = BASE_URL . $path;
    if ($add_api) {
        $params['api_url'] = BASE_URL . '/api/ExternalConnections/api.php';
        $params['api_key'] = DB_API_SECRET;
    }

    return [
        'text' => $text,
        'web_app' => ['url' => $url . '?' . http_build_query($params)]
    ];
}

/**
 * Finds user's **active** live message in the database with `$message_id`
 * different from the one provided, and sends delete request to telegram.
 **/
function deleteOldActiveLiveMessage(User $user, int|string $message_id, DatabaseManager $db): bool|array
{
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

// ==========================================
//  TEXT FORMATTING AND MATHEMATICAL HELPERS
// ==========================================

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
): string {
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

/**
 * Considerations for `$loans` array:
 *  -- Each loan must have all related
 *     installments under `installments` column.
 *  -- All dates (loans' received date and installments'
 *     due and alert date) must be in Jalali string.
 *  -- Installments must be sorted ascending by their duedate.
 *  -- Installments must have 'is_due' bool value.
 */
function createLoansView(array $loans, ?string $loans_mssg_id = null, ?string $initial_mssg_id = null, bool $summerized = true): string
{

    $text = 'وام‌های ثبت شده‌ی شما: ' . "\n";

    $paid_total = 0;
    $overdue_total = 0;
    $remaining_total = 0;

    foreach ($loans as $loan) {

        // Create installments' view and detail
        $installments = &$loan['installments'];
        if ($installments) {

            // Create payment status icon for the installment
            $insts_per_year = [];
            $summerized_insts_text = '‏';
            foreach ($installments as $installment) {

                $due_date = JalaliDate::fromString($installment['due_date']);

                if ($summerized) {
                    if ($installment['is_paid']) $summerized_insts_text .= "🟢";
                    elseif ($installment['is_due']) $summerized_insts_text .= $installment['remaining_days'] == 0 ? "🟡" : "🔴";
                    else $summerized_insts_text .= "⚪";
                } else {
                    $due_year = $due_date->jy;
                    if ($installment['is_paid']) $insts_per_year[$due_year][] = "🟢";
                    elseif ($installment['is_due']) $insts_per_year[$due_year][] = $installment['remaining_days'] == 0 ? "🟡" : "🔴";
                    else $insts_per_year[$due_year][] = "⚪";
                }
            }

            $last_year = array_key_last($insts_per_year);
            $installments_detail = "\n‏      ┘─ وضعیت اقساط\: ";
            foreach ($insts_per_year as $year => $year_installments) {
                $prefix = ($year != $last_year) ?
                    "\n‏          ┤─ " :
                    "\n‏          ┘─ ";
                $installments_detail .= $prefix . beautifulNumber($year, null) . '\: ' . implode('', $year_installments);
            }
        } else {
            $installments_detail = '';
            $summerized_insts_text = '';
        }

        $deep_link = "https://t.me/" . BOT_ID . "?start=showLoan_loanId{$loan['id']}" . ($loans_mssg_id ? "_loansMssgId" . $loans_mssg_id : '') . ($initial_mssg_id ? "_initMssgId" . $initial_mssg_id : '');
        $loan_name = "\n‏" . "\-* [" . beautifulNumber($loan['name'], null) . "]($deep_link)*";

        if (isset($loan['next_installment'])) {

            // Add to total installments' report if the loan is not finished
            $paid_total += $loan['insts_summary']['paid_sum'];
            $overdue_total += $loan['insts_summary']['overdue_sum'];
            $remaining_total += $loan['insts_summary']['remaining_sum'];

            // Create text for the next payment
            $next_installment = $loan['next_installment'];
            $remaining_days = $next_installment['remaining_days'];
            $next_payment_text =
                $remaining_days == 0 ?
                beautifulNumber($next_installment['amount']) . ' ریال برای امروز' : ($remaining_days == 1 ? beautifulNumber($next_installment['amount']) . ' ریال برای فردا' :
                    beautifulNumber($next_installment['amount']) . ' ریال برای ' . $remaining_days . ' روز دیگر');
        } else $next_payment_text = 'پایان یافته';

        if (!$summerized) {
            $detail =
                "\n‏      │  " .
                "\n‏      ┤─ " . 'مبلغ وام: ' . beautifulNumber($loan['total_amount']) .
                "\n‏      ┤─ " . 'تاریخ دریافت: ' . beautifulNumber($loan['received_date'], null) .
                "\n‏      ┤─ " . 'قسط بعدی: ' . beautifulNumber($next_payment_text, null);

            $detail .= $installments_detail . "\n";
        } else
            $detail = ': ' . beautifulNumber($next_payment_text, null) . "\n" . $summerized_insts_text . "\n";

        $text .= $loan_name . markdownScape($detail);
    }

    $total_report_text =
        "خلاصه وضعیت اقساط وام‌های جاری: " . "\n" .
        "    - 🟢 " . "جمع اقساط پرداخت شده: " . beautifulNumber($paid_total) . "\n" .
        "    - 🔴 " . "جمع اقساط معوق: " . beautifulNumber($overdue_total) . "\n" .
        "    - ⚪ " . "جمع اقساط سررسید نشده: " . beautifulNumber($remaining_total);

    return markdownScape($total_report_text) . "\n\n" . $text;
}

/**
 * Generates a formatted loan details text with installment information.
 *
 * Requirements for the `$loan` array structure:
 * - Must contain all related installments under the 'installments' key
 * - All dates (loan received date and installment due/alert dates) must be in Jalali string format
 * - Installments must be sorted in ascending order by due date
 * - Each installment must include an 'is_due' boolean flag
 *
 * @param array $loan The loan data array containing loan details and installments
 * @param string|null $markdown Optional Markdown formatting flag
 * @param string|null $mssg_id Optional message ID for payment toggle links
 * @return string Formatted loan details text
 */
function createLoanDetailText(array $loan, ?string $markdown = null, ?string $mssg_id = null): string
{
    $installments = &$loan['installments'];
    if ($installments) {

        $installments_text = '';
        foreach ($installments as $i => $installment) {

            // Create payment status emoji
            if ($installment['is_paid']) $payment_emoji = "🟢";
            elseif ($installment['is_due']) $payment_emoji = $installment['remaining_days'] == 0 ? "🟡" : "🔴";
            else $payment_emoji = "⚪";

            // Create installment text
            $inst_num = beautifulNumber(intval($i) + 1, null);
            $date = beautifulNumber($installment['due_date'], null);
            $amount = beautifulNumber($installment['amount']);

            if ($markdown) {
                $link = "https://t.me/" . BOT_ID . "?start=toggleInstPayment_instId$installment[id]_mssgId$mssg_id";
                $installments_text .= "\n" . '‏' . '    ' . markdownScape($inst_num) . "\) [$payment_emoji]($link)  " . markdownScape($date) . ':  ' . markdownScape($amount);
            } else
                $installments_text .= "\n" . '‏' . "    $inst_num) $payment_emoji  $date:  $amount";
        }

        $general_info = "‏*" . $loan['name'] . "*:\n" .
            "\n" . "مبلغ وام\: " . beautifulNumber($loan['total_amount']) .
            "\n" . "تاریخ دریافت\: " . beautifulNumber($loan['received_date'], null) .
            "\n" . "کل بازپرداخت\: " . beautifulNumber(array_sum(array_column($installments, 'amount'))) .
            "\n" . beautifulNumber($loan['insts_summary']['paid_count']) . " قسط پرداخت‌شده، معادل " . beautifulNumber($loan['insts_summary']['paid_sum']) .
            "\n" . beautifulNumber($loan['insts_summary']['remaining_count']) . " قسط باقی مانده، معادل " . beautifulNumber($loan['insts_summary']['remaining_sum']) .
            "\n" . beautifulNumber($loan['insts_summary']['overdue_count']) . " قسط معوقه، معادل " . beautifulNumber($loan['insts_summary']['overdue_sum']) .
            "\n" . "شروع یادآوری اقساط از " . beautifulNumber($loan['alert_offset']) . " روز قبل از سررسید" .
            "\n" . "جزئیات اقساط\:";

        if ($markdown) $general_info = markdownScape($general_info);

        $text = $general_info . $installments_text;
    } else $text = $markdown ? markdownScape('هیچ قسطی برای این وام ثبت نشده است!') : 'هیچ قسطی برای این وام ثبت نشده است!';

    return $text;
}

function createLoanDetailKeyboard(array $loan): array
{
    $keyboard = [];
    $keyboard_row = [];
    $btn_in_row = 3;
    foreach ($loan['installments'] as $installment) {

        if ($installment['is_paid']) $payment_icon = '🟢';
        elseif ($installment['is_due']) $payment_icon = $installment['remaining_days'] == 0 ? "🟡" : '🔴';
        else $payment_icon = "⚪";

        $keyboard_row[] = [
            'text' => $payment_icon . ' ' . beautifulNumber($installment['due_date'], null),
            'callback_data' => json_encode(['inplace_inst_pay_toggle' => $installment['id']])
        ];

        if (sizeof($keyboard_row) >= $btn_in_row) {
            $keyboard[] = $keyboard_row;
            $keyboard_row = [];
        }
    }

    if ($keyboard_row) $keyboard[] = $keyboard_row;
    $keyboard[] = [['text' => 'لیست وام‌ها', 'callback_data' => json_encode(['loans_list' => null])]];

    return $keyboard;
}

function calculateProLos(float $p1, float $p2, float $amount = 1, float $conversion_rate = 1): float
{
    $total_price_def = $amount * ($p2 - $p1);
    return $total_price_def * $conversion_rate;
}

/**
 * @param array $asset_names
 * @param DatabaseManager $db
 * @return array
 */
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

/**
 * @param array $assets Array of assets
 * @param array $base_prices
 * @param string $user_base_currency
 * @return string
 */
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
