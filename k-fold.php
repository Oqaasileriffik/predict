#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/vendor/autoload.php';
ini_set('memory_limit', '24G');

// Fixed seed to ensure reproducible results
mt_srand(42);
$n_folds = 10;

$corpus = new \TDC\PDO\SQLite("corpus.sqlite", [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);

$pars = $corpus->prepexec("SELECT p_id, LENGTH(p_body_norm) as p_len FROM pars ORDER BY p_id ASC LIMIT 100000")->fetchAll();
// Fisher-Yates shuffle
for ($i=0 ; $i<count($pars)-2 ; ++$i) {
	$k = mt_rand($i, count($pars)-1);
	$tmp = $pars[$k];
	$pars[$k] = $pars[$i];
	$pars[$i] = $tmp;
}

// Append to a fold, trying to keep all folds equal length
$folds = array_fill(0, $n_folds, ['length' => 0, 'pars' => []]);
foreach ($pars as $par) {
	$min_f = 0;
	for ($i=0 ; $i<$n_folds ; ++$i) {
		if ($folds[$i]['length'] <= $folds[$min_f]['length']) {
			$min_f = $i;
		}
	}
	$folds[$min_f]['length'] += $par['p_len'];
	$folds[$min_f]['pars'][] = $par['p_id'];
}

echo "Fold\tPars\tBytes\n";
foreach ($folds as $k => $fold) {
	echo "$k\t".count($fold['pars'])."\t{$fold['length']}\n";
}

foreach ($folds as $k => $fold) {
	echo "\nFold $k\n";

	chdir(__DIR__);
	shell_exec("rm -rf fold$k; mkdir fold$k");
	chdir("fold$k");

	$db_file = $argv[1] ?? 'ngrams.sqlite';
	$db = new \TDC\PDO\SQLite($db_file);

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

	$db->exec("CREATE TABLE pars (
		p_id INTEGER NOT NULL,
		p_test INTEGER NOT NULL,
		PRIMARY KEY (p_id)
	) WITHOUT ROWID");
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

	$db->beginTransaction();
	$ins = $db->prepare("INSERT INTO pars (p_id, p_test) VALUES (?, ?)");
	foreach ($folds as $k2 => $fold2) {
		foreach ($fold2['pars'] as $pid) {
			if ($k === $k2) {
				$ins->execute([$pid, 1]);
			}
			else {
				$ins->execute([$pid, 0]);
			}
		}
	}
	$db->commit();

	$units_cnt = 0;
	$uniq_units = ["\u{e000}" => 0];

	$db->beginTransaction();
	$ins_unit = $db->prepare("INSERT INTO units (u_id, u_text) VALUES (?, ?)");
	$upd_unit = $db->prepare("UPDATE units SET cnt = cnt + 1 WHERE u_id = ?");
	$ins_unit->execute([0, "\u{e000}"]);

	$sliding = [];
	$db->query("ATTACH '../corpus.sqlite' AS corpus");

	$i = 0;
	$res = $db->prepexec("SELECT p_id, p_body_norm as txt FROM corpus.pars WHERE p_id IN (SELECT p_id FROM pars WHERE p_test = 0)");
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

	ksort($sliding, SORT_STRING);

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
	//$db->exec("CREATE UNIQUE INDEX primary_slide ON sliding (u1 ASC, u2 ASC, u3 ASC, u4 ASC, u5 ASC, u6 ASC)");
	$db->exec("CREATE INDEX index_u5 ON sliding (u5 ASC)");
	$db->exec("PRAGMA ignore_check_constraints = OFF");
	$db->exec("PRAGMA locking_mode = NORMAL");
}
