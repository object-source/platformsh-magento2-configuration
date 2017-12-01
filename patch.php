<?php

use Platformsh\Environment;

require_once 'src/Platformsh/Environment.php';
$env = new Environment();

$dirName = __DIR__ . '/patches';

$files = glob($dirName . '/*');
sort($files);
foreach ($files as $file) {
    $cmd = 'git apply '  . $file;
    $env->execute($cmd);
}

$sampleDataDir = Environment::MAGENTO_ROOT . 'vendor/magento/sample-data-media';
if (file_exists($sampleDataDir)) {
    $env->log("Sample data media found. Marshalling to pub/media.");
    $destination = Environment::MAGENTO_ROOT . '/pub/media';
    foreach (
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sampleDataDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST) as $item
    ) {
        if ($item->isDir()) {
            if (!file_exists($destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName())) {
                mkdir($destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
            }
        } else {
            copy($item, $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
        }
    }
}
