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
    $lookup = $pdo->prepare('SELECT type_name FROM Product_Type WHERE type_key = ? FOR UPDATE');
    $lookup->execute([vehicle_name_key($name)]);
    $canonical = $lookup->fetchColumn();
    if ($canonical === false) throw new RuntimeException('Select an existing product type, or add the new type first.');
    return $canonical;
}

function essential_fitment_fields(): array
{
    return ['size' => 'Tire size', 'position' => 'Position', 'voltage' => 'Voltage', 'viscosity' => 'Oil viscosity'] + ($GLOBALS['custom_product_properties'] ?? []);
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

function normalize_fitment_value(string $key, string $value): string
{
    $value = normalize_recommendation_attribute($value);
    if ($key === 'voltage' && preg_match('/^([0-9]+(?:[.][0-9]+)?)\s*(?:v|volts?)?$/i', $value, $match)) return (string) (float) $match[1] . 'v';
    if ($key === 'viscosity' && preg_match('/^([0-9]{1,2})\s*w\s*-?\s*([0-9]{2})$/i', $value, $match)) return $match[1] . 'w-' . $match[2];
    return $value;
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
        if (!is_string($value)) return null;
        if (trim($value) === '') { unset($fitment[$key]); continue; }
        $fitment[$key] = normalize_fitment_value($key, $value);
    }
    $type = (string) ($product['product_type'] ?? '');
    $specification = (string) ($product['specification'] ?? '');
    if (preg_match('/\b(?:tires?|tyres?)\b/i', $type)) {
        $size = tire_size_key($fitment['size'] ?? $specification);
        if ($size === null) return null;
        $fitment['size'] = $size;
    }
    // Only known essential values are extracted; marketing words do not determine fitment.
    foreach (['voltage' => '/(?<![0-9.])([0-9]+(?:[.][0-9]+)?)\s*(?:volts?|v)\b/i', 'viscosity' => '/\b([0-9]{1,2})\s*w\s*-?\s*([0-9]{2})\b/i'] as $key => $pattern) {
        if (isset($fitment[$key])) continue;
        preg_match_all($pattern, $specification, $matches, PREG_SET_ORDER);
        $values = [];
        foreach ($matches as $match) $values[] = normalize_fitment_value($key, $key === 'voltage' ? $match[1] : $match[1] . 'w-' . $match[2]);
        $values = array_values(array_unique($values));
        if (count($values) > 1) return null;
        if ($values !== []) $fitment[$key] = $values[0];
    }
    if (preg_match('/battery/i', $type) && !isset($fitment['voltage'])) return null;
    if (preg_match('/(?:engine|gear)\s*oil/i', $type) && !isset($fitment['viscosity'])) return null;
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
    return true;
}

function fetch_vehicle_options(PDO $pdo): array
{
    return $pdo->query('SELECT vehicle_id, vehicle_name, is_active FROM Vehicle_Compatibility ORDER BY vehicle_name, vehicle_id')->fetchAll(PDO::FETCH_ASSOC);
}
function refresh_product_vehicle_names(PDO $pdo, ?int $vehicleId = null): void
{
    $pdo->exec('SET SESSION group_concat_max_len = 65535');
    $statement = $pdo->prepare("UPDATE Products p SET compatibility = COALESCE((
        SELECT GROUP_CONCAT(v.vehicle_name ORDER BY v.vehicle_name SEPARATOR ', ')
        FROM Product_Vehicle pv JOIN Vehicle_Compatibility v ON v.vehicle_id = pv.vehicle_id
        WHERE pv.product_id = p.product_id
    ), '')" . ($vehicleId === null ? '' : ' WHERE p.product_id IN (SELECT product_id FROM Product_Vehicle WHERE vehicle_id = ?)'));
    $statement->execute($vehicleId === null ? [] : [$vehicleId]);
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
    if ($isEdit) refresh_product_vehicle_names($pdo, $id);
    return $id;
}

function update_product_type_option(PDO $pdo, int $id, string $name): void
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    $key = vehicle_name_key($name);
    if ($name === '' || strlen($name) > 50 || $key === '' || str_contains($name, ',')) throw new RuntimeException('Enter one product type of up to 50 characters without commas.');
    $statement = $pdo->prepare('SELECT type_name FROM Product_Type WHERE type_id = ? FOR UPDATE');
    $statement->execute([$id]);
    $previous = $statement->fetchColumn();
    if ($previous === false) throw new RuntimeException('Product type not found. Refresh the page.');
    $duplicate = $pdo->prepare('SELECT type_id FROM Product_Type WHERE type_key = ? AND type_id <> ?');
    $duplicate->execute([$key, $id]);
    if ($duplicate->fetchColumn() !== false) throw new RuntimeException('That product type already exists.');
    $pdo->prepare('UPDATE Product_Type SET type_name = ?, type_key = ? WHERE type_id = ?')->execute([$name, $key, $id]);
    $pdo->prepare('UPDATE Products SET product_type = ? WHERE product_type = ?')->execute([$name, $previous]);
}

function delete_product_option(PDO $pdo, string $kind, int $id): void
{
    if ($kind === 'type') {
        $lock = $pdo->prepare('SELECT type_name FROM Product_Type WHERE type_id = ? FOR UPDATE');
        $lock->execute([$id]);
        $name = $lock->fetchColumn();
        if ($name === false) throw new RuntimeException('Product type not found. Refresh the page.');
        $used = $pdo->prepare('SELECT product_id FROM Products WHERE product_type = ? LIMIT 1 FOR UPDATE');
        $used->execute([$name]);
        if ($used->fetchColumn() !== false) throw new RuntimeException('This product type is used by products and cannot be deleted.');
        $pdo->prepare('DELETE FROM Product_Type WHERE type_id = ?')->execute([$id]);
    } elseif ($kind === 'vehicle') {
        $lock = $pdo->prepare('SELECT vehicle_id FROM Vehicle_Compatibility WHERE vehicle_id = ? FOR UPDATE');
        $lock->execute([$id]);
        if ($lock->fetchColumn() === false) throw new RuntimeException('Compatibility entry not found. Refresh the page.');
        $used = $pdo->prepare('SELECT product_id FROM Product_Vehicle WHERE vehicle_id = ? LIMIT 1 FOR UPDATE');
        $used->execute([$id]);
        if ($used->fetchColumn() !== false) throw new RuntimeException('This compatibility entry is used by products and cannot be deleted.');
        $pdo->prepare('DELETE FROM Vehicle_Compatibility WHERE vehicle_id = ?')->execute([$id]);
    } else throw new RuntimeException('Invalid product option.');
}
function find_pos_recommendation_sources(PDO $pdo, string $term, int $categoryId): array
{
    if (trim($term) === '') return [];
    $codeId = preg_match('/^p0*([1-9][0-9]*)$/i', trim($term), $match) ? (int) $match[1] : 0;
    $statement = $pdo->prepare("SELECT p.product_id
        FROM Products p
        LEFT JOIN (SELECT product_id, SUM(quantity_remaining) AS sellable_stock FROM Stock_Batch
            WHERE is_depleted = 0 AND status = 'ACTIVE' AND quantity_remaining > 0
            AND (expiration_date IS NULL OR expiration_date >= CURDATE()) GROUP BY product_id) stock ON stock.product_id = p.product_id
        WHERE COALESCE(p.product_status, 'ACTIVE') = 'ACTIVE'
        AND (? = 0 OR p.category_id = ?)
        AND ((? > 0 AND p.display_code = ?) OR (? = 0 AND LOWER(TRIM(p.product_name)) = ?))
        AND COALESCE(stock.sellable_stock, 0) <= 0
        ORDER BY p.product_id");
    $statement->execute([$categoryId, $categoryId, $codeId, $codeId, $codeId, strtolower(trim($term))]);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}
