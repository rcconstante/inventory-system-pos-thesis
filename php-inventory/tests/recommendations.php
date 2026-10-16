<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/domain.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$tire = [
    'category_id' => 1, 'product_type' => 'Tire', 'compatibility' => 'Honda Click 125',
    'specification' => '80/90-14', 'description' => '', 'brand' => 'Example',
];
check(is_eligible_product_alternative($tire, $tire), 'Matching tire should qualify.');
check(!is_eligible_product_alternative($tire, array_replace($tire, ['category_id' => 2])), 'Cross-category battery must not qualify.');
check(!is_eligible_product_alternative($tire, array_replace($tire, ['product_type' => 'Battery'])), 'Different type within a broad category must not qualify.');
check(!is_eligible_product_alternative($tire, array_replace($tire, ['specification' => '90/90-14'])), 'Different tire size must not qualify.');
check(!is_eligible_product_alternative($tire, array_replace($tire, ['specification' => ''])), 'Missing required specification must not qualify.');
check(!is_eligible_product_alternative($tire, array_replace($tire, ['compatibility' => 'Honda Click 150'])), 'Shared vehicle words must not establish compatibility.');
check(!is_eligible_product_alternative($tire, array_replace($tire, ['compatibility' => ''])), 'Missing vehicle data must not qualify.');
check(!is_eligible_product_alternative(array_replace($tire, ['compatibility' => '']), $tire), 'Unknown source compatibility must not qualify.');
check(!is_eligible_product_alternative(array_replace($tire, ['product_type' => '']), $tire), 'Unknown source type must not qualify.');
check(!is_eligible_product_alternative(array_replace($tire, ['category_id' => null]), array_replace($tire, ['category_id' => null])), 'Uncategorized products must not qualify.');
check(is_eligible_product_alternative($tire, array_replace($tire, ['product_type' => ' TIRE ', 'specification' => ' 80/90-14 ', 'compatibility' => ' HONDA   CLICK 125 '])), 'Case and whitespace must not exclude a match.');
check(is_eligible_product_alternative($tire, array_replace($tire, ['compatibility' => 'Yamaha Mio, Honda Click 125'])), 'Alternative covering the source vehicle must qualify.');
check(!is_eligible_product_alternative(array_replace($tire, ['compatibility' => 'Honda Click 125, Yamaha Mio']), $tire), 'Partial coverage of source vehicles must not qualify.');
check(is_eligible_product_alternative($tire, array_replace($tire, ['compatibility' => 'Universal'])), 'Explicit universal alternative must qualify.');
check(!is_eligible_product_alternative(array_replace($tire, ['compatibility' => 'Universal']), $tire), 'Specific vehicle must not replace universal compatibility.');
check(!is_eligible_product_alternative(array_replace($tire, ['compatibility' => ', ,']), array_replace($tire, ['compatibility' => ','])), 'Empty vehicle lists must not qualify.');
check(!is_eligible_product_alternative(array_replace($tire, ['compatibility' => ', ,']), array_replace($tire, ['compatibility' => 'Universal'])), 'Universal candidate must not bypass missing source vehicle data.');
check(is_eligible_product_alternative(array_replace($tire, ['specification' => '']), $tire), 'Unspecified source must not invent a specification requirement.');
[$score] = calculate_product_similarity($tire, $tire);
check($score === 0.85, 'Existing weights must remain unchanged; short numeric tokens do not add specification points.');
[$fullScore] = calculate_product_similarity(array_replace($tire, ['specification' => 'tubeless radial reinforced']), array_replace($tire, ['specification' => 'tubeless radial reinforced']));
check($fullScore === 1.0, 'All five features must total 1.00 at maximum.');
[$categoryScore] = calculate_product_similarity(array_replace($tire, ['product_type' => '', 'compatibility' => '', 'brand' => '', 'specification' => '']), $tire);
check($categoryScore === 0.30, 'Category weight must remain 0.30.');

$ranked = [
    ['alternative_id' => 5, 'alternative_name' => 'Cheap', 'price' => '1.00', 'similarity_score' => '0.60'],
    ['alternative_id' => 4, 'alternative_name' => 'Expensive', 'price' => '200.00', 'similarity_score' => '0.90'],
    ['alternative_id' => 3, 'alternative_name' => 'Beta', 'price' => '100.00', 'similarity_score' => '0.90'],
    ['alternative_id' => 2, 'alternative_name' => 'alpha', 'price' => '100.00', 'similarity_score' => '0.90'],
    ['alternative_id' => 1, 'alternative_name' => 'Alpha', 'price' => '100.00', 'similarity_score' => '0.90'],
];
usort($ranked, 'compare_product_recommendations');
check(array_column($ranked, 'alternative_id') === [1, 2, 3, 4, 5], 'Ranking must prioritize score, price, alphabetical name, then ID.');

// Opt in with a local disposable server DSN, without dbname. A unique test database is created and removed.
$dsn = getenv('INVENTORY_TEST_DB_DSN');
if ($dsn !== false && $dsn !== '') {
    $pdo = new PDO($dsn, getenv('INVENTORY_TEST_DB_USER') ?: 'root', getenv('INVENTORY_TEST_DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $database = 'inventory_recommendation_test_' . bin2hex(random_bytes(6));
    $pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
    try {
        $pdo->exec('USE `' . $database . '`');
        $schema = file_get_contents(__DIR__ . '/../database.sql');
        preg_match_all('/CREATE TABLE IF NOT EXISTS .*?;/s', $schema, $tables);
        foreach ($tables[0] as $table) {
            $pdo->exec($table);
        }
        ensure_product_lifecycle_schema($pdo);
        $pdo->exec("INSERT INTO Category (category_id, category_name) VALUES (1, 'Tires'), (2, 'Batteries')");
        $insert = $pdo->prepare('INSERT INTO Products (product_id, product_name, price, category_id, product_type, specification, compatibility, brand, description, product_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $fixtures = [
            [1, 'Source tire', 100, 1, 'Tire', '80/90-14', 'Honda Click 125', 'Example', '', 'ACTIVE'],
            [2, 'Battery', 50, 2, 'Battery', '12V', 'Honda Click 125', 'Example', '', 'ACTIVE'],
            [3, 'Wrong size', 50, 1, 'Tire', '90/90-14', 'Honda Click 125', 'Example', '', 'ACTIVE'],
            [4, 'Wrong vehicle', 50, 1, 'Tire', '80/90-14', 'Honda Click 150', 'Example', '', 'ACTIVE'],
            [5, 'Zero stock', 50, 1, 'Tire', '80/90-14', 'Honda Click 125', 'Example', '', 'ACTIVE'],
            [6, 'Archived tire', 50, 1, 'Tire', '80/90-14', 'Honda Click 125', 'Example', '', 'ARCHIVED'],
            [7, 'Expensive tire', 200, 1, 'Tire', '80/90-14', 'Honda Click 125', 'Example', '', 'ACTIVE'],
            [8, 'Beta tire', 100, 1, 'Tire', '80/90-14', 'Honda Click 125', 'Example', '', 'ACTIVE'],
            [9, 'alpha tire', 100, 1, 'Tire', '80/90-14', 'Honda Click 125', 'Example', '', 'ACTIVE'],
            [10, 'Cheap lower score', 1, 1, 'Tire', '80/90-14', 'Honda Click 125', 'Different', '', 'ACTIVE'],
            [11, 'No alternatives', 100, 2, 'Unique', '24V', 'Unique vehicle', 'Example', '', 'ACTIVE'],
            [12, 'Expired tire', 50, 1, 'Tire', '80/90-14', 'Honda Click 125', 'Example', '', 'ACTIVE'],
            [13, 'Disposed tire', 50, 1, 'Tire', '80/90-14', 'Honda Click 125', 'Example', '', 'ACTIVE'],
        ];
        $stock = $pdo->prepare('INSERT INTO Inventory (product_id, current_stock) VALUES (?, ?)');
        $batch = $pdo->prepare('INSERT INTO Stock_Batch (product_id, batch_number, quantity_received, quantity_remaining, expiration_date, status) VALUES (?, ?, 10, ?, ?, ?)');
        foreach ($fixtures as $fixture) {
            $insert->execute($fixture);
            $quantity = in_array($fixture[0], [1, 5], true) ? 0 : 10;
            $stock->execute([$fixture[0], $quantity]);
            $batch->execute([$fixture[0], 'TEST-' . $fixture[0], $quantity, $fixture[0] === 12 ? '2000-01-01' : null, $fixture[0] === 13 ? 'DISPOSED' : 'ACTIVE']);
        }
        // Simulate a populated legacy cache containing invalid pairs, before any rebuild.
        $pdo->exec("INSERT INTO Feature_Based_Match (product_id, alternative_product_id, similarity_score, matched_attribute) SELECT 1, product_id, 0.90, 'compatibility' FROM Products WHERE product_id BETWEEN 2 AND 9 OR product_id IN (12, 13)");
        $legacy = fetch_recommendations_for_products($pdo, [1]);
        check(array_column($legacy[1], 'alternative_id') === [9, 8, 7], 'Legacy cache must filter invalid, archived, unavailable, expired and disposed alternatives immediately.');
        sync_feature_matches_for_catalog($pdo);
        $results = fetch_recommendations_for_products($pdo, [1, 1, 11, 999999]);
        check(array_column($results[1], 'alternative_id') === [9, 8, 7, 10], 'Database results must follow eligibility and full ranking.');
        check(!isset($results[11]) && !isset($results[999999]), 'No suitable alternatives must return an empty result.');
        check(fetch_recommendations_for_products($pdo, []) === [], 'Empty source list must return no recommendations.');
        $pdo->exec("UPDATE Products SET product_status = 'ARCHIVED' WHERE product_id = 1");
        check(fetch_recommendations_for_products($pdo, [1]) === [], 'Archived source must not produce recommendations.');
        $pdo->exec("UPDATE Products SET product_status = 'ACTIVE' WHERE product_id = 1");
        $pdo->exec('UPDATE Inventory SET current_stock = 0');
        $pdo->exec('UPDATE Stock_Batch SET quantity_remaining = 0, is_depleted = 1');
        check(fetch_recommendations_for_products($pdo, [1]) === [], 'No stock must produce no alternatives.');
        $pdo->exec('UPDATE Products SET category_id = NULL');
        sync_feature_matches_for_catalog($pdo);
        check((int) $pdo->query('SELECT COUNT(*) FROM Feature_Based_Match')->fetchColumn() === 0, 'Catalog without valid pairs must have no cached matches.');
        check(fetch_recommendations_for_products($pdo, [1]) === [], 'Empty match cache must safely return no alternatives.');
        echo "Database integration checks passed.\n";
    } finally {
        $pdo->exec('DROP DATABASE `' . $database . '`');
    }
} else {
    echo "Database integration checks skipped: set INVENTORY_TEST_DB_DSN to a disposable MySQL/MariaDB server.\n";
}
echo "Passed {$checks} recommendation checks.\n";
