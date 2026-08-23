<?php

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
