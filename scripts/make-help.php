<?php

$files = array_slice($argv, 1);
$targets = [];

foreach ($files as $file) {
    if (! is_file($file)) {
        continue;
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([a-zA-Z0-9_-]+):.*?## (.*)$/', $line, $matches)) {
            $targets[$matches[1]] = $matches[2];
        }
    }
}

ksort($targets);

foreach ($targets as $name => $description) {
    printf("\033[36m%-16s\033[0m %s\n", $name, $description);
}
