<?php
declare(strict_types=1);
chdir('/elabftw');
require 'vendor/autoload.php';

use Elabftw\Enums\Storage;
use Elabftw\Models\Experiments;
use Elabftw\Models\SmartlabBenchRecords;
use Elabftw\Models\SmartlabDataCenter;
use Elabftw\Models\Users\Users;
use Elabftw\Services\SmartlabQpcrImport;

$pkg = (int) ($argv[1] ?? 2);
$exp = (int) ($argv[2] ?? 7);
$student = new Users(2, 1);
$center = new SmartlabDataCenter($student);
$center->linkPackage($pkg, $exp);
echo "linked package {$pkg} -> experiment {$exp}, imported={$center->lastImportCount}\n";

$bench = new SmartlabBenchRecords($student);
$ctx = $bench->read($exp);
echo 'uploads now: ' . count($ctx['uploads']) . "\n";
foreach ($ctx['uploads'] as $u) {
    echo "  #{$u['id']}  {$u['real_name']}  ({$u['filesize']} B)\n";
}

foreach ($ctx['uploads'] as $u) {
    if (!str_ends_with(strtolower((string) $u['real_name']), '.csv')) {
        continue;
    }
    $bytes = Storage::from((int) ($u['storage'] ?? 1))->getStorage()->getFs()->read((string) $u['long_name']);
    $preview = SmartlabQpcrImport::preview((string) $bytes, (string) $u['real_name']);
    echo 'qPCR parse of ' . $u['real_name'] . ': headers=[' . implode('|', $preview['headers']) . '] rows=' . count($preview['rows']) . "\n";
    echo '  suggested=' . json_encode($preview['suggested'], JSON_UNESCAPED_UNICODE) . "\n";
}
