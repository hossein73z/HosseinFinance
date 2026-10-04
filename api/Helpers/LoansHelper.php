<?php

function getLoanWithInstallments(
    int|string      $user_id,
    DatabaseManager $db,
    bool            $jalali = false,
    int|string|null $loan_id = null,
    int|string|null $installment_id = null): bool|array
{
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
                            'is_paid', i.is_paid,
                            'is_active', i.is_active
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
                $due_date = DateTime::createFromFormat('Y-m-d', $installment['due_date'])->setTime(0, 0);

                // Create `is_due` and `is_paid` boolean values
                $is_paid = boolval($installment['is_paid']);
                $remaining_days = new DateTime('today')->diff($due_date);
                $is_due = $remaining_days->days === 0 || $remaining_days->invert;

                // Add `is_due` and `is_paid` to the installment
                $installment['is_due'] = $is_due;
                $installment['is_paid'] = $is_paid;
                $installment['remaining_days'] = ($is_due ? -1 : 1) * $remaining_days->days;

                if ($installment['is_active']) {
                    // Initialize installments' summary
                    if ($is_paid) $summary_key_word = 'paid';
                    elseif ($is_due) $summary_key_word = 'overdue';
                    else $summary_key_word = 'remaining';

                    // Add installments' summary to loan object
                    $loan['insts_summary'][$summary_key_word . '_count'] += 1;
                    $loan['insts_summary'][$summary_key_word . '_sum'] += $installment['amount'];

                    // Store next installment
                    // NOTE: Due date is stored as Gregorian object
                    if ($loan['next_installment'] === null && $installment['remaining_days'] >= 0 && !$is_paid) {
                        $loan['next_installment'] = $installment;
                        $loan['next_installment']['due_date'] = $due_date;
                    }
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

function prepareLoanForWebApp(array $loan): array
{
    unset($loan['user_id']);
    unset($loan['created_at']);
    if ($loan['installments']) {
        foreach ($loan['installments'] as &$installment) {
            unset($installment['loan_id']);
            unset($installment['alert_date']);
            unset($installment['is_due']);
            unset($installment['remaining_days']);
        }
        $loan['installments'] = array_values($loan['installments']);
    }
    return $loan;
}

function createLoansRichMessage(array $loans, bool $summerized = true): array
{
    /**
     * Considerations for `$loans` array:
     *  -- Each loan must have all related
     *     installments under `installments` column.
     *  -- All dates (loans' received date and installments'
     *     due and alert date) must be in Jalali string.
     *  -- Installments must be sorted ascending by their `due_date`.
     *  -- Installments must have 'is_due' bool value.
     */

    // Create html for loans
    $loans_html = '';
    foreach ($loans as $loan) {

        // Loan's name button HTML
        $loan_callback = json_encode(['view_loan' => $loan['id']]);
        $loan_name_html = "‏" . "<tg-button type='callback_data' data='$loan_callback'>" . beautifulNumber($loan['name'], null) . "</tg-button>";

        // Installments HTML code without outer tag
        $insts_html = null;
        if ($installments = &$loan['installments']) {

            // Create payment status emoji for the installment
            $insts_per_year = [];
            foreach ($installments as $installment) {

                $due_date = JalaliDate::fromString($installment['due_date']);

                if ($summerized) {

                    // Calculate and add year divider
                    if ((isset($loan_id) && $loan_id == $installment['loan_id']) &&
                        (isset($due_year) && $due_year != $due_date->jy))
                        $insts_html .= '|';
                    $loan_id = $installment['loan_id'];
                    $due_year = $due_date->jy;

                    $insts_html = $insts_html ?? "<br>‏";
                    if (!$installment['is_active'])
                        $insts_html .= $installment['is_paid'] ? "🟤" : "⚫";
                    elseif ($installment['is_paid'])
                        $insts_html .= "🟢";
                    elseif ($installment['remaining_days'] == 0)
                        $insts_html .= "🟡";
                    elseif ($installment['is_due'])
                        $insts_html .= "🔴";
                    else
                        $insts_html .= "⚪";
                } else {
                    $due_year = $due_date->jy;
                    if (!$installment['is_active'])
                        $insts_per_year[$due_year][] = $installment['is_paid'] ? "🟤" : "⚫";
                    elseif ($installment['is_paid'])
                        $insts_per_year[$due_year][] = "🟢";
                    elseif ($installment['remaining_days'] == 0)
                        $insts_per_year[$due_year][] = "🟡";
                    elseif ($installment['is_due'])
                        $insts_per_year[$due_year][] = "🔴";
                    else
                        $insts_per_year[$due_year][] = "⚪";
                }
            }

            if (!$summerized) {
                $last_year = array_key_last($insts_per_year);
                $insts_html = $insts_html ?? "<br>" . "┘─ وضعیت اقساط: ";
                foreach ($insts_per_year as $year => $year_installments) {
                    $prefix = "‏" . "&nbsp;&nbsp;&nbsp; " . (($year != $last_year) ? "┤─" : "┘─");
                    $insts_html .= "<br>$prefix " . beautifulNumber($year, null) . ': ' . implode('', $year_installments);
                }
            }
        }

        // Next payment's date as text: $next_payment_text
        if (isset($loan['next_installment'])) {

            // Create text for the next payment
            $next_installment = $loan['next_installment'];
            $remaining_days = beautifulNumber($next_installment['remaining_days'], null);
            $inst_amount = beautifulNumber($next_installment['amount']);

            if ($remaining_days == '۰') $next_payment_text = $inst_amount . " ریال برای امروز";
            elseif ($remaining_days == '۱') $next_payment_text = $inst_amount . " ریال برای فردا";
            else $next_payment_text = $inst_amount . ' ریال برای ' . $remaining_days . ' روز دیگر';

        } else
            $next_payment_text = 'پایان یافته';

        // General HTML information of the loan without outer tag:
        $loan_general_html = $summerized ?
            ": $next_payment_text" :
            "<br>┤─ " . "مبلغ وام: " . beautifulNumber($loan['total_amount']) .
            "<br>┤─ " . "تاریخ دریافت: " . beautifulNumber($loan['received_date'], null) .
            "<br>┤─ " . "قسط بعدی: " . beautifulNumber($next_payment_text, null);

        $loans_html .= "<li>" . $loan_name_html . $loan_general_html . $insts_html . "<br>" . "</li>";
    }

    // Calculate total numbers for summery
    $total_paid = 0;
    $total_overdue = 0;
    $total_remaining = 0;
    foreach ($loans as $loan) {
        $total_paid += $loan['insts_summary']['paid_sum'];
        $total_overdue += $loan['insts_summary']['overdue_sum'];
        $total_remaining += $loan['insts_summary']['remaining_sum'];

    }

    // Total summery HTML for the beginning of the HTML code
    $total_summery_html =
        "<h4>" . "خلاصه وضعیت اقساط وام‌های جاری: " . "</h4>" . "<ul>" .
        "<li>🟢 " . "جمع اقساط پرداخت شده: " . beautifulNumber($total_paid) . "</li>" .
        "<li>🔴 " . "جمع اقساط معوق: " . beautifulNumber($total_overdue) . "</li>" .
        "<li>⚪ " . "جمع اقساط سررسید نشده: " . beautifulNumber($total_remaining) . "</li>" .
        "</ul>";

    $footer =
        "<fotter>" .
        "معانی ایموجی‌های اقساط:" .
        "<br>" . "🟤 --> پرداخت شده ولی غیرفعال" .
        "<br>" . "⚫ --> پداخت نشده و غیرفعال" .
        "<br>" . "🟢 --> پداخت شده و فعال" .
        "<br>" . "🟡 --> پرداخت نشده و فعال با سررسید امروز" .
        "<br>" . "🔴 --> پرداخت نشده و فعال با سررسید گذشته" .
        "<br>" . "⚪ --> پرداخت نشده، فعال و سررسید نشده" .
        "</fotter>";

    // Final HTML code
    $html = "$total_summery_html<hr><h3>" . "وام‌های ثبت شده‌ی شما: " . "</h3><ul>$loans_html</ul><hr>$footer";
    return ['is_rtl' => true, 'html' => $html];
}

function createLoanDetailRichMessage(array $loan, int|string|null $installment_id = null): array
{
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

    $installments = &$loan['installments'];
    if ($installments) {

        // Loan's general info about
        $general_info =
            "<br>" . "مبلغ وام: " . beautifulNumber($loan['total_amount']) .
            "<br>" . "تاریخ دریافت: " . beautifulNumber($loan['received_date'], null) .
            "<br>" . "کل بازپرداخت: " . beautifulNumber(array_sum(array_column($installments, 'amount'))) .
            "<br>" . "تعداد اقساط پرداخت‌شده: " . beautifulNumber($loan['insts_summary']['paid_count']) . " (معادل " . beautifulNumber($loan['insts_summary']['paid_sum']) . " ریال)" .
            "<br>" . "تعداد اقساط باقی مانده: " . beautifulNumber($loan['insts_summary']['remaining_count']) . " (معادل " . beautifulNumber($loan['insts_summary']['remaining_sum']) . " ریال)" .
            "<br>" . "تعداد اقساط معوقه: " . beautifulNumber($loan['insts_summary']['overdue_count']) . " (معادل " . beautifulNumber($loan['insts_summary']['overdue_sum']) . " ریال)" .
            "<br>" . "شروع یادآوری اقساط از " . beautifulNumber($loan['alert_offset']) . " روز قبل از سررسید" .
            "<br>" . "جزئیات اقساط: ";

        // Loan's installments details
        $installments_text = '';
        foreach ($installments as $i => $installment) {

            // Create installment's payment status emoji
            if ($installment['is_paid']) $payment_emoji = "🟢";
            elseif ($installment['is_due']) $payment_emoji = $installment['remaining_days'] == 0 ? "🟡" : "🔴";
            else $payment_emoji = "⚪";

            // Create delete/confirmation button for the installment
            if ($installment_id && $installment['id'] == $installment_id) {
                $delete_conf_callback = json_encode(['delete_inst_conf' => $installment['id']]);
                $delete_deny_callback = json_encode(['view_loan_inplace' => $installment['loan_id']]);
                $inst_buttons_html =
                    "<tg-button style='primary' type='disabled'>" . "حذف" . "</tg-button>" .
                    "<tg-button style='danger' type='callback_data' data='$delete_conf_callback'>" . "تأیید حذف" . "</tg-button>" .
                    "<tg-button style='success' type='callback_data' data='$delete_deny_callback'>" . "لغو" . "</tg-button>";
            } else {
                $delete_callback = json_encode(['delete_inst' => $installment['id']]); # Delete button
                $pay_callback = json_encode(['toggle_inst_pay' => [$installment['loan_id'], $installment['id']]]); # Payment button
                $active_callback = json_encode(['toggle_inst_active' => [$installment['loan_id'], $installment['id']]]); # Activation button
                $inst_buttons_html =
                    "<tg-button style='primary' type='callback_data' data='$delete_callback'>" . "حذف" . "</tg-button>" .
                    "<tg-button style='primary' type='callback_data' data='$pay_callback'>" . ($installment['is_paid'] ? 'پرداخت نشده' : 'پرداخت شده') . "</tg-button>" .
                    "<tg-button style='primary' type='callback_data' data='$active_callback'>" . ($installment['is_active'] ? 'غیرفعال' : 'فعال') . "</tg-button>";
            }

            // Prepare installment's text
            $inst_num = beautifulNumber(intval($i) + 1, null);
            $date = beautifulNumber($installment['due_date'], null);
            $amount = beautifulNumber($installment['amount']);

            $inst_text = ($installment['is_active']) ? "$payment_emoji $date: $amount" : "<s>$payment_emoji $date: $amount</s>";

            $installments_text .= "<br>‏&nbsp;&nbsp;&nbsp;&nbsp;$inst_num) $inst_text $inst_buttons_html";
        }

        // Final HTML code
        $html = "<h3>$loan[name]</h3>" . "<p>$general_info$installments_text</p>";

    } else $html = '<p>هیچ قسطی برای این وام ثبت نشده است!</p>';

    return ['is_rtl' => true, 'html' => $html];
}
