
ibd2sql.php — convert a MySQL 8.x datadir (.ibd/ibdata1/mysql.ibd) into per-database .sql dumps for SQL data recovery
##
How it works: copy datadir to a scratch location, boot a private mysqld on it with --skip-grant-tables,
mysqldump every user database, shut down, optionally clean up.
##
**Prerequisites:**
  *   mysqld + mysql + mysqldump binaries installed (matching or newer major than the datadir).
  *   AppArmor: if datadir or workdir is under /home, set the mysqld profile to complain mode first:
  *      `sudo aa-complain /usr/sbin/mysqld`
  *    Restore after:
  *      `sudo aa-enforce /usr/sbin/mysqld`
  *   Enough free disk for: datadir copy + dumps. Plan ~2.5x datadir size.
##
 **Usage:**
    `php ibd2sql.php --src=/path/to/datadir --work=/path/to/workdir [options]`
##
 **Options:**
 *   `--src=PATH`          Source MySQL datadir (read-only; copied, not modified).
 *   `--work=PATH`         Scratch + output directory (created if missing).
 *   `--port=N`            Alt TCP port for mysqld (default 3308).
 *   `--xport=N`           Alt mysqlx port (default 33070).
 *   `--mysqld=PATH`       mysqld binary (default: which mysqld).
 *   `--mysql=PATH`        mysql client binary.
 *   `--mysqldump=PATH`    mysqldump binary.
 *   `--user=NAME`         OS user mysqld runs as (default: current user).
 *   `--buffer-pool=SIZE`  innodb_buffer_pool_size (default 1G).
 *   `--keep-data`         Keep the scratch datadir after dumping (default: removed).
 *   `--skip-system`       Skip mysql/sys system DBs in addition to information_schema/performance_schema (default on).
 *   `--include-system`    Also dump mysql + sys.
 *   `--only=DB[,DB...]`   Dump only the named databases (comma-separated).
 *   `--tar`               After dumping, gzip-tar the sql/ folder to <work>/sql.tar.gz.
 *   `--boot-timeout=SEC` Max seconds to wait for mysqld socket (default 600 — upgrades can be slow).
 *   `--help`              Show this help.
##
  Exit codes: 0 ok, 1 usage error, 2 mysqld boot failed, 3 dump failure.
