<?php
/**
 * GET api/records.php?area=FR
 *
 * Records worked out from this site's own stored readings (so they only
 * go back to when the site started recording that area - "since" in the
 * reply): the best of each kind with the record it beat, each year's
 * best, and the records broken most recently. See ukgrid_area_records().
 * With &all=1 it also lists every record broken ("breaks") and the first
 * value of each kind ("firsts"), for the Records page's step chart.
 */
require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$area = ukgrid_api_area($config);
$rec = ukgrid_area_records(ukgrid_db(), $area, !empty($_GET['all']));
echo json_encode(array_merge(['ok' => true, 'area' => ukgrid_area_public($area)], $rec));
