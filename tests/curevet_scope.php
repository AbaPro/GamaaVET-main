<?php
// Rollback-only regression checks for CureVet location and stock isolation.
if (PHP_SAPI !== 'cli' || !getenv('GAMMAVET_MANUFACTURING_TEST')) throw new RuntimeException('CLI checks only.');
require_once __DIR__ . '/../includes/functions.php';
function verifyCureVet(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
$pdo->beginTransaction();
try {
    $cureLocations = [];
    foreach (['Cure Vet', 'CureVet', 'Cure-Vet', 'Cure_Vet', 'CUREVET Inventory'] as $name) {
        $q = $pdo->prepare('SELECT id FROM locations WHERE name = ? LIMIT 1');
        $q->execute([$name]);
        $id = $q->fetchColumn();
        if (!$id) {
            $pdo->prepare('INSERT INTO locations (name,is_active) VALUES (?,1)')->execute([$name]);
            $id = $pdo->lastInsertId();
        }
        $cureLocations[] = (int)$id;
    }
    $pdo->exec("INSERT INTO locations (name,is_active) VALUES ('TEST ordinary manufacturing location',1)");
    $normal = (int)$pdo->lastInsertId();
    $inventoryIds = [];
    foreach ([['curva',$cureLocations[0]], [null,$cureLocations[0]], [null,$normal], ['primer',$cureLocations[0]]] as [$channel,$location]) {
        $pdo->prepare('INSERT INTO inventories (name,location_id,direct_sale,is_active) VALUES (?,?,?,1)')
            ->execute(['TEST channel isolation ' . bin2hex(random_bytes(5)),$location,$channel]);
        $inventoryIds[] = (int)$pdo->lastInsertId();
    }
    foreach (['factory','primer','naturous','activita','curva'] as $channel) {
        $_SESSION['login_region'] = $channel;
        foreach ($cureLocations as $location) {
            verifyCureVet(canAccessLocation($location) === ($channel === 'curva'), "$channel location visibility matches CureVet boundary");
        }
        verifyCureVet(canAccessLocation($normal, true), "$channel retains ordinary locations");
        verifyCureVet(isLocationNameAllowedInCurrentChannel('Cure Vet') === ($channel === 'curva'), "$channel cannot create or rename into another channel's location");
        $visible = array_map('intval', $pdo->query('SELECT i.id FROM inventories i WHERE i.id IN (' . implode(',', $inventoryIds) . ') AND ' . getInventoryChannelScopeSql('i'))->fetchAll(PDO::FETCH_COLUMN));
        verifyCureVet(in_array($inventoryIds[0], $visible, true) === ($channel === 'curva'), "$channel inventory visibility respects explicit CureVet ownership");
        if ($channel !== 'curva') verifyCureVet(!in_array($inventoryIds[1],$visible,true) && !in_array($inventoryIds[3],$visible,true), "$channel excludes CureVet locations even when warehouse channel data is missing or incorrect");
    }
    verifyCureVet(!canAccessLocation(0) && !canAccessLocation(999999999), 'Invalid location IDs are rejected');
} finally { $pdo->rollBack(); }
echo "CureVet scope checks passed; fixture writes rolled back.\n";
