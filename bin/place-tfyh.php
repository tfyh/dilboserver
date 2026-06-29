<?php
/**
 * Composer post-install / post-update helper for dilboserver.
 *
 * The dilbo server code loads the tfyh framework via explicit
 * include_once "../../tfyh/..." statements — a deliberate, performance-motivated
 * design (no autoloading). Composer therefore does not need to autoload anything;
 * it only has to make the tfyh package available where the code expects it:
 * <app-root>/tfyh.
 *
 * This script links (or, where symlinks are unavailable, copies) the installed
 * package from vendor/tfyh/tfyh to <app-root>/tfyh. It is idempotent and never
 * deletes a real (non-symlink) ./tfyh directory.
 */

$root   = dirname(__DIR__);
$source = $root . '/vendor/tfyh/tfyh';
$target = $root . '/tfyh';

if (! is_dir($source)) {
    fwrite(STDERR, "[place-tfyh] vendor/tfyh/tfyh not found — run 'composer install' first.\n");
    exit(1);
}

// A real directory (e.g. a manual copy) is left untouched, so nothing is destroyed.
if (is_dir($target) && ! is_link($target)) {
    fwrite(STDERR, "[place-tfyh] ./tfyh exists as a real directory — leaving it untouched.\n");
    exit(0);
}

// Refresh an existing (possibly stale) symlink.
if (is_link($target)) {
    unlink($target);
}

// Preferred: a relative symlink — cheap and always in sync with the installed package.
$relSource = 'vendor/tfyh/tfyh';
if (@symlink($relSource, $target)) {
    echo "[place-tfyh] linked ./tfyh -> $relSource\n";
    exit(0);
}

// Fallback (e.g. Windows without symlink privilege): a recursive copy.
echo "[place-tfyh] symlinks unavailable — copying vendor/tfyh/tfyh -> ./tfyh ...\n";
copyRecursive($source, $target);
echo "[place-tfyh] copied tfyh into ./tfyh\n";

function copyRecursive(string $src, string $dst): void
{
    @mkdir($dst, 0775, true);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $destPath = $dst . '/' . $items->getSubPathName();
        if ($item->isDir()) {
            @mkdir($destPath, 0775, true);
        } else {
            copy($item->getPathname(), $destPath);
        }
    }
}
