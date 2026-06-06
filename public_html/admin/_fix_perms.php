<?php
/**
 * One-shot diagnostic + chmod fix for the /admin/help/ 403 problem.
 *
 * Apache reported "Server unable to read htaccess file, denying access to be
 * safe" for every request under /admin/help/ on goldwing.org.au. That means a
 * .htaccess somewhere in the path is not readable by the web server user.
 *
 * Open this page (admin-only) without query string to see a tree dump with
 * permissions and ownership for everything under /admin/help/, plus an
 * inventory of every .htaccess found.
 *
 * Append ?fix=1 to chmod every .htaccess found to 0644 (rw-r--r--).
 *
 * DELETE THIS FILE once the 403 is resolved.
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_role(['admin', 'webmaster']);

header('Content-Type: text/plain; charset=utf-8');

$root = realpath(__DIR__ . '/help');
echo "Diagnostic for /admin/help/ access (cause of the 403 problem).\n";
echo str_repeat('=', 70) . "\n\n";

if (!$root) {
    echo "PROBLEM: " . __DIR__ . "/help does not exist on disk.\n";
    echo "         The git deploy may have skipped that directory.\n";
    exit;
}

echo "Resolved help root: $root\n";
echo "  is_dir:      " . (is_dir($root) ? 'yes' : 'no') . "\n";
echo "  is_readable: " . (is_readable($root) ? 'yes' : 'NO') . "\n";
echo "  perms:       " . substr(sprintf('%o', @fileperms($root)), -4) . "\n\n";

// Parent chain — Apache also reads .htaccess from every ancestor.
echo "Parent directory chain (Apache reads .htaccess from each):\n";
$p = $root;
while ($p && $p !== '/' && strlen($p) > 5) {
    $p = dirname($p);
    $hta = $p . '/.htaccess';
    $marker = '';
    if (is_file($hta)) {
        $perm = substr(sprintf('%o', @fileperms($hta)), -4);
        $owner = function_exists('posix_getpwuid') ? @posix_getpwuid(@fileowner($hta)) : null;
        $own = $owner ? $owner['name'] : ((string) @fileowner($hta));
        $marker = " .htaccess perms=$perm owner=$own readable=" . (is_readable($hta) ? 'yes' : 'NO');
    }
    echo "  $p$marker\n";
}
echo "\n";

// Walk /admin/help/ tree.
echo "Tree under /admin/help/ (depth 4):\n";
$walk = function (string $dir, int $depth = 0) use (&$walk) {
    if ($depth > 4) return;
    $entries = @scandir($dir);
    if ($entries === false) {
        echo str_repeat('  ', $depth) . "  (cannot scan: " . basename($dir) . ")\n";
        return;
    }
    sort($entries);
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        $path = "$dir/$e";
        $perms = @fileperms($path);
        $permStr = $perms ? substr(sprintf('%o', $perms), -4) : '????';
        $owner = function_exists('posix_getpwuid') ? @posix_getpwuid(@fileowner($path)) : null;
        $own = $owner ? $owner['name'] : ((string) @fileowner($path));
        $type = is_dir($path) ? 'd' : (is_file($path) ? 'f' : '?');
        $readable = is_readable($path) ? '' : '  <<< NOT READABLE';
        echo str_repeat('  ', $depth) . "  [$type] $permStr $own $e$readable\n";
        if (is_dir($path)) {
            $walk($path, $depth + 1);
        }
    }
};
$walk($root);

echo "\n";
echo "Looking for .htaccess files in tree:\n";
$found = [];
$find = function (string $dir) use (&$find, &$found) {
    $entries = @scandir($dir);
    if ($entries === false) return;
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        $path = "$dir/$e";
        if ($e === '.htaccess' && is_file($path)) {
            $found[] = $path;
        } elseif (is_dir($path)) {
            $find($path);
        }
    }
};
$find($root);

if (!$found) {
    echo "  None found under /admin/help/. The bad .htaccess must be in a parent.\n";
    echo "  Check the chain above for any line with `readable=NO`.\n";
} else {
    foreach ($found as $hta) {
        $perm = substr(sprintf('%o', @fileperms($hta)), -4);
        $owner = function_exists('posix_getpwuid') ? @posix_getpwuid(@fileowner($hta)) : null;
        $own = $owner ? $owner['name'] : ((string) @fileowner($hta));
        echo "  $hta\n";
        echo "    perms=$perm  owner=$own  readable=" . (is_readable($hta) ? 'yes' : 'NO') . "\n";
        echo "    size=" . @filesize($hta) . " bytes\n";
        $head = @file_get_contents($hta, false, null, 0, 400);
        if ($head !== false) {
            $head = preg_replace('/\s+/', ' ', $head);
            echo "    head: " . substr($head, 0, 200) . "\n";
        }
    }
}

if (!empty($_GET['fix'])) {
    echo "\n";
    echo "Applying chmod 0644 to every .htaccess found:\n";
    foreach ($found as $hta) {
        $ok = @chmod($hta, 0644);
        echo "  $hta — " . ($ok ? 'OK' : 'FAILED (probably ownership)') . "\n";
    }
    // Also try the help dir + docs dir themselves
    foreach ([$root, $root . '/docs'] as $d) {
        if (is_dir($d)) {
            $ok = @chmod($d, 0755);
            echo "  (dir) $d — chmod 0755 " . ($ok ? 'OK' : 'FAILED') . "\n";
        }
    }
} else {
    echo "\n";
    echo "To attempt automatic chmod fix, append ?fix=1 to the URL:\n";
    echo "  https://goldwing.org.au/admin/_fix_perms.php?fix=1\n";
    echo "It only chmods .htaccess files to 0644 and /help/ + /docs/ dirs to 0755.\n";
    echo "It does NOT delete anything.\n";
}
