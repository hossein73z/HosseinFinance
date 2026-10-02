<?php

function createWebAppBtn(string $text, string $path, array $params = []): array
{
    $url = BASE_URL . $path;

    return [
        'text' => $text,
        'web_app' => ['url' => $url . '?' . http_build_query($params)]
    ];
}
