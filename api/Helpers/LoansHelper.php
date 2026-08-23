<?php

function getLoanWithInstallments(int|string $user_id, DatabaseManager $db, bool $jalali = false, int|string|null $loan_id = null, int|string|null $installment_id = null): bool|array
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

function createLoansView(array $loans, ?string $loans_mssg_id = null, ?string $initial_mssg_id = null, bool $summerized = true): string
{
    /**
     * Considerations for `$loans` array:
     *  -- Each loan must have all related
     *     installments under `installments` column.
     *  -- All dates (loans' received date and installments'
     *     due and alert date) must be in Jalali string.
     *  -- Installments must be sorted ascending by their duedate.
     *  -- Installments must have 'is_due' bool value.
     */

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

function createLoanDetailText(array $loan, ?string $markdown = null, ?string $mssg_id = null): string
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
