<?php

require_once __DIR__ . '/../Libraries/DatabaseManager.php';
require_once __DIR__ . '/../Functions/ExternalEndpointsFunctions.php';
require_once __DIR__ . '/../Functions/StringHelper.php';
require_once __DIR__ . '/../Models/JalaliDate.php';

$cronSecret = getenv('CRON_SECRET');
$headers = getallheaders();
$auth = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
if ($cronSecret && $auth !== "Bearer $cronSecret") {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

try {
    $db = DatabaseManager::getInstance(
        host: getenv('DB_HOST'),
        db: getenv('DB_NAME'),
        user: getenv('DB_USER'),
        pass: getenv('DB_PASS'),
        port: getenv('DB_PORT') ?: '3306'
    );

    $installments = $db->query("
        select
            i.*,
            l.name as loan_name,
            u.id as chat_id
        from installments i
        join loans l on i.loan_id=l.id
        join users u on l.user_id=u.id
        where
            i.is_paid is false and
            curdate() between i.alert_date and i.due_date;
    ")->fetchAll();
    if ($installments) foreach ($installments as $installment) {

        $due_date = JalaliDate::fromGregorianString($installment['due_date']);
        $remaining_days = $due_date->diffInDays(JalaliDate::fromGregorian());
        $remaining_days_str = $remaining_days ? ($remaining_days == 1 ? 'فردا' : $remaining_days . ' روز دیگر') : 'امروز';

        $text = 'یادآور پرداخت قسط وام «' . $installment['loan_name'] . '»' . "\n";
        $text .= "\n" . 'سررسید: ' . beautifulNumber($due_date->format() . ' (' . $remaining_days_str . ')', null);
        $text .= "\n" . 'مبلغ: ' . beautifulNumber($installment['amount']);

        $reply_markup = ['inline_keyboard' => [[
            ['text' => 'پرداخت شد', 'callback_data' => json_encode(['cron_inst_paid' => $installment['id']])]
        ]]];

        sendToTelegram('sendMessage', ['chat_id' => $installment['chat_id'], 'text' => $text, 'reply_markup' => $reply_markup]);
    }

} catch (Exception $e) {
    error_log("Cron Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
