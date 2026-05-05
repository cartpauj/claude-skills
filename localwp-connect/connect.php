<?php
// connect.php — LocalWP site connector. Invoked by the localwp-connect skill.
// Pure logic: all user interaction happens in Claude. Input via flags, output via JSON.

declare(strict_types=1);

// ---------- arg parsing ----------

function parse_args(array $argv): array {
    $args = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) {
            $args[$m[1]] = $m[2] ?? true;
        }
    }
    return $args;
}

function fail(string $code, string $msg, array $extra = []): void {
    echo json_encode(['status' => 'error', 'code' => $code, 'message' => $msg] + $extra,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(1);
}

function emit(array $data): void {
    echo json_encode(['status' => 'ok'] + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$args = parse_args($argv);
$configDir = $args['config-dir'] ?? null;
if (!$configDir || !is_file("$configDir/sites.json")) {
    fail('bad_config_dir', "--config-dir missing or sites.json not found inside");
}

$sitesRaw = json_decode((string)file_get_contents("$configDir/sites.json"), true);
if (!is_array($sitesRaw)) fail('parse_sites', "failed to parse sites.json");

// ---------- --list-sites ----------

if (!empty($args['list-sites'])) {
    $list = [];
    foreach ($sitesRaw as $id => $s) {
        $list[] = [
            'id' => $id,
            'name' => $s['name'] ?? $id,
            'domain' => $s['domain'] ?? null,
            'path' => expand_home((string)($s['path'] ?? '')),
        ];
    }
    usort($list, fn($a, $b) => strcmp(strtolower($a['name']), strtolower($b['name'])));
    emit(['sites' => $list]);
}

// ---------- connect ----------

$needle = $args['site'] ?? null;
if (!$needle) fail('no_site', "--site=<name-or-id> required (or use --list-sites)");

$site = find_site($sitesRaw, (string)$needle);
if (!$site) fail('site_not_found', "no site matched '$needle'");

$siteId   = $site['_id'];
$siteName = $site['name'] ?? $siteId;
$sitePath = expand_home((string)($site['path'] ?? ''));
// Normalize to forward slashes: PHP/bash/WP-CLI all accept them on Windows,
// and it keeps CLAUDE.md paths from mixing separators.
$sitePath = str_replace('\\', '/', $sitePath);
$wpRoot   = "$sitePath/app/public";
if (!is_dir($wpRoot)) fail('wp_root_missing', "site WP root not found: $wpRoot");

// --- DB info: prefer wp-config.php, fall back to sites.json ---
$db = [
    'name'   => $site['mysql']['database'] ?? 'local',
    'user'   => $site['mysql']['user'] ?? 'root',
    'pass'   => $site['mysql']['password'] ?? 'root',
    'prefix' => 'wp_',
];
$wpConfig = @file_get_contents("$wpRoot/wp-config.php");
if ($wpConfig) {
    foreach (['DB_NAME' => 'name', 'DB_USER' => 'user', 'DB_PASSWORD' => 'pass'] as $k => $f) {
        $re = '/define\(\s*[\'"]' . preg_quote($k, '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/';
        if (preg_match($re, $wpConfig, $m)) {
            $db[$f] = $m[1];
        }
    }
    if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $wpConfig, $m)) {
        $db['prefix'] = $m[1];
    }
}

$httpPort  = $site['services']['nginx']['ports']['HTTP'][0] ?? null;
$mysqlPort = $site['services']['mysql']['ports']['MYSQL'][0] ?? null;
$phpVer    = $site['services']['php']['version'] ?? null;
$mysqlVer  = $site['services']['mysql']['version'] ?? null;
$dbFlavor  = $site['services']['mysql']['name'] ?? 'mysql';  // 'mysql' or 'mariadb'
$adminId   = (string)($site['oneClickAdminID'] ?? '1');
$adminName = (string)($site['oneClickAdminDisplayName']
    ?? $site['oneClickAdminUsername']
    ?? $site['oneClickAdminEmail']
    ?? '');

// --- Router mode & URL ---
$router = @json_decode((string)@file_get_contents("$configDir/router.json"), true) ?: [];
$mode = $router['mode'] ?? 'localhost';
$url = ($mode === 'site-domains' && !empty($site['domain']))
    ? "http://" . $site['domain']
    : "http://localhost:" . ($httpPort ?? '80');

// --- MySQL socket (posix only) ---
$socket = null;
$myCnf = "$configDir/run/$siteId/conf/mysql/my.cnf";
if (is_file($myCnf)) {
    foreach (file($myCnf) ?: [] as $line) {
        if (preg_match('/^\s*socket\s*=\s*(.+)$/', $line, $m)) {
            $socket = trim($m[1]);
            break;
        }
    }
}

// --- Resolve lightning-services versioned dirs ---
$lightning = "$configDir/lightning-services";
$phpDir   = resolve_lightning($lightning, 'php',      $phpVer);
$mysqlDir = resolve_lightning($lightning, $dbFlavor,  $mysqlVer);
if (!$phpDir || !$mysqlDir) {
    fail('lightning_resolve', "could not resolve lightning-services dirs",
        ['php_ver' => $phpVer, 'mysql_ver' => $mysqlVer, 'lightning_dir' => $lightning]);
}

// --- Install dir (for wp-cli + composer binaries) — optional ---
$installDir = find_install_dir();

// --- Write wrappers into ./bin ---
@mkdir('bin', 0755, true);
$posixWrapper = write_posix_wrapper($siteId, $sitePath, $configDir, $installDir, $phpDir, $mysqlDir);
$cmdWrapper   = write_cmd_wrapper($siteId, $sitePath, $configDir, $installDir, $phpDir, $mysqlDir);

// --- Full path to Local's mysql binary for CLAUDE.md (cross-platform copy-paste) ---
$archCandidates = match (PHP_OS_FAMILY) {
    'Windows' => ['win64', 'win32'],
    'Darwin'  => (php_uname('m') === 'arm64') ? ['darwin-arm64', 'darwin'] : ['darwin', 'darwin-arm64'],
    default   => ['linux'],
};
$arch = $archCandidates[0];
foreach ($archCandidates as $a) {
    if (is_dir("$lightning/$mysqlDir/bin/$a")) { $arch = $a; break; }
}
$mysqlBinName = PHP_OS_FAMILY === 'Windows' ? 'mysql.exe' : 'mysql';
$mysqlBin = "$lightning/$mysqlDir/bin/$arch/bin/$mysqlBinName";

// --- Update CLAUDE.md block ---
$claudeStatus = update_claude_md([
    'name'       => $siteName,
    'site_path'  => $sitePath,
    'wp_root'    => $wpRoot,
    'url'        => $url,
    'mode'       => $mode,
    'admin_user' => $adminName,
    'admin_id'   => $adminId,
    'db'         => $db,
    'mysql_port' => $mysqlPort,
    'socket'     => $socket,
    'php_ver'    => $phpVer,
    'mysql_ver'  => $mysqlVer,
    'mysql_bin'  => $mysqlBin,
    'is_windows' => PHP_OS_FAMILY === 'Windows',
]);

// --- Symlink ---
$wantLink = (string)($args['symlink'] ?? 'auto');
if ($wantLink === 'auto') $wantLink = detect_plugin(getcwd()) ? 'yes' : 'no';
$symlinkStatus = ['requested' => $wantLink, 'action' => 'skipped'];
if ($wantLink === 'yes') {
    $slug = basename((string)getcwd());
    $target = "$wpRoot/wp-content/plugins/$slug";
    $force = isset($args['symlink-force']) ? (string)$args['symlink-force'] : null;
    $symlinkStatus = ['requested' => 'yes', 'slug' => $slug, 'target' => $target]
        + create_symlink((string)getcwd(), $target, $force);
}

emit([
    'site' => [
        'id' => $siteId, 'name' => $siteName, 'path' => $sitePath, 'wp_root' => $wpRoot,
        'url' => $url, 'router_mode' => $mode,
    ],
    'admin'   => ['user' => $adminName, 'id' => $adminId],
    'db'      => $db + ['tcp' => $mysqlPort ? "127.0.0.1:$mysqlPort" : null, 'socket' => $socket],
    'stack'   => ['php' => $phpVer, 'mysql' => $mysqlVer],
    'wrappers'=> array_filter(['posix' => $posixWrapper, 'windows' => $cmdWrapper]),
    'claude_md' => $claudeStatus,
    'symlink'   => $symlinkStatus,
    'install_dir' => $installDir,
    'router_recommendation' => $mode === 'site-domains',
]);

// ====================== helpers ======================

function expand_home(string $p): string {
    if ($p === '') return $p;
    if ($p[0] === '~') {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
        $p = $home . substr($p, 1);
    }
    return $p;
}

function find_site(array $sites, string $needle): ?array {
    $n = strtolower($needle);
    if (isset($sites[$needle])) return $sites[$needle] + ['_id' => $needle];
    foreach ($sites as $id => $s) {
        if (strtolower((string)($s['name'] ?? '')) === $n) return $s + ['_id' => $id];
    }
    $subs = [];
    foreach ($sites as $id => $s) {
        if (str_contains(strtolower((string)($s['name'] ?? '')), $n)) {
            $subs[] = $s + ['_id' => $id];
        }
    }
    return count($subs) === 1 ? $subs[0] : null;
}

function resolve_lightning(string $dir, string $prefix, ?string $ver): ?string {
    if (!is_dir($dir)) return null;
    $all = array_values(array_filter(scandir($dir) ?: [],
        fn($e) => str_starts_with($e, "$prefix-") && is_dir("$dir/$e")));
    if (!$all) return null;
    if ($ver) {
        $exact = array_values(array_filter($all, fn($e) => str_starts_with($e, "$prefix-$ver")));
        if ($exact) { usort($exact, 'strnatcmp'); return end($exact); }
    }
    usort($all, 'strnatcmp');
    return end($all);
}

function find_install_dir(): ?string {
    $home = getenv('HOME') ?: '';
    $candidates = match (PHP_OS_FAMILY) {
        'Darwin' => [
            '/Applications/Local.app/Contents/Resources/extraResources/bin',
            "$home/Applications/Local.app/Contents/Resources/extraResources/bin",
            '/Applications/Local by Flywheel.app/Contents/Resources/extraResources/bin',
        ],
        'Windows' => [
            (getenv('ProgramFiles') ?: 'C:\\Program Files') . '\\Local\\resources\\extraResources\\bin',
            (getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)') . '\\Local\\resources\\extraResources\\bin',
            (getenv('LOCALAPPDATA') ?: '') . '\\Programs\\Local\\resources\\extraResources\\bin',
        ],
        default => [
            '/opt/Local/resources/extraResources/bin',
            '/usr/lib/local-by-flywheel/resources/extraResources/bin',
            '/usr/share/local/resources/extraResources/bin',
            "$home/.local/share/Local/resources/extraResources/bin",
            '/snap/local/current/resources/extraResources/bin',
        ],
    };
    foreach ($candidates as $c) {
        if ($c && is_file("$c/wp-cli/config.yaml")) return $c;
    }
    return null;
}

function detect_plugin(string $dir): bool {
    foreach (glob("$dir/*.php") ?: [] as $f) {
        $head = (string)@file_get_contents($f, false, null, 0, 4096);
        if (preg_match('/^[\s\*\/]*Plugin Name\s*:/mi', $head)) return true;
    }
    return false;
}

// ---------- wrapper writers ----------

function write_posix_wrapper(string $siteId, string $sitePath, string $configDir,
                             ?string $installDir, string $phpDir, string $mysqlDir): string {
    $path = 'bin/wp';
    $tpl = <<<'EOT'
#!/usr/bin/env bash
# Auto-generated by localwp-connect. Runs WP-CLI against __SITE_NAME__.
set -e

# If this script is being invoked from inside a Lando container (the repo is
# bind-mounted at /app and /app/bin ends up on PATH, shadowing the container's
# wp-cli), step aside and let the container's wp-cli handle it.
if [ -n "$LANDO_INFO" ] || [ -f "/.lando" ] || [ -f "/lando-entrypoint.sh" ]; then
  for candidate in /usr/local/bin/wp /usr/bin/wp; do
    if [ -x "$candidate" ] && [ "$candidate" != "$0" ]; then
      exec "$candidate" "$@"
    fi
  done
  echo "localwp-connect: running inside Lando but no container wp-cli found." >&2
  exit 1
fi

SITE_ID="__SITE_ID__"
SITE_PATH="__SITE_PATH__"
LOCAL_CONFIG="__CONFIG_DIR__"
LOCAL_INSTALL="__INSTALL_DIR__"
PHP_DIR="__PHP_DIR__"
MYSQL_DIR="__MYSQL_DIR__"

UNAME="$(uname -s 2>/dev/null || echo Unknown)"
UMACH="$(uname -m 2>/dev/null || echo Unknown)"
LIGHTNING="$LOCAL_CONFIG/lightning-services"

# Re-resolve versioned dirs if a Local update moved them
[ -d "$LIGHTNING/$PHP_DIR" ] || PHP_DIR="$(ls -1 "$LIGHTNING" 2>/dev/null | grep '^php-' | sort -V | tail -1)"
[ -d "$LIGHTNING/$MYSQL_DIR" ] || MYSQL_DIR="$(ls -1 "$LIGHTNING" 2>/dev/null | grep "^${MYSQL_DIR%%-*}-" | sort -V | tail -1)"

case "$UNAME" in
  Linux*) ARCH_DIR="linux" ;;
  Darwin*)
    if [ "$UMACH" = "arm64" ] && [ -d "$LIGHTNING/$PHP_DIR/bin/darwin-arm64" ]; then
      ARCH_DIR="darwin-arm64"
    else
      ARCH_DIR="darwin"
    fi ;;
  MINGW*|MSYS*|CYGWIN*)
    if [ -d "$LIGHTNING/$PHP_DIR/bin/win64" ]; then ARCH_DIR="win64"; else ARCH_DIR="win32"; fi ;;
  *) echo "Unsupported platform: $UNAME" >&2; exit 1 ;;
esac

export MYSQL_HOME="$LOCAL_CONFIG/run/$SITE_ID/conf/mysql"
# LocalWP's my.cnf only has a [mysqld] section, which the mysql CLIENT can't
# read. Without this, `wp db query` (which shells out to mysql) falls back
# to /tmp/mysql.sock and fails. MYSQL_UNIX_PORT despite its name = socket path.
export MYSQL_UNIX_PORT="$LOCAL_CONFIG/run/$SITE_ID/mysql/mysqld.sock"
export PHPRC="$LOCAL_CONFIG/run/$SITE_ID/conf/php"
export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1
# PHP layout differs on Windows: bin/<arch>/php.exe (no nested bin/). MySQL keeps bin/<arch>/bin/.
case "$ARCH_DIR" in
  win*) export PATH="$LIGHTNING/$MYSQL_DIR/bin/$ARCH_DIR/bin:$LIGHTNING/$PHP_DIR/bin/$ARCH_DIR:$PATH" ;;
  *)    export PATH="$LIGHTNING/$MYSQL_DIR/bin/$ARCH_DIR/bin:$LIGHTNING/$PHP_DIR/bin/$ARCH_DIR/bin:$PATH" ;;
esac

if [ -n "$LOCAL_INSTALL" ] && [ -f "$LOCAL_INSTALL/wp-cli/config.yaml" ]; then
  export WP_CLI_CONFIG_PATH="$LOCAL_INSTALL/wp-cli/config.yaml"
  export PATH="$LOCAL_INSTALL/wp-cli/posix:$LOCAL_INSTALL/composer/posix:$PATH"
elif ! command -v wp >/dev/null 2>&1; then
  echo "localwp-connect: LocalWP install dir not found and no system 'wp' on PATH." >&2
  exit 1
fi

if [ "$ARCH_DIR" != "win32" ]; then
  export LD_LIBRARY_PATH="$LIGHTNING/$PHP_DIR/bin/$ARCH_DIR/shared-libs${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
  export MAGICK_CODER_MODULE_PATH="$LIGHTNING/$PHP_DIR/bin/$ARCH_DIR/ImageMagick/modules-Q16/coders"
  [ "$ARCH_DIR" = "darwin" ] && export DYLD_LIBRARY_PATH="$LIGHTNING/$PHP_DIR/bin/$ARCH_DIR/shared-libs${DYLD_LIBRARY_PATH:+:$DYLD_LIBRARY_PATH}"
fi

cd "$SITE_PATH/app/public"
exec wp "$@"
EOT;
    $out = strtr($tpl, [
        '__SITE_NAME__'  => basename($sitePath),
        '__SITE_ID__'    => $siteId,
        '__SITE_PATH__'  => $sitePath,
        '__CONFIG_DIR__' => $configDir,
        '__INSTALL_DIR__'=> (string)$installDir,
        '__PHP_DIR__'    => $phpDir,
        '__MYSQL_DIR__'  => $mysqlDir,
    ]);
    file_put_contents($path, $out);
    @chmod($path, 0755);
    return $path;
}

function write_cmd_wrapper(string $siteId, string $sitePath, string $configDir,
                           ?string $installDir, string $phpDir, string $mysqlDir): string {
    $path = 'bin/wp.cmd';
    $winConfig  = str_replace('/', '\\', $configDir);
    $winInstall = $installDir ? str_replace('/', '\\', $installDir) : '';
    $winSite    = str_replace('/', '\\', $sitePath);
    $tpl = <<<'EOT'
@echo off
REM Auto-generated by localwp-connect. Runs WP-CLI against __SITE_NAME__.
SETLOCAL ENABLEDELAYEDEXPANSION
SET "SITE_ID=__SITE_ID__"
SET "SITE_PATH=__SITE_PATH__"
SET "LOCAL_CONFIG=__CONFIG_DIR__"
SET "LOCAL_INSTALL=__INSTALL_DIR__"
SET "PHP_DIR=__PHP_DIR__"
SET "MYSQL_DIR=__MYSQL_DIR__"
SET "LIGHTNING=%LOCAL_CONFIG%\lightning-services"

IF NOT EXIST "%LIGHTNING%\%PHP_DIR%" (
  FOR /F "delims=" %%F IN ('dir /B /O-N "%LIGHTNING%\php-*" 2^>NUL') DO IF NOT DEFINED _P SET "_P=%%F"
  IF DEFINED _P SET "PHP_DIR=!_P!"
)
IF NOT EXIST "%LIGHTNING%\%MYSQL_DIR%" (
  FOR /F "tokens=1 delims=-" %%X IN ("%MYSQL_DIR%") DO SET "_MF=%%X"
  FOR /F "delims=" %%F IN ('dir /B /O-N "%LIGHTNING%\!_MF!-*" 2^>NUL') DO IF NOT DEFINED _M SET "_M=%%F"
  IF DEFINED _M SET "MYSQL_DIR=!_M!"
)

IF EXIST "%LIGHTNING%\%PHP_DIR%\bin\win64" (SET "ARCH_DIR=win64") ELSE (SET "ARCH_DIR=win32")
IF EXIST "%LOCAL_INSTALL%\wp-cli\win64" (SET "WPCLI_ARCH=win64") ELSE (SET "WPCLI_ARCH=win32")

SET "MYSQL_HOME=%LOCAL_CONFIG%\run\%SITE_ID%\conf\mysql"
SET "PHPRC=%LOCAL_CONFIG%\run\%SITE_ID%\conf\php"
SET "WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1"
REM NOTE: PHP on Windows is at bin\<arch>\php.exe (no nested bin\). MySQL keeps the bin\<arch>\bin\ layout.
SET "PATH=%LIGHTNING%\%MYSQL_DIR%\bin\%ARCH_DIR%\bin;%LIGHTNING%\%PHP_DIR%\bin\%ARCH_DIR%;%PATH%"
IF NOT "%LOCAL_INSTALL%"=="" IF EXIST "%LOCAL_INSTALL%\wp-cli\config.yaml" (
  SET "WP_CLI_CONFIG_PATH=%LOCAL_INSTALL%\wp-cli\config.yaml"
  SET "PATH=%LOCAL_INSTALL%\wp-cli\%WPCLI_ARCH%;%LOCAL_INSTALL%\composer\%WPCLI_ARCH%;%PATH%"
)
cd /d "%SITE_PATH%\app\public"
call wp %*
ENDLOCAL
EOT;
    $out = strtr($tpl, [
        '__SITE_NAME__'  => basename($sitePath),
        '__SITE_ID__'    => $siteId,
        '__SITE_PATH__'  => $winSite,
        '__CONFIG_DIR__' => $winConfig,
        '__INSTALL_DIR__'=> $winInstall,
        '__PHP_DIR__'    => $phpDir,
        '__MYSQL_DIR__'  => $mysqlDir,
    ]);
    file_put_contents($path, $out);
    return $path;
}

// ---------- CLAUDE.md ----------

function update_claude_md(array $f): array {
    $path = 'CLAUDE.md';
    $isWin = !empty($f['is_windows']);
    $tcp = $f['mysql_port'] ? "127.0.0.1:{$f['mysql_port']}" : "n/a";
    $u = $f['db']['user']; $p = $f['db']['pass']; $n = $f['db']['name'];

    // Socket line: only show on POSIX where the socket exists and is usable.
    // MySQL on Windows uses named pipes / TCP — the socket file (if any) isn't usable from outside mysqld.
    $socketLine = (!$isWin && $f['socket'])
        ? "  - **Unix socket:** `{$f['socket']}`  _(preferred for direct `mysql` client access — faster, no password warning)_"
        : null;

    // Direct-DB section varies by platform. Use the full path to Local's bundled mysql binary so the command
    // works regardless of system PATH (the wp.cmd wrapper uses SETLOCAL, so PATH changes don't persist back
    // to the caller's shell — and POSIX `./bin/wp` runs in a subshell, so neither does there).
    $mysqlBin = $f['mysql_bin'];
    if ($isWin) {
        $dbSection = <<<MD
### Direct DB access

```cmd
"$mysqlBin" -h 127.0.0.1 -P {$f['mysql_port']} -u$u -p$p $n
```

The bundled `mysql.exe` is always invoked by full path — `wp.cmd` uses `SETLOCAL`, so it does not export PATH to the caller's shell.
MD;
    } else {
        $dbSection = <<<MD
### Direct DB access

```bash
# Via socket (preferred — faster, avoids password-on-CLI warning):
"$mysqlBin" --socket="{$f['socket']}" -u$u -p$p $n

# Via TCP (works everywhere):
"$mysqlBin" -h 127.0.0.1 -P {$f['mysql_port']} -u$u -p$p $n
```
MD;
        // If no socket was discovered (site never started, etc.), drop the socket example.
        if (!$f['socket']) {
            $dbSection = <<<MD
### Direct DB access

```bash
"$mysqlBin" -h 127.0.0.1 -P {$f['mysql_port']} -u$u -p$p $n
```
MD;
        }
    }

    $dbListItems = "  - **TCP:** `$tcp`"
        . ($socketLine ? "\n$socketLine" : "");

    $block = <<<MD
<!-- localwp-connect:start -->
## LocalWP Site: {$f['name']}

This project is connected to the LocalWP site **{$f['name']}**.

- **Site path:** `{$f['site_path']}`
- **WordPress root:** `{$f['wp_root']}`
- **Local URL:** `{$f['url']}`  _(router mode: `{$f['mode']}`)_
- **Admin URL:** `{$f['url']}/wp-admin`
- **Admin user:** `{$f['admin_user']}` (ID `{$f['admin_id']}`)
- **Database:** `$n` — user `$u` / password `$p` — table prefix `{$f['db']['prefix']}`
$dbListItems
- **Stack:** PHP `{$f['php_ver']}` · MySQL `{$f['mysql_ver']}`

### Running WP-CLI

This project ships a wrapper that loads LocalWP's environment — no need to open Site Shell.

- POSIX (Linux, macOS, WSL, Git Bash): `./bin/wp <command>`
- Windows cmd.exe / PowerShell: `bin\\wp.cmd <command>`

```bash
./bin/wp plugin list
./bin/wp option get siteurl
./bin/wp db query "SELECT COUNT(*) FROM {$f['db']['prefix']}posts"
```

$dbSection
<!-- localwp-connect:end -->
MD;

    $existing = is_file($path) ? (string)file_get_contents($path) : '';
    $start = '<!-- localwp-connect:start -->';
    $end   = '<!-- localwp-connect:end -->';
    $status = 'created';

    if ($existing !== '') {
        $si = strpos($existing, $start);
        $ei = strpos($existing, $end);
        if ($si !== false && $ei !== false && $ei > $si) {
            $before = rtrim(substr($existing, 0, $si), "\n");
            $after  = ltrim(substr($existing, $ei + strlen($end)), "\n");
            $new = ($before === '' ? '' : $before . "\n\n") . $block . ($after === '' ? "\n" : "\n\n" . $after);
            file_put_contents($path, $new);
            return ['path' => $path, 'status' => 'replaced'];
        }
        $new = rtrim($existing, "\n") . "\n\n" . $block . "\n";
        file_put_contents($path, $new);
        return ['path' => $path, 'status' => 'appended'];
    }
    file_put_contents($path, $block . "\n");
    return ['path' => $path, 'status' => $status];
}

// ---------- symlink ----------

function create_symlink(string $src, string $target, ?string $force): array {
    // Canonicalize
    $srcReal = realpath($src) ?: $src;
    $parent = dirname($target);
    if (!is_dir($parent)) return ['action' => 'error', 'error' => "plugins dir missing: $parent"];

    // WSL cross-filesystem check (Linux only, target starts with /mnt/)
    $wslNote = null;
    if (PHP_OS_FAMILY === 'Linux' && str_starts_with($target, '/mnt/')) {
        $wslNote = 'WSL cross-filesystem symlink — may not be visible to Windows tools';
    }

    $exists = file_exists($target) || is_link($target);
    if ($exists) {
        if (is_link($target)) {
            $cur = readlink($target) ?: '';
            $curReal = realpath($cur) ?: $cur;
            if ($curReal === $srcReal) {
                return ['action' => 'already_linked', 'note' => $wslNote];
            }
            if ($force === 'replace') {
                @unlink($target);
            } else {
                return ['action' => 'blocked_symlink_elsewhere', 'existing_target' => $curReal,
                        'hint' => 'pass --symlink-force=replace to overwrite'];
            }
        } elseif (is_dir($target)) {
            if ($force === 'rename-bak') {
                $bak = $target . '.bak';
                $i = 1;
                while (file_exists($bak)) $bak = $target . '.bak' . (++$i);
                if (!@rename($target, $bak)) {
                    return ['action' => 'error', 'error' => "failed to rename existing dir to $bak"];
                }
            } else {
                return ['action' => 'blocked_real_directory', 'target' => $target,
                        'hint' => 'pass --symlink-force=rename-bak to move it aside'];
            }
        } else {
            return ['action' => 'blocked_unexpected_file', 'target' => $target];
        }
    }

    // Create link
    if (PHP_OS_FAMILY === 'Windows') {
        // Try symlink, fall back to junction
        $cmd = 'mklink /D ' . escapeshellarg($target) . ' ' . escapeshellarg($srcReal);
        exec($cmd . ' 2>&1', $o, $rc);
        if ($rc !== 0) {
            $cmd = 'mklink /J ' . escapeshellarg($target) . ' ' . escapeshellarg($srcReal);
            exec($cmd . ' 2>&1', $o2, $rc2);
            if ($rc2 !== 0) return ['action' => 'error', 'error' => 'mklink failed: ' . implode("\n", $o2)];
            return ['action' => 'created_junction', 'note' => $wslNote];
        }
        return ['action' => 'created_symlink', 'note' => $wslNote];
    }
    if (!@symlink($srcReal, $target)) {
        return ['action' => 'error', 'error' => "symlink($srcReal, $target) failed"];
    }
    return ['action' => 'created_symlink', 'note' => $wslNote];
}
