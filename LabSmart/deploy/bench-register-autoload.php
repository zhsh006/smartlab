<?php
declare(strict_types=1);
// The existing runtime uses an authoritative Composer class map.
$path = '/elabftw/vendor/autoload.php';
$source = file_get_contents($path);
$replacement = '$benchLoader = $1;' . "\n" . '$benchLoader->addClassMap(array("Elabftw\\\\Models\\\\SmartlabBenchRecords" => "/elabftw/src/Models/SmartlabBenchRecords.php"));' . "\n" . 'return $benchLoader;';
$source = preg_replace('/return (ComposerAutoloaderInit[^;]+::getLoader\(\));/', $replacement, $source, 1, $count);
if ($count !== 1) { throw new RuntimeException('Composer loader registration did not match; stop build.'); }
file_put_contents($path, $source);
