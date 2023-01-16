#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/vendor/autoload.php';
ini_set('memory_limit', '24G');

$db_file = $argv[1] ?? 'ngrams.sqlite';
$db = new \TDC\PDO\SQLite($db_file);

$corpus = new \TDC\PDO\SQLite("corpus.sqlite", [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);

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
$db->exec("CREATE TABLE sliding (
	u1 INTEGER NOT NULL,
	u2 INTEGER NOT NULL,
	u3 INTEGER NOT NULL,
	u4 INTEGER NOT NULL,
	u5 INTEGER NOT NULL,
	u6 INTEGER NOT NULL,
	cnt INTEGER NOT NULL,
	PRIMARY KEY (u1, u2, u3, u4, u5, u6)
) WITHOUT ROWID");

$units_cnt = 0;
$uniq_units = ["\u{e000}" => 0];

$db->beginTransaction();
$ins_unit = $db->prepare("INSERT INTO units (u_id, u_text) VALUES (?, ?)");
$upd_unit = $db->prepare("UPDATE units SET cnt = cnt + 1 WHERE u_id = ?");
$ins_unit->execute([0, "\u{e000}"]);

$sliding = [];

$i = 0;
$res = $corpus->prepexec("SELECT p_id, p_body_norm as txt FROM pars ORDER BY p_id ASC");
while ($row = $res->fetch()) {
	// Remember state per token, so that when there are ambiguous readings we can continue from previous token for each one
	$state = [0, 0, 0, 0, 0, 0];
	$oldstate = $state;

	$lines = explode("\n", $row['txt']);
	foreach ($lines as $line) {
		if (preg_match('~^"<~', $line)) {
			$oldstate = $state;
		}
		else if (preg_match('~^\t~', $line)) {
			$wstate = [0, 0, 0, 0, 0, 0];
			$state = $oldstate;
			$units = explode(' ', trim($line));
			foreach ($units as $unit) {
				if (!array_key_exists($unit, $uniq_units)) {
					++$units_cnt;
					$ins_unit->execute([$units_cnt, $unit]);
					$uniq_units[$unit] = $units_cnt;
				}
				else {
					$upd_unit->execute([$uniq_units[$unit]]);
				}

				// Per-word sliding window
				array_shift($wstate);
				$wstate[] = $uniq_units[$unit];
				$k = pack('N*', ...$wstate);
				$sliding[$k] = ($sliding[$k] ?? 0) + 1;

				// Global sliding window
				array_shift($state);
				$state[] = $uniq_units[$unit];
				$k = pack('N*', ...$state);
				$sliding[$k] = ($sliding[$k] ?? 0) + 1;

				if (++$i % 100000 === 0) {
					echo "{$row['p_id']} $i\r";
					$db->commit();
					$db->beginTransaction();
				}
			}
		}
	}

	while ($state[1] !== 0) {
		array_shift($state);
		$state[] = 0;
		$k = pack('N*', ...$state);
		$sliding[$k] = ($sliding[$k] ?? 0) + 1;
	}
}
echo "$i              \n";
$db->commit();
$db->beginTransaction();

$ins_slide = $db->prepare("INSERT INTO sliding (u1, u2, u3, u4, u5, u6, cnt) VALUES (?, ?, ?, ?, ?, ?, ?)");
$i = 0;
foreach ($sliding as $k => $cnt) {
	$k = array_values(unpack('N*', $k));
	$k[] = $cnt;
	$ins_slide->execute($k);

	if (++$i % 100000 === 0) {
		echo "$i\r";
		$db->commit();
		$db->beginTransaction();
	}
}
echo "$i              \n";

$db->commit();
$db->exec("CREATE INDEX index_u5 ON sliding (u5 ASC)");
$db->exec("PRAGMA ignore_check_constraints = OFF");
$db->exec("PRAGMA locking_mode = NORMAL");
