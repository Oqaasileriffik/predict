#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/vendor/autoload.php';
ini_set('memory_limit', '24G');

$n_folds = 10;

for ($k=0 ; $k<$n_folds ; ++$k) {
	echo "\nFold $k\n";

	chdir(__DIR__);
	chdir("fold$k");

	$full = new \TDC\PDO\SQLite("full.sqlite", [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
	shell_exec("rm -f ngrams.6.sqlite; ln -sf full.sqlite ngrams.6.sqlite");

	$dbs = [];
	for ($i=2 ; $i<6 ; ++$i) {
		@unlink("ngrams.$i.sqlite");
		$db = new \TDC\PDO\SQLite("ngrams.$i.sqlite");

		$db->exec("PRAGMA journal_mode = delete");
		$db->exec("PRAGMA page_size = 65536");
		$db->exec("VACUUM");

		$db->exec("PRAGMA auto_vacuum = INCREMENTAL");
		$db->exec("PRAGMA case_sensitive_like = ON");
		$db->exec("PRAGMA foreign_keys = OFF");
		$db->exec("PRAGMA ignore_check_constraints = ON");
		$db->exec("PRAGMA journal_mode = MEMORY");
		$db->exec("PRAGMA locking_mode = EXCLUSIVE");
		$db->exec("PRAGMA synchronous = OFF");
		$db->exec("PRAGMA threads = 4");
		$db->exec("PRAGMA trusted_schema = OFF");

		$db->exec("CREATE TABLE units (
			u_id INTEGER NOT NULL,
			u_text TEXT NOT NULL,
			cnt INTEGER NOT NULL DEFAULT 1,
			PRIMARY KEY (u_id)
		) WITHOUT ROWID");

		$db->query("ATTACH 'full.sqlite' as fdb");
		$db->beginTransaction();
		$db->query("INSERT INTO units SELECT * FROM fdb.units");
		$db->commit();
		$db->query("DETACH fdb");

		$cols = [];
		for ($u=1 ; $u<=$i ; ++$u) {
			$cols["u$u"] = "u$u INTEGER NOT NULL";
		}
		$us = implode(', ', array_keys($cols));
		$db->exec("CREATE TABLE sliding (
			".implode(', ', $cols).",
			cnt INTEGER NOT NULL,
			PRIMARY KEY ($us)
		) WITHOUT ROWID");

		$dbs[$i] = [
			'cols' => $cols,
			'us' => $us,
			'db' => $db,
			'ins' => $db->prepare("INSERT INTO sliding ($us, cnt) VALUES (".str_repeat('?, ', $i)."?) ON CONFLICT($us) DO UPDATE SET cnt = cnt + ?"),
			];
		$db->beginTransaction();
	}

	$n = 0;
	$res = $full->prepexec("SELECT * FROM sliding");
	while ($row = $res->fetch()) {
		++$n;
		$row = array_values($row);
		while (count($row) > 3) {
			array_shift($row);
			$i = count($row)-1;
			$dbs[$i]['ins']->execute(array_merge($row, [$row[$i]]));
			if ($n % 100000 == 0) {
				echo "$n        \r";
				$dbs[$i]['db']->commit();
				$dbs[$i]['db']->beginTransaction();
			}
		}
	}
	echo "$n        \n";

	for ($i=2 ; $i<6 ; ++$i) {
		$dbs[$i]['db']->commit();
		$u = $i-1;
		$dbs[$i]['db']->exec("CREATE INDEX index_u$u ON sliding (u$u ASC)");
		$dbs[$i]['db']->exec("PRAGMA ignore_check_constraints = OFF");
		$dbs[$i]['db']->exec("PRAGMA locking_mode = NORMAL");
	}
}
