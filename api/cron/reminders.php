<?php

require_once __DIR__ . '/../Libraries/DatabaseManager.php';
require_once __DIR__ . '/../Functions/ExternalEndpointsFunctions.php';
require_once __DIR__ . '/../Functions/StringHelper.php';

$cronSecret = getenv('CRON_SECRET');
if ($cronSecret && $_SERVER['HTTP_AUTHORIZATION'] !== ("Bearer " . $cronSecret)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

try {
    $db = DatabaseManager::getInstance();

    $installments = $db->read(
        table: 'installments i',
        conditions: [
            'i.is_paid' => false
        ],
        selectColumns: 'i.*, p.chat_id, l.name as loan_name, l.alert_offset',
        join: [
            [
                'type' => 'INNER',
                'table' => 'loans l',
                'on' => 'i.loan_id = l.id'
            ],
            [
                'type' => 'INNER',
                'table' => 'persons p',
                'on' => 'l.person_id = p.id'
            ]
        ],
        orderBy: ['due_date' => 'ASC']
    );

    if (!$installments) {
        echo json_encode(['status' => 'success', 'message' => 'No installments due for today.']);
        exit;
    }

    $count = 0;

    foreach ($installments as $installment) {

        $parts = explode('/', $installment['due_date']);
        if (count($parts) == 3) {
            $gregorianDueDate = new DateTime(jalaliToGregorian($parts[0], $parts[1], $parts[2]) . ' 00:00:00');
            $today = new DateTime('now');
            $today->setTime(0, 0); // Normalize today to midnight for accurate day calc

            $interval = $today->diff($gregorianDueDate);
            $daysRemaining = (int)$interval->format('%r%a'); // %r gives sign (-/+), %a gives total days

            if ($daysRemaining > $installment['alert_offset']) continue;

            $message = "📢 یادآوری\n\n";
            $message .= "قسط وام «" . $installment['loan_name'] . "» به مبلغ " . beautifulNumber($installment['amount']);

            // Send request to Telegram
            $response = sendToTelegram(method: 'sendMessage', data: ['chat_id' => $installment['chat_id'], 'text' => $message]);
            if ($response) $count++;
            else echo "\n\n" . json_encode($response, JSON_PRETTY_PRINT) . "\n\n";
        }
    }

    echo json_encode(['status' => 'success', 'sent_count' => $count]);

} catch (Exception $e) {
    error_log("Cron Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
