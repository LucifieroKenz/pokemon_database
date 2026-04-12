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
    if (isset($_GET['random'])) {
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
    } elseif (!empty($_GET['pokemon'])) {
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
            font-family: 'Courier New', monospace;
            background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 50%, #1e40af 100%);
            margin: 0;
            padding: 0;
            color: #f1f5f9;
            min-height: 100vh;
        }
        .container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 1.5rem;
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 50%, #0369a1 100%);
            border-radius: 16px;
            box-shadow: 0 15px 35px rgba(6, 182, 212, 0.3), inset 0 1px 0 rgba(255,255,255,0.1);
            border: 3px solid #cbd5e1;
            position: relative;
        }
        .container::before {
            content: '';
            position: absolute;
            top: -3px;
            left: -3px;
            right: -3px;
            bottom: -3px;
            background: linear-gradient(45deg, #a78bfa, #8b5cf6, #a78bfa);
            border-radius: 19px;
            z-index: -1;
        }
        h1 {
            margin-top: 0;
            color: #f1f5f9;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
            font-weight: bold;
            text-align: center;
            background: linear-gradient(45deg, #f1f5f9, #e2e8f0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .api-meta {
            margin-bottom: 1.5rem;
            color: #f1f5f9;
            text-align: center;
            font-style: italic;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }
        form {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            justify-content: center;
        }
        input[type="text"] {
            flex: 1 1 250px;
            padding: 0.85rem 1rem;
            border: 2px solid #cbd5e1;
            border-radius: 8px;
            font-size: 1rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: #1e293b;
            font-family: 'Courier New', monospace;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #a78bfa;
            box-shadow: 0 0 8px rgba(167, 139, 250, 0.3), inset 0 2px 4px rgba(0,0,0,0.1);
        }
        button {
            padding: 0.85rem 1.25rem;
            border: 2px solid #cbd5e1;
            border-radius: 8px;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: #1e293b;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-family: 'Courier New', monospace;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        button:hover {
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(167, 139, 250, 0.3);
            border-color: #a78bfa;
        }
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 2px solid white;
            font-weight: bold;
            background: #ffffff;
            color: #f1f5f9;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .error {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            color: #dc2626;
            border-color: #f87171;
        }
        .pokemon-card {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 1.5rem;
            align-items: start;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 1.5rem;
            border-radius: 12px;
            border: 2px solid #cbd5e1;
            box-shadow: 0 4px 8px rgba(6, 182, 212, 0.15);
        }
        .pokemon-card img {
            width: 100%;
            border-radius: 16px;
            border: 3px solid #cbd5e1;
            background: #ffffff;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .pokemon-details {
            display: grid;
            gap: 1rem;
        }
        .pokemon-details h2 {
            margin: 0 0 0.25rem;
            text-transform: capitalize;
            color: #0ea5e9;
            font-size: 1.8rem;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
            border-bottom: 2px solid #a78bfa;
            padding-bottom: 0.5rem;
        }
        .pokemon-details strong {
            color: #0ea5e9;
        }

        .type-ability-text {
            color: #3C4142;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(120px, 1fr));
            gap: 0.75rem 1.5rem;
        }
        .detail-grid div {
            background: #ffffff;
            padding: 0.85rem;
            border-radius: 10px;
            border: 2px solid #cbd5e1;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            color: #3C4142;
        }
        .detail-grid strong {
            display: block;
            margin-bottom: 0.35rem;
            color: #0ea5e9;
            font-size: 0.95rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stats-table {
            width: 100%;
            border-collapse: collapse;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(6, 182, 212, 0.1);
            border: 2px solid #cbd5e1;
        }
        .stats-table th,
        .stats-table td {
            text-align: left;
            padding: 0.65rem 0.5rem;
            border-bottom: 1px solid #cbd5e1;
            color: #1e293b;
        }
        .stats-table td {
            color: #3C4142;
        }
        .stats-table th {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: #f1f5f9;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }
        .stats-table tr:nth-child(even) {
            background: #f8fafc;
        }
        .stats-table tr:hover {
            background: #e0f2fe;
        }
        .footer {
            margin-top: 2rem;
            color: #f1f5f9;
            font-size: 0.95rem;
            text-align: center;
            font-style: italic;
            border-top: 2px solid #a78bfa;
            padding-top: 1rem;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }
        @media (max-width: 720px) {
            .pokemon-card {
                grid-template-columns: 1fr;
            }
            .detail-grid {
                grid-template-columns: 1fr;
            }
            .container {
                margin: 1rem;
                padding: 1rem;
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
            <div class="message" style="background:linear-gradient(135deg,#e0f2fe 0%,#bae6fd 100%);color:#0c4a6e;border-color:#0ea5e9;font-weight:bold;box-shadow:0 2px 4px rgba(6, 182, 212, 0.2);">✓ <?= htmlspecialchars($savedMessage) ?></div>
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
                        <p><strong>Types:</strong> <span class="type-ability-text"><?= htmlspecialchars(renderCommaList($pokemon['types'])) ?></span></p>
                        <p><strong>Abilities:</strong> <span class="type-ability-text"><?= htmlspecialchars(renderCommaList($pokemon['abilities'])) ?></span></p>
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
            <p>This project is dedicated to the public domain. No rights reserved.</p>
        </div>
    </div>
</body>
</html>
