<?php
/** GET api/areas.php - every area this site covers (see includes/areas.php), for the archive and day pages' country picker. */
require __DIR__ . '/_bootstrap.php';
$out = [];
foreach (ukgrid_areas(ukgrid_load_config()) as $a) {
    $out[] = ukgrid_area_public($a);
}
echo json_encode(['ok' => true, 'areas' => $out]);
