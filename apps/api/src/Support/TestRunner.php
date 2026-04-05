<?php

declare(strict_types=1);

namespace App\Support;

final class TestRunner
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function run(): int
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->basePath . '/tests'));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $failures = 0;
        foreach ($files as $file) {
            try {
                require $file;
                fwrite(STDOUT, "PASS {$file}\n");
            } catch (\Throwable $e) {
                $failures++;
                fwrite(STDERR, "FAIL {$file}: {$e->getMessage()}\n");
            }
        }

        fwrite(STDOUT, sprintf("Ran %d test files, %d failure(s)\n", count($files), $failures));
        return $failures === 0 ? 0 : 1;
    }
}
