<?php
// API used: https://pokeapi.co/
// Objective: Fetch Pokemon data using PHP cURL, decode JSON, display readable results, and save searches into the database.

require 'connection.php';

$conn = null;
try {
    $dbServer = 'localhost';
    $dbUser = 'root';
    $dbPassword = '';
    $dbName = 'pokedex_entries';

    $conn = new PDO("mysql:host={$dbServer};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPassword);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $conn = null;
    $errorMessage = 'Database connection failed: ' . $e->getMessage();
}

$apiBaseUrl = 'https://pokeapi.co/api/v2/pokemon/';
$pokemonData = null;
$errorMessage = $errorMessage ?? null;
$savedMessage = null;
$searchTerm = '';

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

$searchTerm = '';

function wantsJson(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return stripos($accept, 'application/json') !== false || stripos($userAgent, 'Postman') !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['pokemon'])) {
        $searchTerm = trim($_GET['pokemon']);
        if (wantsJson() && is_numeric($searchTerm)) {
            // Get from database
            $pokemonId = (int) $searchTerm;
            try {
                $stmt = $conn->prepare("SELECT id, pokemon_id, pokemon_name, search_term, height, weight, base_experience, types, abilities, stats, sprite_url, created_at FROM pokemon_searches WHERE pokemon_id = :pokemon_id");
                $stmt->bindParam(':pokemon_id', $pokemonId, PDO::PARAM_INT);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result) {
                    $result['types'] = json_decode($result['types'], true);
                    $result['abilities'] = json_decode($result['abilities'], true);
                    $result['stats'] = json_decode($result['stats'], true);

                    http_response_code(200);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'success', 'data' => $result], JSON_PRETTY_PRINT);
                    exit;
                } else {
                    http_response_code(404);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'failed', 'message' => 'Pokemon not found in database.'], JSON_PRETTY_PRINT);
                    exit;
                }
            } catch (PDOException $e) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'failed', 'message' => 'Database query failed: ' . $e->getMessage()], JSON_PRETTY_PRINT);
                exit;
            }
        } else {
            $result = fetchPokemonData($searchTerm);
            if (wantsJson()) {
                if (isset($result['error'])) {
                    http_response_code(404);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'failed', 'message' => $result['error']], JSON_PRETTY_PRINT);
                    exit;
                } else {
                    $pokemonData = $result['data'];
                    $status = isset($conn) && $conn instanceof PDO && savePokemonSearch($conn, $pokemonData, $searchTerm) ? 'success' : 'failed';
                    http_response_code($status === 'success' ? 200 : 500);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => $status, 'message' => $status === 'success' ? 'Pokemon fetched and saved successfully.' : 'Failed to save to database.', 'data' => $pokemonData], JSON_PRETTY_PRINT);
                    exit;
                }
            } else {
                if (isset($result['error'])) {
                    $errorMessage = $result['error'];
                } else {
                    $pokemonData = $result['data'];
                    if (isset($conn) && $conn instanceof PDO) {
                        if (savePokemonSearch($conn, $pokemonData, $searchTerm)) {
                            $savedMessage = 'Pokemon data saved to the database successfully.';
                        } else {
                            $errorMessage = 'Unable to save Pokemon data to the database.';
                        }
                    } else {
                        $savedMessage = 'Database unavailable. Search result was not saved.';
                    }
                }
            }
        }
    } elseif (isset($_GET['random'])) {
        $randomId = rand(1, 1010);
        $searchTerm = (string) $randomId;
        $result = fetchPokemonData($randomId);
        if (wantsJson()) {
            if (isset($result['error'])) {
                http_response_code(404);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'failed', 'message' => $result['error']], JSON_PRETTY_PRINT);
                exit;
            } else {
                $pokemonData = $result['data'];
                $status = isset($conn) && $conn instanceof PDO && savePokemonSearch($conn, $pokemonData, $searchTerm) ? 'success' : 'failed';
                http_response_code($status === 'success' ? 200 : 500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => $status, 'message' => $status === 'success' ? 'Pokemon fetched and saved successfully.' : 'Failed to save to database.', 'data' => $pokemonData], JSON_PRETTY_PRINT);
                exit;
            }
        } else {
            if (isset($result['error'])) {
                $errorMessage = $result['error'];
            } else {
                $pokemonData = $result['data'];
                if (isset($conn) && $conn instanceof PDO) {
                    if (savePokemonSearch($conn, $pokemonData, $searchTerm)) {
                        $savedMessage = 'Pokemon data saved to the database successfully.';
                    } else {
                        $errorMessage = 'Unable to save Pokemon data to the database.';
                    }
                } else {
                    $savedMessage = 'Database unavailable. Search result was not saved.';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && wantsJson() && empty($_GET['pokemon']) && !isset($_GET['random'])) {
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

        foreach ($results as &$row) {
            $row['types'] = json_decode($row['types'], true);
            $row['abilities'] = json_decode($row['abilities'], true);
            $row['stats'] = json_decode($row['stats'], true);
        }

        $countStmt = $conn->query("SELECT COUNT(*) as total FROM pokemon_searches");
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'success',
            'total' => $total,
            'count' => count($results),
            'limit' => $limit,
            'offset' => $offset,
            'results' => $results,
        ], JSON_PRETTY_PRINT);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'failed', 'message' => 'Database query failed: ' . $e->getMessage()], JSON_PRETTY_PRINT);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pokemon = null;
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $pokemon = $data['pokemon'] ?? null;
    } elseif (stripos($contentType, 'application/x-www-form-urlencoded') !== false || stripos($contentType, 'multipart/form-data') !== false) {
        $pokemon = $_POST['pokemon'] ?? null;
    }

    if (empty($pokemon)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'failed', 'message' => 'Missing pokemon field in request body. Send as JSON: {"pokemon": "name"} or form data with pokemon=name'], JSON_PRETTY_PRINT);
        exit;
    }

    $result = fetchPokemonData($pokemon);
    if (isset($result['error'])) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'failed', 'message' => $result['error']], JSON_PRETTY_PRINT);
        exit;
    }

    if (isset($conn) && $conn instanceof PDO) {
        if (savePokemonSearch($conn, $result['data'], $pokemon)) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'success', 'message' => 'Pokemon fetched and saved successfully.', 'data' => $result['data']], JSON_PRETTY_PRINT);
            exit;
        } else {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'failed', 'message' => 'Pokemon fetched but failed to save to database.'], JSON_PRETTY_PRINT);
            exit;
        }
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'failed', 'message' => 'Database unavailable.'], JSON_PRETTY_PRINT);
        exit;
    }
}

function formatLabel(string $text): string
{
    return ucwords(str_replace('-', ' ', $text));
}

function renderCommaList(array $items): string
{
    return implode(', ', array_map('formatLabel', $items));
}

function extractPokemonDetails(array $data): array
{
    $types = array_map(fn($type) => $type['type']['name'], $data['types']);
    $abilities = array_map(fn($ability) => $ability['ability']['name'], $data['abilities']);
    $stats = array_map(fn($stat) => [
        'name' => $stat['stat']['name'],
        'value' => $stat['base_stat'],
    ], $data['stats']);

    $sprite = $data['sprites']['other']['official-artwork']['front_default']
        ?? $data['sprites']['front_default']
        ?? '';

    return [
        'name' => $data['name'] ?? '',
        'id' => $data['id'] ?? '',
        'height' => $data['height'] ?? '',
        'weight' => $data['weight'] ?? '',
        'base_experience' => $data['base_experience'] ?? '',
        'types' => $types,
        'abilities' => $abilities,
        'stats' => $stats,
        'sprite' => $sprite,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pokémon Database</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f7f7f7;
            margin: 0;
            padding: 0;
            color: #333;
        }
        .container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 1.5rem;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        h1 {
            margin-top: 0;
            color: #2a75bb;
        }
        .api-meta {
            margin-bottom: 1.5rem;
            color: #555;
        }
        form {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        input[type="text"] {
            flex: 1 1 250px;
            padding: 0.85rem 1rem;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 1rem;
        }
        button {
            padding: 0.85rem 1.25rem;
            border: none;
            border-radius: 8px;
            background: #2a75bb;
            color: white;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        button:hover {
            background: #1f5b94;
        }
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .error {
            background: #ffe5e5;
            color: #af1c1c;
        }
        .pokemon-card {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 1.5rem;
            align-items: start;
        }
        .pokemon-card img {
            width: 100%;
            border-radius: 16px;
            border: 1px solid #eee;
            background: #f9fafb;
        }
        .pokemon-details {
            display: grid;
            gap: 1rem;
        }
        .pokemon-details h2 {
            margin: 0 0 0.25rem;
            text-transform: capitalize;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(120px, 1fr));
            gap: 0.75rem 1.5rem;
        }
        .detail-grid div {
            background: #f6f8fc;
            padding: 0.85rem;
            border-radius: 10px;
        }
        .detail-grid strong {
            display: block;
            margin-bottom: 0.35rem;
            color: #555;
            font-size: 0.95rem;
        }
        .stats-table {
            width: 100%;
            border-collapse: collapse;
        }
        .stats-table th,
        .stats-table td {
            text-align: left;
            padding: 0.65rem 0.5rem;
            border-bottom: 1px solid #eaeaea;
        }
        .stats-table th {
            color: #555;
            width: 140px;
        }
        .footer {
            margin-top: 2rem;
            color: #666;
            font-size: 0.95rem;
        }
        @media (max-width: 720px) {
            .pokemon-card {
                grid-template-columns: 1fr;
            }
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Pokémon Database</h1>
        <p class="api-meta">API used: <strong>https://pokeapi.co/</strong>. Enter a Pokémon name or ID, then search or load a random Pokémon.</p>

        <form method="get" action="">
            <input type="text" name="pokemon" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Enter Pokémon name or ID" />
            <button type="submit">Search</button>
            <button type="submit" name="random" value="1">Random Pokémon</button>
        </form>

        <?php if ($errorMessage): ?>
            <div class="message error"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <?php if ($savedMessage): ?>
            <div class="message" style="background:#e6ffed;color:#1f6a2d;"><?= htmlspecialchars($savedMessage) ?></div>
        <?php endif; ?>

        <?php if ($pokemonData): ?>
            <?php $pokemon = extractPokemonDetails($pokemonData); ?>
            <div class="pokemon-card">
                <div>
                    <?php if ($pokemon['sprite']): ?>
                        <img src="<?= htmlspecialchars($pokemon['sprite']) ?>" alt="<?= htmlspecialchars($pokemon['name']) ?>" />
                    <?php else: ?>
                        <div class="message">No image available.</div>
                    <?php endif; ?>
                </div>
                <div class="pokemon-details">
                    <div>
                        <h2><?= htmlspecialchars($pokemon['name']) ?> <span style="font-size:0.8rem;color:#777;">#<?= htmlspecialchars($pokemon['id']) ?></span></h2>
                        <p><strong>Types:</strong> <?= htmlspecialchars(renderCommaList($pokemon['types'])) ?></p>
                        <p><strong>Abilities:</strong> <?= htmlspecialchars(renderCommaList($pokemon['abilities'])) ?></p>
                    </div>

                    <div class="detail-grid">
                        <div><strong>Height</strong><span><?= htmlspecialchars($pokemon['height']) ?> decimeters</span></div>
                        <div><strong>Weight</strong><span><?= htmlspecialchars($pokemon['weight']) ?> hectograms</span></div>
                        <div><strong>Base Experience</strong><span><?= htmlspecialchars($pokemon['base_experience']) ?></span></div>
                        <div><strong>Search Term</strong><span><?= htmlspecialchars($searchTerm) ?></span></div>
                    </div>

                    <div>
                        <h3>Stats</h3>
                        <table class="stats-table">
                            <thead>
                                <tr><th>Stat</th><th>Value</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pokemon['stats'] as $stat): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(formatLabel($stat['name'])) ?></td>
                                        <td><?= htmlspecialchars($stat['value']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php elseif (!$errorMessage): ?>
            <div class="message">Use the form above to fetch Pokémon data from the PokeAPI.</div>
        <?php endif; ?>

        <div class="footer">
            <p>PHP cURL fetch + json_decode() + formatted display.</p>
        </div>
    </div>
</body>
</html>
