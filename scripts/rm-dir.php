<?php

$path = $argv[1] ?? null;

if (!$path || !file_exists($path)) {
    exit(0);
}

if (is_file($path) || is_link($path)) {
    @unlink($path);
    exit(0);
}

$iterator = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
$files = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST);

foreach ($files as $file) {
    if ($file->isDir()) {
        @rmdir($file->getRealPath());
        continue;
    }

    @unlink($file->getRealPath());
}

@rmdir($path);