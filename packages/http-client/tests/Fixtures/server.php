<?php

declare(strict_types=1);

// Router for the PHP built-in server used by HttpClientTest.

$target = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = parse_url($target, PHP_URL_PATH);
parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
$to = is_string($query['to'] ?? null) ? $query['to'] : '/echo';

switch ($path) {
    case '/echo':
        header('Content-Type: application/json');
        header('X-Multi: a');
        header('X-Multi: b', false);
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'headers' => array_change_key_case(getallheaders()),
            'body' => file_get_contents('php://input'),
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
        ]);
        break;
    case '/redirect':
        http_response_code(is_numeric($query['status'] ?? null) ? (int) $query['status'] : 302);
        header('Location: ' . $to);
        break;
    case '/dir/relative':
        http_response_code(302);
        header('Location: ../echo?from=relative');
        break;
    case '/big':
        echo str_repeat('x', is_numeric($query['bytes'] ?? null) ? (int) $query['bytes'] : 0);
        break;
    case '/chunked':
        // No Content-Length: the limit must apply while reading.
        for ($i = 0; $i < 20; $i++) {
            echo str_repeat('y', 100);
            flush();
        }
        break;
    case '/slow':
        usleep((is_numeric($query['ms'] ?? null) ? (int) $query['ms'] : 0) * 1000);
        echo 'done';
        break;
    default:
        http_response_code(404);
}
