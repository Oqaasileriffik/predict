#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/vendor/autoload.php';

$db = new \TDC\PDO\SQLite("ngrams.sqlite", [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
$db->exec("PRAGMA case_sensitive_like = ON");

$txt = $db->prepare("SELECT u_text FROM units WHERE u_id = ?");
$sel_sliding = $db->prepare("SELECT u6, cnt FROM sliding WHERE u1 = ? AND u2 = ? AND u3 = ? AND u4 = ? AND u5 = ? ORDER BY cnt DESC LIMIT 4");
$sel_units = $db->prepare("SELECT u_id FROM units WHERE u_text LIKE ? ORDER BY cnt DESC LIMIT 100");

// Initialize with most frequent lemmas
$res = $db->prepexec("SELECT u_id as u6, cnt FROM units WHERE u_text LIKE '\"%' ORDER BY cnt DESC LIMIT 4");

$state = [0, 0, 0, 0, 0];
$in = '';
while (42) {
	echo "STATE: ".implode(' ', $state)."\n";
	$out = [];
	while ($row = $res->fetch()) {
		$txt->execute([$row['u6']]);
		$out[] = [$row['u6'], $txt->fetch()['u_text'], $row['cnt']];
	}
	foreach ($out as $k => $o) {
		echo "#$k: {$o[1]} ({$o[2]})\n";
	}

	$in .= trim(fgets(STDIN));
	if (empty($in)) {
		break;
	}

	if (preg_match('~#([0-9]+)$~', $in, $m)) {
		// User picked a unit from the list
		$in = intval($m[1]);
		array_shift($state);
		$state[] = $out[$in][0];
		$sel_sliding->execute($state);
		$res = $sel_sliding;
		$in = '';
	}
	else {
		// User typed a letter, so try to find units starting/continuing with that letter
		$sel_units->execute(["$in%"]);
		$units = $sel_units->fetchAll(PDO::FETCH_COLUMN, 0);
		//echo "Found ".count($units)." partial units matching $in: ".implode(', ', $units)."\n";
		// Exclude currently shown continuations
		$qs = array_merge($state, array_column($out, 0), $units);
		$res = $db->prepexec("SELECT u6, cnt FROM sliding WHERE u1 = ? AND u2 = ? AND u3 = ? AND u4 = ? AND u5 = ? AND u6 NOT IN (?".str_repeat(', ?', count($out)-1).") AND u6 IN (?".str_repeat(', ?', count($units)-1).") ORDER BY cnt DESC LIMIT 4", $qs);
	}
}
