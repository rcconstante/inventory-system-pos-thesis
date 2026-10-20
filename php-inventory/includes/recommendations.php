<?php
declare(strict_types=1);

function vehicle_name_key(string $value): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
}

function save_product_type_option(PDO $pdo, string $name): array
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    $key = vehicle_name_key($name);
    if ($name === '' || strlen($name) > 50 || $key === '' || str_contains($name, ',')) throw new RuntimeException('Enter one product type of up to 50 characters, without commas.');
    $pdo->prepare('INSERT INTO Product_Type (type_name, type_key) VALUES (?, ?) ON DUPLICATE KEY UPDATE type_id = LAST_INSERT_ID(type_id)')->execute([$name, $key]);
    $lookup = $pdo->prepare('SELECT type_id, type_name FROM Product_Type WHERE type_key = ?');
    $lookup->execute([$key]);
    return $lookup->fetch(PDO::FETCH_ASSOC);
}

function resolve_product_type(PDO $pdo, string $name): string
{
    if ($name === '') return '';
    $lookup = $pdo->prepare('SELECT type_name FROM Product_Type WHERE type_key = ?');
    $lookup->execute([vehicle_name_key($name)]);
    $canonical = $lookup->fetchColumn();
    if ($canonical === false) throw new RuntimeException('Select an existing product type, or add the new type first.');
    return $canonical;
}

function essential_fitment_fields(): array
{
    return ['size' => 'Tire size', 'position' => 'Position', 'voltage' => 'Voltage', 'viscosity' => 'Oil viscosity'];
}

function parse_essential_fitment(mixed $input): array
{
    if (!is_array($input) || count($input) > count(essential_fitment_fields())) throw new RuntimeException('Invalid essential fitment fields.');
    $values = [];
    foreach ($input as $key => $value) {
        if (!isset(essential_fitment_fields()[$key]) || !is_string($value) || strlen($value) > 100) throw new RuntimeException('Invalid essential fitment value.');
        $value = trim($value);
        if ($value === '') continue;
        if ($key === 'position' && !in_array($value, ['front', 'rear', 'front/rear'], true)) throw new RuntimeException('Select a valid position.');
        if ($key === 'size' && tire_size_key($value) === null) throw new RuntimeException('Use a single tire size, for example 80/90-14 or 2.75-17.');
        $values[$key] = $value;
    }
    ksort($values);
    return $values;
}

function product_specification_display(array $product): string
{
    $values = json_decode((string) ($product['essential_fitment'] ?? '{}'), true);
    if (!is_array($values)) return (string) ($product['specification'] ?? '');
    $lines = [];
    foreach ($values as $key => $value) {
        if (isset(essential_fitment_fields()[$key]) && is_string($value)) $lines[] = essential_fitment_fields()[$key] . ': ' . $value;
    }
    return trim((string) ($product['specification'] ?? '') . ($lines !== [] ? "\n\nEssential fitment:\n" . implode("\n", $lines) : ''));
}

function tire_size_key(string $text): ?string
{
    preg_match_all('/(?<![\d.])(?:\d{2,3}\s*\/\s*\d{2,3}\s*[-r]\s*\d{2}|\d\.\d{2}\s*-\s*\d{2})(?![\d.])/i', $text, $matches);
    $sizes = array_unique(array_map(static fn (string $size): string => strtolower(preg_replace('/\s+/', '', $size) ?? $size), $matches[0]));
    return count($sizes) === 1 ? array_values($sizes)[0] : null;
}

function essential_fitment_for_product(array $product): ?array
{
    try {
        $fitment = json_decode((string) ($product['essential_fitment'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($fitment)) return null;
    $fitment = array_intersect_key($fitment, essential_fitment_fields());
    foreach ($fitment as $key => $value) {
        if (!is_string($key) || !is_string($value)) return null;
        $fitment[$key] = normalize_recommendation_attribute($value);
    }
    if (preg_match('/\b(?:tires?|tyres?)\b/i', (string) ($product['product_type'] ?? ''))) {
        $size = tire_size_key($fitment['size'] ?? (string) ($product['specification'] ?? ''));
        if ($size === null) return null;
        $fitment['size'] = $size;
    }
    return $fitment;
}

function essential_fitment_matches(array $source, array $candidate): bool
{
    $required = essential_fitment_for_product($source);
    $available = essential_fitment_for_product($candidate);
    if ($required === null || $available === null) return false;
    foreach ($required as $key => $value) {
        if ($key === 'position' && ($available[$key] ?? '') === 'front/rear' && in_array($value, ['front', 'rear', 'front/rear'], true)) continue;
        if (!isset($available[$key]) || $available[$key] !== $value) return false;
    }
    // Unmigrated non-tire products retain conservative behavior until requirements are entered.
    if ($required === [] && trim((string) ($source['specification'] ?? '')) !== '') {
        return normalize_recommendation_attribute($source['specification'])
            === normalize_recommendation_attribute((string) ($candidate['specification'] ?? ''));
    }
    return true;
}

function fetch_vehicle_options(PDO $pdo): array
{
    return $pdo->query('SELECT vehicle_id, vehicle_name, is_active FROM Vehicle_Compatibility ORDER BY vehicle_name, vehicle_id')->fetchAll(PDO::FETCH_ASSOC);
}
function refresh_product_vehicle_names(PDO $pdo): void
{
    $pdo->exec('SET SESSION group_concat_max_len = 65535');
    $pdo->exec("UPDATE Products p SET compatibility = COALESCE((
        SELECT GROUP_CONCAT(v.vehicle_name ORDER BY v.vehicle_name SEPARATOR ', ')
        FROM Product_Vehicle pv JOIN Vehicle_Compatibility v ON v.vehicle_id = pv.vehicle_id
        WHERE pv.product_id = p.product_id
    ), '')");
}

function save_product_vehicles(PDO $pdo, int $productId, mixed $input): void
{
    if (!is_array($input) || count($input) > 30) throw new RuntimeException('Select up to 30 compatible models.');
    $ids = [];
    foreach ($input as $id) {
        if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) throw new RuntimeException('Invalid compatibility selection.');
        $ids[(int) $id] = (int) $id;
    }
    $existing = $pdo->prepare('SELECT vehicle_id FROM Product_Vehicle WHERE product_id = ?');
    $existing->execute([$productId]);
    $previous = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
    $names = [];
    $lookup = $pdo->prepare('SELECT vehicle_name, is_active FROM Vehicle_Compatibility WHERE vehicle_id = ? FOR UPDATE');
    foreach ($ids as $id) {
        $lookup->execute([$id]);
        $vehicle = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$vehicle || (!(bool) $vehicle['is_active'] && !in_array($id, $previous, true))) throw new RuntimeException('An unavailable compatibility entry was selected. Refresh the form.');
        $names[] = $vehicle['vehicle_name'];
    }
    if (count($names) > 1 && in_array('universal', array_map('vehicle_name_key', $names), true)) throw new RuntimeException('Select Universal alone, or select specific vehicles.');
    $pdo->prepare('DELETE FROM Product_Vehicle WHERE product_id = ?')->execute([$productId]);
    $insert = $pdo->prepare('INSERT INTO Product_Vehicle (product_id, vehicle_id) VALUES (?, ?)');
    foreach ($ids as $id) $insert->execute([$productId, $id]);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    $pdo->prepare('UPDATE Products SET compatibility = ? WHERE product_id = ?')->execute([implode(', ', $names), $productId]);
}

function save_vehicle_option(PDO $pdo, ?int $id, string $name): int
{
    $isEdit = $id !== null;
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    $key = vehicle_name_key($name);
    if ($name === '' || strlen($name) > 100 || str_contains($name, ',') || $key === '') throw new RuntimeException('Use a compatibility name of up to 100 characters without commas.');
    if ($id !== null) {
        $statement = $pdo->prepare('SELECT vehicle_id FROM Vehicle_Compatibility WHERE vehicle_id = ? FOR UPDATE');
        $statement->execute([$id]);
        if (!$statement->fetchColumn()) throw new RuntimeException('Compatibility entry not found.');
    }
    $find = $pdo->prepare('SELECT vehicle_id FROM Vehicle_Alias WHERE alias_key = ? FOR UPDATE');
    $find->execute([$key]);
    $owner = $find->fetchColumn();
    if ($owner !== false && (int) $owner !== $id) throw new RuntimeException('That compatibility name already exists.');
    if ($id === null) {
        $pdo->prepare('INSERT INTO Vehicle_Compatibility (vehicle_name) VALUES (?)')->execute([$name]);
        $id = (int) $pdo->lastInsertId();
    } else {
        $pdo->prepare('UPDATE Vehicle_Compatibility SET vehicle_name = ? WHERE vehicle_id = ?')->execute([$name, $id]);
    }
    $pdo->prepare('DELETE FROM Vehicle_Alias WHERE vehicle_id = ?')->execute([$id]);
    $pdo->prepare('INSERT INTO Vehicle_Alias (alias_key, vehicle_id) VALUES (?, ?)')->execute([$key, $id]);
    if ($isEdit) refresh_product_vehicle_names($pdo);
    return $id;
}
function find_pos_recommendation_sources(PDO $pdo, string $term, int $categoryId): array
{
    if (trim($term) === '') return [];
    $query = "SELECT p.product_id, p.product_name,
        COALESCE(stock.sellable_stock, 0) AS sellable_stock
        FROM Products p LEFT JOIN Category c ON c.category_id = p.category_id
        LEFT JOIN (SELECT product_id, SUM(quantity_remaining) AS sellable_stock FROM Stock_Batch
            WHERE is_depleted = 0 AND status = 'ACTIVE' AND quantity_remaining > 0
            AND (expiration_date IS NULL OR expiration_date >= CURDATE()) GROUP BY product_id) stock ON stock.product_id = p.product_id
        WHERE COALESCE(p.product_status, 'ACTIVE') = 'ACTIVE'
        AND (? = 0 OR p.category_id = ?)
        AND (p.product_name LIKE ? OR p.brand LIKE ? OR p.compatibility LIKE ? OR c.category_name LIKE ?)
        ORDER BY p.product_id";
    $like = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $term) . '%';
    $query = str_replace('LIKE ?', "LIKE ? ESCAPE '='", $query);
    $statement = $pdo->prepare($query);
    $statement->execute([$categoryId, $categoryId, $like, $like, $like, $like]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $exact = array_filter($rows, static fn (array $row): bool => normalize_recommendation_attribute($row['product_name']) === normalize_recommendation_attribute($term));
    $sources = $exact !== [] ? $exact : $rows;
    return array_map(static fn (array $row): int => (int) $row['product_id'], array_values(array_filter($sources, static fn (array $row): bool => (int) $row['sellable_stock'] <= 0)));
}
