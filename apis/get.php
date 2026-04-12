<?php
require_once __DIR__ . '/../connection.php';

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

// Function to fetch pokemon data
function fetchPokemonData(string $query): array
{
    $url = 'https://pokeapi.co/api/v2/pokemon/' . urlencode(trim(strtolower($query)));
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: PHP PokeAPI Client/1.0',
        ],
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($curlError) {
        return ['error' => 'cURL error: ' . $curlError];
    }

    if ($httpCode !== 200) {
        return ['error' => 'Pokemon not found or API returned HTTP ' . $httpCode];
    }

    $data = json_decode($response, true);
    if ($data === null) {
        return ['error' => 'Unable to decode API response.'];
    }

    return ['data' => $data];
}

if (isset($_GET['pokemon'])) {
    $searchTerm = trim($_GET['pokemon']);
    $result = fetchPokemonData($searchTerm);
    if (isset($result['error'])) {
        respondJson(['status' => 'failed', 'message' => $result['error']], 404);
    } else {
        if (savePokemonSearch($conn, $result['data'], $searchTerm)) {
            respondJson(['status' => 'success', 'message' => 'Pokemon fetched and saved successfully.', 'data' => $result['data']], 200);
        } else {
            respondJson(['status' => 'failed', 'message' => 'Pokemon fetched but failed to save to database.'], 500);
        }
    }
} else {
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $limit = max(1, min($limit, 200));
    $offset = max(0, $offset);

    try {
        $stmt = $conn->prepare("SELECT id, pokemon_id, pokemon_name, search_term, height, weight, base_experience, types, abilities, stats, sprite_url, created_at FROM pokemon_searches ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode JSON fields and convert numeric fields
        foreach ($results as &$row) {
            $row['types'] = json_decode($row['types'], true);
            $row['abilities'] = json_decode($row['abilities'], true);
            $row['stats'] = json_decode($row['stats'], true);
            $row['height'] = (int)$row['height'];
            $row['weight'] = (int)$row['weight'];
            $row['base_experience'] = (int)$row['base_experience'];
        }

        // Get total count
        $countStmt = $conn->query("SELECT COUNT(*) as total FROM pokemon_searches");
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        respondJson([
            'status' => 'success',
            'total' => $total,
            'count' => count($results),
            'limit' => $limit,
            'offset' => $offset,
            'results' => $results,
        ]);
    } catch (PDOException $e) {
        respondJson(['status' => 'failed', 'message' => 'Database query failed: ' . $e->getMessage()], 500);
    }
}
?>
