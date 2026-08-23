<?php

function sendDBInformation(User $user): void
{
    $data = [
        'text' =>
        '*HOST*: ' . DB_HOST . "\n" .
            '*NAME*: ' . DB_NAME . "\n" .
            '*USER*: ' . DB_USER . "\n" .
            '*PORT*: ' . DB_PORT . "\n",
        'chat_id' => $user->getid()
    ];

    sendToTelegram('sendMessage', $data);
    exit;
}

function sendHostInformation(User $user): void
{
    $data = [
        'text' =>
        '*Host Name*: ' . markdownScape(gethostname()) . "\n" .
            '*IP*: ' . markdownScape($_SERVER['SERVER_ADDR']) . "\n" .
            '*PHP Version*: ' . markdownScape(phpinfo()) . "\n" .
            '*OS Info*: ' . "\n" .
            '    OS Name: ' . markdownScape(php_uname('s')) . "\n" .
            '    Host Name: ' . markdownScape(php_uname('n')) . "\n" .
            '    Kernel Release: ' . markdownScape(php_uname('r')) . "\n" .
            '    OS Version: ' . markdownScape(php_uname('v')) . "\n" .
            '    Architecture: ' . markdownScape(php_uname('m')) . "\n",
        'chat_id' => $user->getid()
    ];

    sendToTelegram('sendMessage', $data);
    exit;
}
