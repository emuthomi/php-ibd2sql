#!/usr/bin/env php
<?php
/**
 * ibd2sql.php — convert a MySQL 8.x datadir (.ibd/ibdata1/mysql.ibd) into per-database .sql dumps.
 *
 * Strategy: copy datadir to a scratch location, boot a private mysqld on it with --skip-grant-tables,
 * mysqldump every user database, shut down, optionally clean up.
 *
 * Prerequisites:
 *   - mysqld + mysql + mysqldump binaries installed (matching or newer major than the datadir).
 *   - AppArmor: if datadir or workdir is under /home, set the mysqld profile to complain mode first:
 *       sudo aa-complain /usr/sbin/mysqld
 *     Restore after:
 *       sudo aa-enforce /usr/sbin/mysqld
 *   - Enough free disk for: datadir copy + dumps. Plan ~2.5x datadir size.
 *
 * Usage:
 *   php ibd2sql.php --src=/path/to/datadir --work=/path/to/workdir [options]
 *
 * Options:
 *   --src=PATH          Source MySQL datadir (read-only; copied, not modified).
 *   --work=PATH         Scratch + output directory (created if missing).
 *   --port=N            Alt TCP port for mysqld (default 3308).
 *   --xport=N           Alt mysqlx port (default 33070).
 *   --mysqld=PATH       mysqld binary (default: which mysqld).
 *   --mysql=PATH        mysql client binary.
 *   --mysqldump=PATH    mysqldump binary.
 *   --user=NAME         OS user mysqld runs as (default: current user).
 *   --buffer-pool=SIZE  innodb_buffer_pool_size (default 1G).
 *   --keep-data         Keep the scratch datadir after dumping (default: removed).
 *   --skip-system       Skip mysql/sys system DBs in addition to information_schema/performance_schema (default on).
 *   --include-system    Also dump mysql + sys.
 *   --only=DB[,DB...]   Dump only the named databases (comma-separated).
 *   --tar               After dumping, gzip-tar the sql/ folder to <work>/sql.tar.gz.
 *   --boot-timeout=SEC  Max seconds to wait for mysqld socket (default 600 — upgrades can be slow).
 *   --help              Show this help.
 *
 * Exit codes: 0 ok, 1 usage error, 2 mysqld boot failed, 3 dump failure.
 */

const DEFAULT_PORT          = 3308;
const DEFAULT_XPORT         = 33070;
const DEFAULT_BUFFER_POOL   = '1G';
const DEFAULT_BOOT_TIMEOUT  = 600;
const SOCK_POLL_INTERVAL_S  = 1;

function fail(string $msg, int $code = 1): void {
    fwrite(STDERR, "ibd2sql: $msg\n");
    exit($code);
}

function info(string $msg): void {
    fwrite(STDOUT, "[" . date('H:i:s') . "] $msg\n");
}

function which(string $bin): ?string {
    $out = trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
    return $out !== '' ? $out : null;
}

function parse_args(array $argv): array {
    $opts = getopt('', [
        'src:', 'work:', 'port:', 'xport:', 'mysqld:', 'mysql:', 'mysqldump:',
        'user:', 'buffer-pool:', 'keep-data', 'skip-system', 'include-system',
        'only:', 'tar', 'boot-timeout:', 'help',
    ]);
    if (isset($opts['help']) || !isset($opts['src'], $opts['work'])) {
        $self = basename(__FILE__);
        fwrite(STDOUT, "Usage: php $self --src=DATADIR --work=WORKDIR [options]\nSee header of this script for full option list.\n");
        exit(isset($opts['help']) ? 0 : 1);
    }
    return $opts;
}

function copy_datadir(string $src, string $dst): void {
    if (!is_dir($src)) fail("source datadir not found: $src");
    if (!is_dir($dst) && !mkdir($dst, 0750, true) && !is_dir($dst)) {
        fail("could not create $dst");
    }
    info("copying datadir $src -> $dst (this may take a while)");
    $cmd = sprintf('cp -a %s/. %s/', escapeshellarg($src), escapeshellarg($dst));
    passthru($cmd, $rc);
    if ($rc !== 0) fail("cp failed (exit $rc)", 2);
}

function write_cnf(string $path, array $cfg): void {
    $body  = "[mysqld]\n";
    foreach ($cfg as $k => $v) {
        if ($v === '' || $v === null) {
            $body .= "$k\n";
        } else {
            $body .= sprintf("%-32s= %s\n", $k, $v);
        }
    }
    $body .= "\n[client]\n";
    $body .= "socket                          = {$cfg['socket']}\n";
    $body .= "port                            = {$cfg['port']}\n";
    if (file_put_contents($path, $body) === false) fail("could not write $path");
}

function spawn_mysqld(string $mysqld, string $cnf, string $logFile): int {
    $cmd = sprintf('%s --defaults-file=%s >%s 2>&1 & echo $!',
        escapeshellcmd($mysqld), escapeshellarg($cnf), escapeshellarg($logFile));
    $pid = (int) trim((string) shell_exec($cmd));
    if ($pid < 1) fail("failed to spawn mysqld", 2);
    return $pid;
}

function wait_for_socket(string $sock, int $pid, int $timeoutSec, string $logFile): void {
    $deadline = time() + $timeoutSec;
    while (time() < $deadline) {
        if (file_exists($sock)) return;
        if (!posix_kill($pid, 0)) {
            $tail = (string) shell_exec('tail -n 60 ' . escapeshellarg($logFile));
            fail("mysqld died during startup. Log tail:\n$tail", 2);
        }
        sleep(SOCK_POLL_INTERVAL_S);
    }
    posix_kill($pid, SIGTERM);
    fail("timed out waiting for mysqld socket ($sock)", 2);
}

function shutdown_mysqld(int $pid): void {
    if (!posix_kill($pid, 0)) return;
    posix_kill($pid, SIGTERM);
    for ($i = 0; $i < 60; $i++) {
        if (!posix_kill($pid, 0)) return;
        sleep(1);
    }
    posix_kill($pid, SIGKILL);
}

function list_databases(string $mysql, string $cnf, bool $includeSystem, array $only): array {
    $cmd = sprintf('%s --defaults-file=%s -uroot -N -e %s 2>&1',
        escapeshellcmd($mysql), escapeshellarg($cnf), escapeshellarg('SHOW DATABASES;'));
    $out = (string) shell_exec($cmd);
    $skip = ['information_schema', 'performance_schema', 'sys'];
    if (!$includeSystem) $skip[] = 'mysql';
    $dbs = [];
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        $line = trim($line);
        if ($line === '' || in_array($line, $skip, true)) continue;
        if ($only !== [] && !in_array($line, $only, true)) continue;
        $dbs[] = $line;
    }
    return $dbs;
}

function dump_db(string $mysqldump, string $cnf, string $db, string $outFile, string $errFile): bool {
    $cmd = sprintf(
        '%s --defaults-file=%s -uroot ' .
        '--single-transaction --quick --routines --triggers --events ' .
        '--hex-blob --skip-lock-tables --set-gtid-purged=OFF ' .
        '--column-statistics=0 --databases %s > %s 2> %s',
        escapeshellcmd($mysqldump),
        escapeshellarg($cnf),
        escapeshellarg($db),
        escapeshellarg($outFile),
        escapeshellarg($errFile)
    );
    $rc = 0;
    passthru($cmd, $rc);
    return $rc === 0;
}

function main(array $argv): void {
    $opts = parse_args($argv);

    $src        = rtrim((string) $opts['src'], '/');
    $work       = rtrim((string) $opts['work'], '/');
    $port       = (int) ($opts['port'] ?? DEFAULT_PORT);
    $xport      = (int) ($opts['xport'] ?? DEFAULT_XPORT);
    $bufferPool = (string) ($opts['buffer-pool'] ?? DEFAULT_BUFFER_POOL);
    $bootTimeout = (int) ($opts['boot-timeout'] ?? DEFAULT_BOOT_TIMEOUT);
    $keepData    = array_key_exists('keep-data', $opts);
    $tar         = array_key_exists('tar', $opts);
    $includeSys  = array_key_exists('include-system', $opts);
    $only        = isset($opts['only']) ? array_values(array_filter(array_map('trim', explode(',', (string) $opts['only'])))) : [];

    $mysqld    = (string) ($opts['mysqld']    ?? which('mysqld')    ?? '/usr/sbin/mysqld');
    $mysql     = (string) ($opts['mysql']     ?? which('mysql')     ?? '/usr/bin/mysql');
    $mysqldump = (string) ($opts['mysqldump'] ?? which('mysqldump') ?? '/usr/bin/mysqldump');
    $osUser    = (string) ($opts['user'] ?? posix_getpwuid(posix_geteuid())['name']);

    foreach (['mysqld' => $mysqld, 'mysql' => $mysql, 'mysqldump' => $mysqldump] as $name => $path) {
        if (!is_executable($path)) fail("$name not executable at $path");
    }

    $dataDir = "$work/data";
    $sqlDir  = "$work/sql";
    $logDir  = "$work/log";
    $tmpDir  = "$work/tmp";
    $runDir  = "$work/run";
    $cnfPath = "$work/my.cnf";

    foreach ([$work, $sqlDir, $logDir, $tmpDir, $runDir] as $d) {
        if (!is_dir($d) && !mkdir($d, 0750, true) && !is_dir($d)) fail("could not create $d");
    }

    if (is_dir($dataDir) && (new \FilesystemIterator($dataDir))->valid()) {
        fail("scratch data dir not empty: $dataDir (move or remove it first)");
    }

    copy_datadir($src, $dataDir);

    $sockPath  = "$runDir/mysqld.sock";
    $xSockPath = "$runDir/mysqlx.sock";
    $pidPath   = "$runDir/mysqld.pid";
    $errLog    = "$logDir/error.log";
    $stdoutLog = "$logDir/stdout.log";

    write_cnf($cnfPath, [
        'user'                              => $osUser,
        'datadir'                           => $dataDir,
        'tmpdir'                            => $tmpDir,
        'socket'                            => $sockPath,
        'pid-file'                          => $pidPath,
        'log-error'                         => $errLog,
        'port'                              => $port,
        'mysqlx'                            => 'ON',
        'mysqlx_port'                       => $xport,
        'mysqlx_socket'                     => $xSockPath,
        'bind-address'                      => '127.0.0.1',
        'skip-name-resolve'                 => '',
        'skip-grant-tables'                 => '',
        'skip-networking'                   => '',
        'disable-log-bin'                   => '',
        'default-authentication-plugin'     => 'mysql_native_password',
        'innodb_buffer_pool_size'           => $bufferPool,
        'innodb_flush_log_at_trx_commit'    => '0',
        'innodb_doublewrite'                => '0',
        'sync_binlog'                       => '0',
        'secure-file-priv'                  => $tmpDir,
        'upgrade'                           => 'FORCE',
    ]);

    info("starting mysqld (timeout ${bootTimeout}s; first boot may include upgrade + redo recovery)");
    $pid = spawn_mysqld($mysqld, $cnfPath, $stdoutLog);
    info("mysqld pid=$pid");

    wait_for_socket($sockPath, $pid, $bootTimeout, $errLog);
    info("mysqld socket up");

    $dbs = list_databases($mysql, $cnfPath, $includeSys, $only);
    if ($dbs === []) {
        shutdown_mysqld($pid);
        fail("no user databases found", 3);
    }
    info("found " . count($dbs) . " databases to dump");

    $ok = 0; $fail = 0; $failedDbs = [];
    foreach ($dbs as $db) {
        $safe = str_replace(['/', "\0"], '_', $db);
        $out  = "$sqlDir/{$safe}.sql";
        $err  = "$sqlDir/{$safe}.err";
        info("dump $db");
        if (dump_db($mysqldump, $cnfPath, $db, $out, $err)) {
            $sz = filesize($out) ?: 0;
            info("  ok " . number_format($sz) . " bytes");
            @unlink($err);
            $ok++;
        } else {
            $fail++;
            $failedDbs[] = $db;
            info("  FAIL — see $err");
        }
    }

    info("shutting down mysqld");
    shutdown_mysqld($pid);

    if (!$keepData) {
        info("removing scratch datadir $dataDir");
        passthru('rm -rf ' . escapeshellarg($dataDir) . ' ' . escapeshellarg($logDir) . ' ' .
                 escapeshellarg($tmpDir) . ' ' . escapeshellarg($runDir));
    }

    if ($tar) {
        $archive = "$work/sql.tar.gz";
        info("creating archive $archive");
        $cmd = sprintf('tar -czf %s -C %s sql', escapeshellarg($archive), escapeshellarg($work));
        passthru($cmd, $rc);
        if ($rc !== 0) fail("tar failed (exit $rc)", 3);
    }

    info("done. ok=$ok fail=$fail. output: $sqlDir");
    if ($fail > 0) {
        fwrite(STDERR, "failed databases: " . implode(', ', $failedDbs) . "\n");
        exit(3);
    }
}

main($argv);
