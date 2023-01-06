#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/vendor/autoload.php';
ini_set('memory_limit', '24G');

$db_file = $argv[1] ?? 'corpus.sqlite';
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
	p_tokens INTEGER NOT NULL,
	p_morphs INTEGER NOT NULL,
	p_body TEXT NOT NULL,
	p_body_norm TEXT NOT NULL,
	PRIMARY KEY (p_id)
) WITHOUT ROWID");

$db->beginTransaction();
$ins = $db->prepare("INSERT INTO pars (p_id, p_tokens, p_morphs, p_body, p_body_norm) VALUES (?, ?, ?, ?, ?)");

$in_par = false;
$tokens = 0;
$morphs = 0;
$par = '';
$par_norm = '';
$i = 0;
while ($line = fgets(STDIN)) {
	if (preg_match('~^<s(\d+)>~', $line, $m)) {
		$in_par = intval($m[1]);
	}
	else if (preg_match('~^</s(\d+)>~', $line, $m)) {
		if (intval($m[1]) !== $in_par) {
			echo "MISMATCH: {$m[1]} != {$in_par}\n";
			$in_par = false;
			$tokens = $morphs = 0;
			$par = $par_norm = '';
			continue;
		}
		$ins->execute([$in_par, $tokens, $morphs, trim($par), trim($par_norm)]);
		++$i;
		if ($i % 10000 === 0) {
			echo "$i\r";
			$db->commit();
			$db->beginTransaction();
		}
		$in_par = false;
		$tokens = $morphs = 0;
		$par = $par_norm = '';
	}
	else if ($in_par) {
		$par .= $line;
		if (preg_match('~^"<[\pL\pM\pN]~u', $line)) {
			// Only count alphanumerics
			++$tokens;
			$par_norm .= $line;
		}
		else if (preg_match('~^"<~u', $line)) {
			$par_norm .= $line;
		}
		else if (preg_match('~^\t"([^\n]+?"?[^"]*)" ~u', $line, $m)) {
			$bf = $m[1];
			$norm = $line;

			$norm = str_replace('"'.$bf.'"', "\"\u{e001}\"", $norm);
			$bf = str_replace(' ', "\u{e002}", $bf);

			$norm = preg_replace('~\t(".+?") Prefix/(\S+)~', "\t$2 $1", $norm);
			$norm = preg_replace('~ i([A-Z]\S*) ~u', ' $1 ', $norm);
			$norm = preg_replace('~ DIRTALE\S+~', '', $norm);
			$norm = preg_replace('~ <\S+~', '', $norm);
			$norm = preg_replace('~ @\S+~', '', $norm);
			$norm = preg_replace('~ #\S+~', '', $norm);
			$norm = preg_replace('~ Der/(\S+)~', ' der/$1', $norm);
			$norm = preg_replace('~ [A-Z][^/\s]*/\S+~', '', $norm);
			$norm = preg_replace('~ (N|V|Pali|Conj|Adv|Interj|Pron|Prop|Num|Symbol) ([0-9A-Z]+[a-z]+\S*) ([0-9A-Z]+[a-z]+\S*) ([0-9A-Z]+[a-z]+\S*)~', ' $1+$2+$3+$4', $norm);
			$norm = preg_replace('~ (N|V|Pali|Conj|Adv|Interj|Pron|Prop|Num|Symbol) ([0-9A-Z]+[a-z]+\S*) ([0-9A-Z]+[a-z]+\S*)~', ' $1+$2+$3', $norm);
			$norm = preg_replace('~ (N|V|Pali|Conj|Adv|Interj|Pron|Prop|Num|Symbol) ([0-9A-Z]+[a-z]+\S*)~', ' $1+$2', $norm);
			$norm = preg_replace('~ der/(\S+)~', '+$1', $norm);

			$norm = str_replace("\"\u{e001}\"", '"'.$bf.'"', $norm);
			$par_norm .= $norm;
			//echo "$norm";
			if (preg_match('~"[\pL\pM\pN]~', $norm)) {
				// Only count alphanumerics
				$morphs += substr_count($norm, ' ')+1;
			}
		}
	}
}
echo "$i\n";

$db->commit();
$db->exec("PRAGMA ignore_check_constraints = OFF");
$db->exec("PRAGMA locking_mode = NORMAL");
