<?php
require_once __DIR__ . '/../connection.php';

$id = $_GET['id'] ?? $_GET['name'] ?? null;
if (empty($id)) {
    respondJson(['status' => 'failed', 'message' => 'Missing id or name parameter.'], 400);
}

$result = pokeApiRequest('pokemon/' . urlencode(trim(strtolower($id))));
if (isset($result['error'])) {
    respondJson(['status' => 'failed', 'message' => $result['error']], 404);
}

$data = [
    'id' => $result['id'] ?? null,
    'name' => $result['name'] ?? null,
    'height' => $result['height'] ?? null,
    'weight' => $result['weight'] ?? null,
    'base_experience' => $result['base_experience'] ?? null,
    'types' => array_map(fn($item) => $item['type']['name'], $result['types'] ?? []),
    'abilities' => array_map(fn($item) => $item['ability']['name'], $result['abilities'] ?? []),
    'stats' => array_map(fn($item) => [
        'name' => $item['stat']['name'],
        'value' => $item['base_stat'],
    ], $result['stats'] ?? []),
    'sprite' => $result['sprites']['other']['official-artwork']['front_default'] ?? $result['sprites']['front_default'] ?? null,
];

respondJson(['status' => 'success', 'data' => $data]);

