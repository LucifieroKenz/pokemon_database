<?php
$pokeApiBase = 'https://pokeapi.co/api/v2';

function pokeApiRequest(string $endpoint): array
{
    global $pokeApiBase;
    $url = rtrim($pokeApiBase, '/') . '/' . ltrim($endpoint, '/');

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: PHP PokeAPI Wrapper/1.0',
        ],
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($curlError) {
        return ['error' => 'cURL error: ' . $curlError];
    }
    if ($httpCode >= 400) {
        return ['error' => 'PokeAPI returned HTTP ' . $httpCode];
    }

    $data = json_decode($response, true);
    if ($data === null) {
        return ['error' => 'Unable to decode API response.'];
    }

    return $data;
}

function getJsonBody(): array
{
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}

function respondJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit;
}
