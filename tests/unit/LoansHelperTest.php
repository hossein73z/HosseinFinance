<?php

use PHPUnit\Framework\TestCase;

class LoansHelperTest extends TestCase
{
    /** Minimal loan fixture shaped like prepareLoan() output */
    private function sampleLoan(): array
    {
        return [
            'id' => 10,
            'user_id' => 1,
            'name' => 'وام مسکن',
            'total_amount' => 1000000,
            'received_date' => '1403/01/01',
            'alert_offset' => 3,
            'created_at' => '2024-01-01 00:00:00',
            'installments' => [
                [
                    'id' => 101,
                    'loan_id' => 10,
                    'amount' => 100000,
                    'due_date' => '1403/02/01',
                    'alert_date' => '1403/01/28',
                    'is_paid' => true,
                    'is_due' => false,
                    'remaining_days' => -10,
                ],
                [
                    'id' => 102,
                    'loan_id' => 10,
                    'amount' => 100000,
                    'due_date' => '1403/03/01',
                    'alert_date' => '1403/02/26',
                    'is_paid' => false,
                    'is_due' => true,
                    'remaining_days' => -2,
                ],
                [
                    'id' => 103,
                    'loan_id' => 10,
                    'amount' => 100000,
                    'due_date' => '1403/04/01',
                    'alert_date' => '1403/03/28',
                    'is_paid' => false,
                    'is_due' => false,
                    'remaining_days' => 20,
                ],
            ],
            'insts_summary' => [
                'paid_count' => 1,
                'paid_sum' => 100000,
                'overdue_count' => 1,
                'overdue_sum' => 100000,
                'remaining_count' => 1,
                'remaining_sum' => 100000,
            ],
            'next_installment' => [
                'id' => 103,
                'amount' => 100000,
                'remaining_days' => 20,
                'due_date' => '1403/04/01',
            ],
        ];
    }

    // ---------- prepareLoanForWebApp ----------

    public function testPrepareLoanForWebAppRemovesSensitiveFields(): void
    {
        $loan = $this->sampleLoan();
        $prepared = prepareLoanForWebApp($loan);

        $this->assertArrayNotHasKey('user_id', $prepared);
        $this->assertArrayNotHasKey('created_at', $prepared);

        foreach ($prepared['installments'] as $inst) {
            $this->assertArrayNotHasKey('loan_id', $inst);
            $this->assertArrayNotHasKey('alert_date', $inst);
            $this->assertArrayNotHasKey('is_due', $inst);
            $this->assertArrayNotHasKey('remaining_days', $inst);
        }

        // Core fields kept
        $this->assertSame(10, $prepared['id']);
        $this->assertSame('وام مسکن', $prepared['name']);
        $this->assertCount(3, $prepared['installments']);
    }

    // ---------- createLoanDetailKeyboard ----------

    public function testCreateLoanDetailKeyboardStructure(): void
    {
        $keyboard = createLoanDetailKeyboard($this->sampleLoan());

        // Last row is "لیست وام‌ها"
        $lastRow = $keyboard[array_key_last($keyboard)];
        $this->assertSame('لیست وام‌ها', $lastRow[0]['text']);
        $this->assertStringContainsString('loans_list', $lastRow[0]['callback_data']);

        // First rows are installment buttons (max 3 per row)
        $firstRow = $keyboard[0];
        $this->assertLessThanOrEqual(3, count($firstRow));
        $this->assertArrayHasKey('callback_data', $firstRow[0]);
        $this->assertStringContainsString('inplace_inst_pay_toggle', $firstRow[0]['callback_data']);
    }

    public function testCreateLoanDetailKeyboardPaymentIcons(): void
    {
        $keyboard = createLoanDetailKeyboard($this->sampleLoan());

        // Flatten button texts (except last row)
        $texts = [];
        for ($i = 0; $i < count($keyboard) - 1; $i++) {
            foreach ($keyboard[$i] as $btn) {
                $texts[] = $btn['text'];
            }
        }

        $joined = implode(' ', $texts);
        $this->assertStringContainsString('🟢', $joined); // paid
        $this->assertStringContainsString('🔴', $joined); // overdue
        $this->assertStringContainsString('⚪', $joined); // remaining
    }

    // ---------- createLoanDetailText ----------

    public function testCreateLoanDetailTextContainsLoanNameAndAmounts(): void
    {
        $text = createLoanDetailText($this->sampleLoan());

        $this->assertStringContainsString('وام مسکن', $text);
        // amounts go through beautifulNumber → Persian digits; compare via toEnglishDigits
        $plain = toEnglishDigits($text);
        $this->assertStringContainsString('1000000', str_replace(',', '', $plain));
    }

    public function testCreateLoanDetailTextWithMarkdown(): void
    {
        $text = createLoanDetailText($this->sampleLoan(), 'MarkdownV2', '55');

        // Markdown links for toggle payment
        $this->assertStringContainsString('toggleInstPayment', $text);
        $this->assertStringContainsString('mssgId55', $text);
    }

    public function testCreateLoanDetailTextNoInstallments(): void
    {
        $loan = $this->sampleLoan();
        $loan['installments'] = null;

        $text = createLoanDetailText($loan);
        $this->assertStringContainsString('هیچ قسطی', $text);
    }

    // ---------- createLoansView ----------

    public function testCreateLoansViewSummarized(): void
    {
        $text = createLoansView([$this->sampleLoan()], null, null, true);

        $this->assertStringContainsString('وام‌های ثبت شده', $text);
        $this->assertStringContainsString('وام مسکن', $text);
        // summary totals section
        $this->assertStringContainsString('خلاصه وضعیت', $text);
    }

    public function testCreateLoansViewDetailed(): void
    {
        $text = createLoansView([$this->sampleLoan()], '99', '11', false);

        $this->assertStringContainsString('وام مسکن', $text);
        $this->assertStringContainsString('showLoan_loanId10', $text);
        $this->assertStringContainsString('loansMssgId99', $text);
        $this->assertStringContainsString('initMssgId11', $text);
    }
}