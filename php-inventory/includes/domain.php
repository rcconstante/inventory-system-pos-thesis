<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

function fetch_user_by_id(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM User WHERE user_id = :user_id LIMIT 1');
    $statement->execute(['user_id' => $userId]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

function fetch_role_options(PDO $pdo): array
{
    $statement = $pdo->query('SELECT role_id, role_type FROM Role ORDER BY role_id ASC');
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function sync_reorder_alerts_for_catalog(PDO $pdo): void
{
    $alreadyInTransaction = $pdo->inTransaction();

    if (!$alreadyInTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->exec('DELETE FROM Reorder_Alert');

        $statement = $pdo->query(
            'SELECT product_id, current_stock, min_stock_level
             FROM Inventory
             WHERE min_stock_level > 0 AND current_stock <= min_stock_level'
        );
        $alerts = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($alerts !== []) {
            $insert = $pdo->prepare(
                'INSERT INTO Reorder_Alert (product_id, current_stock, min_stock_level, alert_status)
                 VALUES (:product_id, :current_stock, :min_stock_level, :alert_status)'
            );

            foreach ($alerts as $alert) {
                $insert->execute([
                    'product_id' => $alert['product_id'],
                    'current_stock' => $alert['current_stock'],
                    'min_stock_level' => $alert['min_stock_level'],
                    'alert_status' => 'ACTIVE',
                ]);
            }
        }

        if (!$alreadyInTransaction) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if (!$alreadyInTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function tokenize_match_text(string $value): array
{
    $tokens = preg_split('/[^a-z0-9]+/i', strtolower($value)) ?: [];
    $tokens = array_filter($tokens, static fn ($token) => strlen($token) >= 3);

    return array_values(array_unique($tokens));
}

function calculate_product_similarity(array $source, array $candidate): array
{
    $score = 0.0;
    $matchedAttributes = [];

    if (!empty($source['category_id']) && (int) $source['category_id'] === (int) $candidate['category_id']) {
        $score += 0.30;
        $matchedAttributes[] = 'category';
    }

    if ($source['product_type'] !== '' && strcasecmp($source['product_type'], $candidate['product_type']) === 0) {
        $score += 0.25;
        $matchedAttributes[] = 'product_type';
    }

    if ($source['compatibility'] !== '' && strcasecmp($source['compatibility'], $candidate['compatibility']) === 0) {
        $score += 0.20;
        $matchedAttributes[] = 'compatibility';
    }

    if ($source['brand'] !== '' && strcasecmp($source['brand'], $candidate['brand']) === 0) {
        $score += 0.10;
        $matchedAttributes[] = 'brand';
    }

    $sourceTokens = tokenize_match_text($source['specification'] . ' ' . $source['description']);
    $candidateTokens = tokenize_match_text($candidate['specification'] . ' ' . $candidate['description']);

    if ($sourceTokens !== [] && $candidateTokens !== []) {
        $overlapCount = count(array_intersect($sourceTokens, $candidateTokens));
        if ($overlapCount > 0) {
            $score += min(0.15, $overlapCount * 0.05);
            $matchedAttributes[] = 'specification';
        }
    }

    return [
        round($score, 2),
        implode(', ', array_unique($matchedAttributes)),
    ];
}

function sync_feature_matches_for_catalog(PDO $pdo): void
{
    $alreadyInTransaction = $pdo->inTransaction();

    $statement = $pdo->query(
        "SELECT
            product_id,
            COALESCE(category_id, 0) AS category_id,
            COALESCE(product_name, '') AS product_name,
            COALESCE(brand, '') AS brand,
            COALESCE(description, '') AS description,
            COALESCE(product_type, '') AS product_type,
            COALESCE(specification, '') AS specification,
            COALESCE(compatibility, '') AS compatibility
         FROM Products
         ORDER BY product_id ASC"
    );
    $products = $statement->fetchAll(PDO::FETCH_ASSOC);

    if (!$alreadyInTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->exec('DELETE FROM Feature_Based_Match');

        if (count($products) >= 2) {
            $insert = $pdo->prepare(
                'INSERT INTO Feature_Based_Match (product_id, alternative_product_id, similarity_score, matched_attribute)
                 VALUES (:product_id, :alternative_product_id, :similarity_score, :matched_attribute)'
            );

            foreach ($products as $source) {
                $matches = [];

                foreach ($products as $candidate) {
                    if ((int) $source['product_id'] === (int) $candidate['product_id']) {
                        continue;
                    }

                    [$score, $matchedAttribute] = calculate_product_similarity($source, $candidate);
                    if ($score < 0.35 || $matchedAttribute === '') {
                        continue;
                    }

                    $matches[] = [
                        'alternative_product_id' => (int) $candidate['product_id'],
                        'similarity_score' => $score,
                        'matched_attribute' => $matchedAttribute,
                        'product_name' => $candidate['product_name'],
                    ];
                }

                usort($matches, static function (array $left, array $right): int {
                    if ($left['similarity_score'] === $right['similarity_score']) {
                        return strcmp($left['product_name'], $right['product_name']);
                    }

                    return $left['similarity_score'] < $right['similarity_score'] ? 1 : -1;
                });

                foreach (array_slice($matches, 0, 3) as $match) {
                    $insert->execute([
                        'product_id' => $source['product_id'],
                        'alternative_product_id' => $match['alternative_product_id'],
                        'similarity_score' => $match['similarity_score'],
                        'matched_attribute' => $match['matched_attribute'],
                    ]);
                }
            }
        }

        if (!$alreadyInTransaction) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if (!$alreadyInTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function fetch_recommendations_for_products(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    if ($productIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
    $statement = $pdo->prepare(
        "SELECT
            fbm.product_id,
            fbm.similarity_score,
            fbm.matched_attribute,
            p.product_name AS alternative_name,
            p.brand AS alternative_brand,
            COALESCE(i.current_stock, 0) AS alternative_stock
         FROM Feature_Based_Match fbm
         INNER JOIN Products p ON p.product_id = fbm.alternative_product_id
         LEFT JOIN Inventory i ON i.product_id = p.product_id
         WHERE fbm.product_id IN ($placeholders)
         ORDER BY fbm.product_id ASC, fbm.similarity_score DESC, p.product_name ASC"
    );
    $statement->execute($productIds);

    $recommendations = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int) $row['alternative_stock'] <= 0) {
            continue;
        }

        $productId = (int) $row['product_id'];
        if (!isset($recommendations[$productId])) {
            $recommendations[$productId] = [];
        }

        $recommendations[$productId][] = $row;
    }

    return $recommendations;
}
