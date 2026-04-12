<?php
require_once __DIR__ . '/../connection.php';

$data = getJsonBody();
$pokemon = $data['pokemon'] ?? null;
if (empty($pokemon)) {
    respondJson(['status' => 'failed', 'message' => 'Missing pokemon field in request body.'], 400);
}

$result = pokeApiRequest('pokemon/' . urlencode(trim(strtolower($pokemon))));
if (isset($result['error'])) {
    respondJson(['status' => 'failed', 'message' => $result['error']], 404);
}

// Database connection
$conn = null;
try {
    $dbServer = 'localhost';
    $dbUser = 'root';
    $dbPassword = '';
    $dbName = 'pokedex_entries';

    $conn = new PDO("mysql:host={$dbServer};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPassword);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    respondJson(['status' => 'failed', 'message' => 'Database connection failed: ' . $e->getMessage()], 500);
}

// Function to ensure table exists
function ensurePokemonTableExists(PDO $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS pokemon_searches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pokemon_id INT NOT NULL,
        pokemon_name VARCHAR(100) NOT NULL,
        search_term VARCHAR(255) NOT NULL,
        height INT,
        weight INT,
        base_experience INT,
        types TEXT NOT NULL,
        abilities TEXT NOT NULL,
        stats TEXT NOT NULL,
        sprite_url VARCHAR(255),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    $conn->exec($sql);
}

// Function to save pokemon data
function savePokemonSearch(PDO $conn, array $pokemonData, string $searchTerm): bool
{
    ensurePokemonTableExists($conn);

    $types = json_encode(array_map(fn($type) => $type['type']['name'], $pokemonData['types']), JSON_UNESCAPED_UNICODE);
    $abilities = json_encode(array_map(fn($ability) => $ability['ability']['name'], $pokemonData['abilities']), JSON_UNESCAPED_UNICODE);
    $stats = json_encode(array_map(fn($stat) => [
        'name' => $stat['stat']['name'],
        'value' => $stat['base_stat'],
    ], $pokemonData['stats']), JSON_UNESCAPED_UNICODE);
    $sprite = $pokemonData['sprites']['other']['official-artwork']['front_default']
        ?? $pokemonData['sprites']['front_default']
        ?? '';

    $sql = "INSERT INTO pokemon_searches (pokemon_id, pokemon_name, search_term, height, weight, base_experience, types, abilities, stats, sprite_url)
            VALUES (:pokemon_id, :pokemon_name, :search_term, :height, :weight, :base_experience, :types, :abilities, :stats, :sprite_url)";
    $stmt = $conn->prepare($sql);

    return $stmt->execute([
        ':pokemon_id' => $pokemonData['id'] ?? 0,
        ':pokemon_name' => $pokemonData['name'] ?? '',
        ':search_term' => $searchTerm,
        ':height' => $pokemonData['height'] ?? 0,
        ':weight' => $pokemonData['weight'] ?? 0,
        ':base_experience' => $pokemonData['base_experience'] ?? 0,
        ':types' => $types,
        ':abilities' => $abilities,
        ':stats' => $stats,
        ':sprite_url' => $sprite,
    ]);
}

// Save to database
if (savePokemonSearch($conn, $result, $pokemon)) {
    respondJson(['status' => 'success', 'message' => 'Pokemon fetched and saved successfully.', 'data' => $result], 200);
} else {
    respondJson(['status' => 'failed', 'message' => 'Pokemon fetched but failed to save to database.'], 500);
}

?>