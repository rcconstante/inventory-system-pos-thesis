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

    if ($source['compatibility'] !== '' && $candidate['compatibility'] !== '') {
        if (strcasecmp(trim($source['compatibility']), trim($candidate['compatibility'])) === 0) {
            $score += 0.20;
            $matchedAttributes[] = 'compatibility';
        } else {
            $srcCompat = tokenize_match_text($source['compatibility']);
            $cndCompat = tokenize_match_text($candidate['compatibility']);
            if ($srcCompat !== [] && $cndCompat !== []) {
                $overlap = count(array_intersect($srcCompat, $cndCompat));
                $union = count(array_unique(array_merge($srcCompat, $cndCompat)));
                if ($overlap > 0 && $union > 0) {
                    $score += round(0.20 * ($overlap / $union), 2);
                    $matchedAttributes[] = 'compatibility';
                }
            }
        }
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
                    if ($score < 0.20 || $matchedAttribute === '') {
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

                foreach ($matches as $match) {
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

function ensure_feature_matches_synced(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM Feature_Based_Match')->fetchColumn();
    if ($count === 0) {
        sync_feature_matches_for_catalog($pdo);
    }
}

function fetch_recommendations_for_products(PDO $pdo, array $productIds): array
{
    ensure_feature_matches_synced($pdo);

    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    if ($productIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
    $statement = $pdo->prepare(
        "SELECT
            fbm.product_id,
            fbm.alternative_product_id AS alternative_id,
            fbm.similarity_score,
            fbm.matched_attribute,
            p.product_name AS alternative_name,
            p.brand AS alternative_brand,
            p.price,
            COALESCE(p.specification, '') AS specification,
            COALESCE(p.compatibility, '') AS compatibility,
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

// ─── Batch / FIFO Functions ────────────────────────────────────────────

function create_stock_batch(PDO $pdo, int $productId, int $quantity, ?float $acquisitionCost = null, ?string $manufacturingDate = null, ?string $expirationDate = null, ?string $batchNumber = null): int
{
    if ($batchNumber === null) {
        $batchNumber = 'BATCH-' . $productId . '-' . date('YmdHis') . '-' . random_int(100, 999);
    }

    $statement = $pdo->prepare(
        'INSERT INTO Stock_Batch (product_id, batch_number, acquisition_cost, manufacturing_date, expiration_date, quantity_received, quantity_remaining, is_depleted, status)
         VALUES (:product_id, :batch_number, :acquisition_cost, :manufacturing_date, :expiration_date, :quantity_received, :quantity_remaining, 0, \'ACTIVE\')'
    );
    $statement->execute([
        'product_id' => $productId,
        'batch_number' => $batchNumber,
        'acquisition_cost' => $acquisitionCost,
        'manufacturing_date' => $manufacturingDate,
        'expiration_date' => $expirationDate,
        'quantity_received' => $quantity,
        'quantity_remaining' => $quantity,
    ]);

    $batchId = (int) $pdo->lastInsertId();
    sync_inventory_from_batches($pdo, $productId);

    return $batchId;
}

function sync_inventory_from_batches(PDO $pdo, int $productId): void
{
    $statement = $pdo->prepare(
        "SELECT COALESCE(SUM(quantity_remaining), 0) FROM Stock_Batch WHERE product_id = :product_id AND is_depleted = 0 AND status = 'ACTIVE' AND (expiration_date IS NULL OR expiration_date >= CURDATE())"
    );
    $statement->execute(['product_id' => $productId]);
    $totalStock = (int) $statement->fetchColumn();

    $update = $pdo->prepare('UPDATE Inventory SET current_stock = :stock WHERE product_id = :product_id');
    $update->execute(['stock' => $totalStock, 'product_id' => $productId]);
}

function get_sellable_stock(PDO $pdo, int $productId): int
{
    $statement = $pdo->prepare(
        "SELECT COALESCE(SUM(quantity_remaining), 0) FROM Stock_Batch WHERE product_id = :product_id AND is_depleted = 0 AND status = 'ACTIVE' AND (expiration_date IS NULL OR expiration_date >= CURDATE())"
    );
    $statement->execute(['product_id' => $productId]);
    return (int) $statement->fetchColumn();
}

function deduct_stock_fifo(PDO $pdo, int $productId, int $quantity, int $saleItemId): void
{
    $batchStatement = $pdo->prepare(
        "SELECT batch_id, quantity_remaining
         FROM Stock_Batch
         WHERE product_id = :product_id AND is_depleted = 0 AND status = 'ACTIVE'
           AND (expiration_date IS NULL OR expiration_date >= CURDATE())
         ORDER BY date_received ASC, batch_id ASC
         FOR UPDATE"
    );
    $batchStatement->execute(['product_id' => $productId]);
    $batches = $batchStatement->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $quantity;
    $saleItemBatchInsert = $pdo->prepare(
        'INSERT INTO Sale_Item_Batch (sale_item_id, batch_id, quantity_from_batch) VALUES (:sale_item_id, :batch_id, :qty)'
    );
    $batchUpdate = $pdo->prepare(
        'UPDATE Stock_Batch SET quantity_remaining = :qty_remaining, is_depleted = :is_depleted WHERE batch_id = :batch_id'
    );

    foreach ($batches as $batch) {
        if ($remaining <= 0) {
            break;
        }

        $batchId = (int) $batch['batch_id'];
        $available = (int) $batch['quantity_remaining'];
        $take = min($remaining, $available);

        $newRemaining = $available - $take;
        $isDepleted = $newRemaining <= 0 ? 1 : 0;

        $batchUpdate->execute([
            'qty_remaining' => $newRemaining,
            'is_depleted' => $isDepleted,
            'batch_id' => $batchId,
        ]);

        $saleItemBatchInsert->execute([
            'sale_item_id' => $saleItemId,
            'batch_id' => $batchId,
            'qty' => $take,
        ]);

        $remaining -= $take;
    }

    if ($remaining > 0) {
        throw new RuntimeException('Insufficient non-expired batch stock for product ID ' . $productId);
    }

    sync_inventory_from_batches($pdo, $productId);
}

function fetch_batches_for_product(PDO $pdo, int $productId): array
{
    $statement = $pdo->prepare(
        "SELECT batch_id, batch_number, acquisition_cost, manufacturing_date, expiration_date, quantity_received, quantity_remaining, date_received, is_depleted, COALESCE(status, 'ACTIVE') AS status
         FROM Stock_Batch
         WHERE product_id = :product_id
         ORDER BY date_received ASC, batch_id ASC"
    );
    $statement->execute(['product_id' => $productId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function update_stock_batch(PDO $pdo, int $batchId, array $data): void
{
    $statement = $pdo->prepare(
        'UPDATE Stock_Batch
         SET batch_number = :batch_number,
             acquisition_cost = :acquisition_cost,
             manufacturing_date = :manufacturing_date,
             expiration_date = :expiration_date,
             quantity_remaining = :quantity_remaining,
             status = :status,
             is_depleted = CASE WHEN :status2 != \'ACTIVE\' OR :qty2 <= 0 THEN 1 ELSE 0 END
         WHERE batch_id = :batch_id'
    );
    $statement->execute([
        'batch_number' => $data['batch_number'],
        'acquisition_cost' => $data['acquisition_cost'],
        'manufacturing_date' => $data['manufacturing_date'],
        'expiration_date' => $data['expiration_date'],
        'quantity_remaining' => $data['quantity_remaining'],
        'status' => $data['status'],
        'status2' => $data['status'],
        'qty2' => $data['quantity_remaining'],
        'batch_id' => $batchId,
    ]);

    // Get product_id from batch to sync inventory
    $pidStmt = $pdo->prepare('SELECT product_id FROM Stock_Batch WHERE batch_id = :batch_id');
    $pidStmt->execute(['batch_id' => $batchId]);
    $productId = (int) $pidStmt->fetchColumn();
    if ($productId > 0) {
        sync_inventory_from_batches($pdo, $productId);
    }
}

function delete_stock_batch(PDO $pdo, int $batchId): void
{
    // Get product_id before deletion
    $pidStmt = $pdo->prepare('SELECT product_id FROM Stock_Batch WHERE batch_id = :batch_id');
    $pidStmt->execute(['batch_id' => $batchId]);
    $productId = (int) $pidStmt->fetchColumn();

    $statement = $pdo->prepare('DELETE FROM Stock_Batch WHERE batch_id = :batch_id');
    $statement->execute(['batch_id' => $batchId]);

    if ($productId > 0) {
        sync_inventory_from_batches($pdo, $productId);
    }
}

function restore_stock_to_batches(PDO $pdo, int $saleItemId): void
{
    $statement = $pdo->prepare(
        'SELECT sib.batch_id, sib.quantity_from_batch, sb.product_id
         FROM Sale_Item_Batch sib
         JOIN Stock_Batch sb ON sb.batch_id = sib.batch_id
         WHERE sib.sale_item_id = :sale_item_id'
    );
    $statement->execute(['sale_item_id' => $saleItemId]);
    $entries = $statement->fetchAll(PDO::FETCH_ASSOC);

    $batchUpdate = $pdo->prepare(
        'UPDATE Stock_Batch SET quantity_remaining = quantity_remaining + :qty, is_depleted = 0 WHERE batch_id = :batch_id'
    );

    $productIds = [];
    foreach ($entries as $entry) {
        $batchUpdate->execute([
            'qty' => $entry['quantity_from_batch'],
            'batch_id' => $entry['batch_id'],
        ]);
        $productIds[] = (int) $entry['product_id'];
    }

    foreach (array_unique($productIds) as $pid) {
        sync_inventory_from_batches($pdo, $pid);
    }
}
