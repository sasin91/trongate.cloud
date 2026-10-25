<?php

/**
 * Sample modules to download from the docs: one folder each in modules/docs/samples.
 *
 * A sample folder holds its files exactly where they go in a customer's app, so
 * samples/backup/modules/trongate-cloud/backup/runtime.php unzips into the app's root
 * as modules/trongate-cloud/backup/runtime.php. Hidden files are left out.
 */
class Sample {

    /** Where the sample folders live */
    const DIRECTORY = __DIR__ . '/../samples';

    /**
     * Whether a sample by this name exists.
     *
     * @param string $name A folder name in the samples directory
     * @param string $directory The samples directory (tests pass their own)
     * @return bool
     */
    public static function exists(string $name, string $directory = self::DIRECTORY): bool {
        return preg_match('/^[a-z0-9-]+$/', $name) === 1 && is_dir("{$directory}/{$name}");
    }

    /**
     * The files in a sample, as the docs page lists them.
     *
     * @param string $name A sample that exists
     * @param string $directory The samples directory (tests pass their own)
     * @return array<int, object> Each with path (relative to the app's root) and size (like "2.1 KB")
     */
    public static function files(string $name, string $directory = self::DIRECTORY): array {
        $files = [];

        foreach (self::paths($name, $directory) as $relative => $absolute) {
            $files[] = (object) [
                'path' => $relative,
                'size' => self::size((int) filesize($absolute)),
            ];
        }

        return $files;
    }

    /**
     * Pack a sample into a zip file.
     *
     * @param string $name A sample that exists
     * @param string $target Where to write the zip
     * @param string $directory The samples directory (tests pass their own)
     * @return void
     */
    public static function zip(string $name, string $target, string $directory = self::DIRECTORY): void {
        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create {$target}");
        }

        foreach (self::paths($name, $directory) as $relative => $absolute) {
            $zip->addFile($absolute, $relative);
        }

        $zip->close();
    }

    /**
     * Every file in a sample, sorted, skipping hidden files and folders.
     *
     * @param string $name A sample that exists
     * @param string $directory The samples directory
     * @return array<string, string> Absolute path by path relative to the sample folder
     */
    private static function paths(string $name, string $directory): array {
        $root = "{$directory}/{$name}";
        $paths = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->isFile() && !preg_match('#(^|/)\.#', $relative)) {
                $paths[$relative] = $file->getPathname();
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * A file size people can read.
     *
     * @param int $bytes
     * @return string Like "812 B" or "2.1 KB"
     */
    private static function size(int $bytes): string {
        return $bytes < 1024 ? "{$bytes} B" : round($bytes / 1024, 1) . ' KB';
    }

}
