#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/vendor/autoload.php';

function sqlite_regexp($pattern, $subject) {
	return (preg_match($pattern, $subject) !== 0);
}

$db = new \TDC\PDO\SQLite("ngrams.sqlite", [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
$db->exec("PRAGMA case_sensitive_like = ON");
$db->sqliteCreateFunction('regexp', 'sqlite_regexp', 2);

$pos = [];
$res = $db->prepexec("SELECT u_id, u_text FROM units WHERE (u_text LIKE '%+%' OR u_text REGEXP '~^(N|V|Pali|Conj|Adv|Interj|Pron|Prop|Num|Symbol)(\+|$)~') AND u_text NOT LIKE '%+vv' AND u_text NOT LIKE '%+nv' AND u_text NOT LIKE '%+vn' AND u_text NOT LIKE '%+nn' AND u_text NOT LIKE '%\"%' ORDER BY cnt DESC LIMIT 100");
while ($row = $res->fetch()) {
	$pos[$row['u_id']] = $row['u_text'];
}

$txt = $db->prepare("SELECT u_text FROM units WHERE u_id = ?");
$sel_sliding = $db->prepare("SELECT u1, u2, u3, u4, u5, u6, cnt FROM sliding WHERE u1 = ? AND u2 = ? AND u3 = ? AND u4 = ? AND u5 = ? ORDER BY cnt DESC LIMIT 4");
$sel_auto = $db->prepare("SELECT u6 FROM sliding WHERE u1 = ? AND u2 = ? AND u3 = ? AND u4 = ? AND u5 = ? AND u6 IN (".implode(', ', array_keys($pos)).") ORDER BY cnt DESC LIMIT 1");
$sel_units = $db->prepare("SELECT u_id FROM units WHERE u_text LIKE ? ORDER BY cnt DESC LIMIT 100");

// Initialize with most frequent lemmas
$rows = $db->prepexec("SELECT 0 as u1, 0 as u2, 0 as u3, 0 as u4, 0 as u5, u_id as u6, cnt FROM units WHERE u_text REGEXP '~^\"[A-Za-z]~i' ORDER BY cnt DESC LIMIT 4")->fetchAll();

$build = [];
$state = [0, 0, 0, 0, 0];
$auto = 0;
$in = '';
while (42) {
	echo "S: ".implode(' ', $state)." ; B: ".implode(' ', $build)." ; IN: $in\n";
	$out = [];
	foreach ($rows as $row) {
		$txt->execute([$row['u6']]);
		$nstate = array_values($row);
		array_pop($nstate);
		array_shift($nstate);
		$out[] = [$nstate, $txt->fetch()['u_text'], $row['cnt']];
	}
	if (empty($out)) {
		// Current state yielded no possible continuations, so try to recover
	}
	foreach ($out as $k => $o) {
		echo "\t#$k: {$o[1]} ({$o[2]}) (".implode(',', $o[0]).")\n";
	}

	$r = fgets(STDIN);
	if (empty($r)) {
		break;
	}
	$in .= trim($r);

	if ($r === " \n") {
		if ($auto) {
			// User accepted the most likely part-of-speech unit
			// Fake that by putting it as the first option and selecting that option
			$in = "#0";
			$out[0] = [$state, $pos[$auto]];
			array_shift($out[0][0]);
			$out[0][0][] = $auto;
		}
		else {
			// We have no good state, so try to recover
		}
	}

	if (preg_match('~#([0-9]+)$~', $in, $m)) {
		// User picked a unit from the list
		$in = intval($m[1]);
		$state = $out[$in][0];

		if (!empty($build) && preg_match('~^(TA|AA|")~', $out[$in][1])) {
			$emit = trim(shell_exec('echo "'.implode('+', $build).'" | hfst-optimized-lookup -p -u ~/langtech/kal/src/generator-gt-norm.hfstol | grep -vF "?" | head -n 1'));
			if ($emit) {
				echo "EMIT: $emit\n";
				$build = [];
			}
		}

		if ($out[$in][1][0] === '"') {
			$build[] = substr($out[$in][1], 1, -1);
		}
		else {
			$build[] = preg_replace('~^(CONJ|ADV)-~', '', preg_replace('~\+([vn][vn])$~', '+Der/$1', $out[$in][1]));
		}

		$sel_auto->execute($state);
		$auto = $sel_auto->fetchColumn(0);
		if ($auto) {
			$emit = trim(shell_exec('echo "'.implode('+', $build).'+'.$pos[$auto].'" | hfst-optimized-lookup -p -u ~/langtech/kal/src/generator-gt-norm.hfstol | grep -vF "?" | head -n 1'));
			echo "$auto: {$pos[$auto]} => {$emit}\n";
		}

		$sel_sliding->execute($state);
		$rows = $sel_sliding->fetchAll();
		$in = '';
	}
	else {
		// User typed a letter, so try to find units starting/continuing with that letter
		$sel_units->execute(["$in%"]);
		$units = $sel_units->fetchAll(PDO::FETCH_COLUMN, 0);
		//echo "Found ".count($units)." partial units matching $in: ".implode(', ', $units)."\n";
		// Exclude currently shown continuations
		if (empty($units)) {
			$qs = array_merge($state, array_column(array_column($out, 0), 4));
			$rows = $db->prepexec("SELECT u1, u2, u3, u4, u5, u6, cnt FROM sliding WHERE u1 = ? AND u2 = ? AND u3 = ? AND u4 = ? AND u5 = ? AND u6 NOT IN (?".str_repeat(', ?', count($out)-1).") ORDER BY cnt DESC LIMIT 4", $qs)->fetchAll();
		}
		else {
			$qs = array_merge($state, array_column(array_column($out, 0), 4), $units);
			$rows = $db->prepexec("SELECT u1, u2, u3, u4, u5, u6, cnt FROM sliding WHERE u1 = ? AND u2 = ? AND u3 = ? AND u4 = ? AND u5 = ? AND u6 NOT IN (?".str_repeat(', ?', count($out)-1).") AND u6 IN (?".str_repeat(', ?', count($units)-1).") ORDER BY cnt DESC LIMIT 4", $qs)->fetchAll();

			for ($i=2 ; $i < 6 && empty($rows) ; ++$i) {
				array_shift($qs);
				$us = '';
				for ($u=$i ; $u < 6 ; ++$u) {
					$us .= "u{$u} = ? AND ";
				}
				$rows = $db->prepexec("SELECT u1, u2, u3, u4, u5, u6, cnt FROM sliding WHERE {$us} u6 NOT IN (?".str_repeat(', ?', count($out)-1).") AND u6 IN (?".str_repeat(', ?', count($units)-1).") ORDER BY cnt DESC LIMIT 4", $qs)->fetchAll();
			}
		}
	}
}
