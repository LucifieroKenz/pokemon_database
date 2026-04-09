<?php
require_once __DIR__ . '/../connection.php';

$data = getJsonBody();
$pokemon = $data['pokemon'] ?? null;
$random = $data['random'] ?? false;

if ($random) {
    $pokemon = rand(1, 1010);
}

if (empty($pokemon)) {
    respondJson(['status' => 'failed', 'message' => 'Missing pokemon field or random flag in request body.'], 400);
}

$result = pokeApiRequest('pokemon/' . urlencode(trim(strtolower($pokemon))));
if (isset($result['error'])) {
    respondJson(['status' => 'failed', 'message' => $result['error']], 404);
}

respondJson(['status' => 'success', 'message' => 'Pokemon fetched successfully.', 'data' => $result], 200);
?>