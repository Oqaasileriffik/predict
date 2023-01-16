#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/vendor/autoload.php';

$GLOBALS['composition-preview'] = false;
$GLOBALS['suggestions'] = 3;
$GLOBALS['custom-keyboard'] = true;
$GLOBALS['keyboard-buttons'] = 12;
$GLOBALS['min-cnt'] = 0; // behave as if entries with less hits than this were pruned; default 0 to include everything
$GLOBALS['ngrams'] = 5;

function sqlite_regexp($pattern, $subject) {
	return (preg_match($pattern, $subject) !== 0);
}

$db = new \TDC\PDO\SQLite("ngrams.{$GLOBALS['ngrams']}.sqlite", [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
$db->exec("PRAGMA case_sensitive_like = ON");
$db->sqliteCreateFunction('regexp', 'sqlite_regexp', 2);

$qcols = [];
$icols = [];
$ucols = '';
for ($i=1 ; $i<$GLOBALS['ngrams'] ; ++$i) {
	$qcols[] = "u$i = ?";
	$icols[] = "0 as u$i";
	$ucols .= "u$i, ";
}
$qcols = implode(' AND ', $qcols);
$icols = implode(', ', $icols);
$umax = 'u'.$GLOBALS['ngrams'];
$ucols .= $umax;

$keys = [];
$skip_keys = '';
if ($GLOBALS['custom-keyboard']) {
	$GLOBALS['custom-keyboard'] = 1;
	$res = $db->prepexec("SELECT u_id, u_text FROM units WHERE (u_text LIKE '%+vv' OR u_text LIKE '%+nv' OR u_text LIKE '%+vn' OR u_text LIKE '%+nn') AND cnt > {$GLOBALS['min-cnt']} ORDER BY cnt DESC LIMIT ".($GLOBALS['keyboard-buttons'] * 5));
	$skip_keys = [];
	while ($row = $res->fetch()) {
		// The first N keys cost 1, the rest are long-press so they cost 2
		$cost = (count($keys) < $GLOBALS['keyboard-buttons'] ? 1 : 2);
		$keys[$row['u_text']] = [$row['u_id'], $cost];
		if ($cost == 1) {
			// Don't suggest morphemes on the custom keyboard front, but do suggest ones behind long-press keys
			$skip_keys[] = $row['u_id'];
		}
	}
	$skip_keys = 'AND '.$umax.' NOT IN ('.implode(', ', $skip_keys).')';
}
else {
	$GLOBALS['custom-keyboard'] = 0;
}

$pos = [];
$res = $db->prepexec("SELECT u_id, u_text FROM units WHERE (u_text LIKE '%+%' OR u_text REGEXP '~^(N|V|Pali|Conj|Adv|Interj|Pron|Prop|Num|Symbol)(\+|$)~') AND u_text NOT LIKE '%+vv' AND u_text NOT LIKE '%+nv' AND u_text NOT LIKE '%+vn' AND u_text NOT LIKE '%+nn' AND u_text NOT LIKE '%\"%' AND cnt > {$GLOBALS['min-cnt']} ORDER BY cnt DESC LIMIT 100");
while ($row = $res->fetch()) {
	$pos[$row['u_id']] = $row['u_text'];
}

$txt = $db->prepare("SELECT u_text FROM units WHERE u_id = ?");
$sel_sliding = $db->prepare("SELECT {$ucols}, cnt FROM sliding WHERE {$qcols} AND cnt > {$GLOBALS['min-cnt']} {$skip_keys} ORDER BY cnt DESC LIMIT {$GLOBALS['suggestions']}");
$sel_auto = $db->prepare("SELECT {$umax} FROM sliding WHERE {$qcols} AND {$umax} IN (".implode(', ', array_keys($pos)).") AND cnt > {$GLOBALS['min-cnt']} ORDER BY cnt DESC LIMIT 1");
$sel_units = $db->prepare("SELECT u_id FROM units WHERE u_text LIKE ? AND cnt > {$GLOBALS['min-cnt']} ORDER BY cnt DESC LIMIT 100");

// Initialize with most frequent stems
$rows = $db->prepexec("SELECT {$icols}, u_id as {$umax}, cnt FROM units WHERE u_text REGEXP '~^\"[A-Za-z]~i' ORDER BY cnt DESC LIMIT {$GLOBALS['suggestions']}")->fetchAll();

$max_cost = 0;
$total_cost = 0;
$cost = 1;
$on_keyb = false;

$state = array_fill(0, $GLOBALS['ngrams']-1, 0);
$auto = 0;
while ($line = fgets(STDIN)) {
	$tokens = explode(' ', trim($line));

	while (!empty($tokens)) {
		//echo "S: ".implode(' ', $state)."\n";
		$out = [];

		if ($GLOBALS['custom-keyboard'] && array_key_exists($tokens[0], $keys)) {
			$kb = [];
			for ($u=1 ; $u < $GLOBALS['ngrams'] ; ++$u) {
				$kb["u$u"] = $state[$u-1];
			}
			$kb[$umax] = $keys[$tokens[0]][0];
			$kb['cnt'] = -1;
			// Fake keyboard by appending it as a suggestion
			$rows[] = $kb;
		}

		// Current state yielded no possible continuations, so try to recover
		$qs = $state;
		for ($i=2 ; $i < $GLOBALS['ngrams'] && empty($rows) ; ++$i) {
			array_shift($qs);
			$us = [];
			for ($u=$i ; $u < $GLOBALS['ngrams'] ; ++$u) {
				$us[] = "u{$u} = ?";
			}
			$us = implode(' AND ', $us);
			$rows = $db->prepexec("SELECT {$ucols}, cnt FROM sliding WHERE {$us} AND cnt > {$GLOBALS['min-cnt']} {$skip_keys} ORDER BY cnt DESC LIMIT {$GLOBALS['suggestions']}", $qs)->fetchAll();
		}

		foreach ($rows as $row) {
			$txt->execute([$row[$umax]]);
			$nstate = array_values($row);
			array_pop($nstate);
			array_shift($nstate);
			$out[] = [$nstate, $txt->fetch()['u_text'], $row['cnt']];
		}
		/*
		foreach ($out as $k => $o) {
			echo "\t#$k: {$o[1]} ({$o[2]}) (".implode(',', $o[0]).")\n";
		}
		//*/

		$in = '';
		foreach ($out as $k => $o) {
			if ($tokens[0] === $o[1]) {
				$in = "#$k";
				break;
			}
		}
		// User accepted the most likely part-of-speech unit
		if ($auto && $tokens[0] === $pos[$auto]) {
			// Fake that by putting it as the first option and selecting that option
			$in = "#0";
			$out[0] = [$state, $pos[$auto], 0];
			array_shift($out[0][0]);
			$out[0][0][] = $auto;
			$auto = 0;
			// If we just finished a word, automatically switch to alphabetic keyboard at zero cost
			$on_keyb = false;
		}
		// If none of the suggestions are usable, input letters to steer future suggestions
		if (empty($in)) {
			if ($GLOBALS['custom-keyboard'] && $on_keyb) {
				++$cost;
			}
			$on_keyb = false;

			$in = substr($tokens[0], 0, $cost);
			++$cost;
			// Nothing matched, so we've now typed in the whole token
			if ($in === $tokens[0]) {
				// Fake that by putting it as the first option and selecting that option
				$in = "#0";
				$out[0] = [$state, $tokens[0], 0];
				array_shift($out[0][0]);
				$id = $db->prepexec("SELECT u_id FROM units WHERE u_text = ?")->fetchAll();
				if (!empty($id)) {
					$out[0][0][] = $id[0]['u_id'];
				}
				else {
					$out[0][0][] = 0;
				}
			}
		}

		if (preg_match('~#([0-9]+)$~', $in, $m)) {
			// User picked a unit from the list
			$in = intval($m[1]);
			$state = $out[$in][0];

			// Desired morpheme came from the custom keyboard
			$penalty = 0;
			if ($out[$in][2] === -1) {
				if (!$on_keyb) {
					++$cost;
				}
				$penalty = $keys[$tokens[0]][1] - 1;
				$cost += $penalty;
				//echo "\tKB: {$tokens[0]}\n";
			}
			$on_keyb = true;

			// If we are starting on a new word, drop sliding window lookback
			/*
			if ($state[0] && preg_match('~^(TA|AA|")~', $out[$in][1])) {
				$final = $state[count($state)-1];
				foreach ($state as $k => $v) {
					if ($v === $final) {
						break;
					}
					$state[$k] = 0;
				}
			}
			//*/
			if (preg_match('~^"~', $out[$in][1])) {
				$state = array_merge(array_fill(0, $GLOBALS['ngrams']-2, 0), [$state[count($state)-1]]);
			}
			else if (preg_match('~^(N|V|Pali|Conj|Adv|Interj|Pron|Prop|Num|Symbol)(\+|$)~', $out[$in][1])) {
				// If we just finished a word, automatically switch to alphabetic keyboard at zero cost
				$on_keyb = false;
			}

			// Strip quotes and +nn, as those aren't actually relevant in the real world
			$clean = preg_replace('~(^")|("$)|(\+[vn][vn]$)~', '', $tokens[0]);
			$cost = min($cost, strlen($clean) + $penalty);
			$max_cost += strlen($clean);
			echo "{$clean} $cost\n";
			$total_cost += $cost;
			$cost = 1;
			array_shift($tokens);

			if ($GLOBALS['composition-preview']) {
				$sel_auto->execute($state);
				$auto = $sel_auto->fetchColumn(0);
			}

			$sel_sliding->execute($state);
			$rows = $sel_sliding->fetchAll();
			$in = '';
		}
		else {
			if ($GLOBALS['custom-keyboard'] && $on_keyb) {
				++$cost;
			}
			$on_keyb = false;

			// User typed a letter, so try to find units starting/continuing with that letter
			$sel_units->execute(["$in%"]);
			$units = $sel_units->fetchAll(PDO::FETCH_COLUMN, 0);

			// Exclude currently shown continuations
			$umax_not = '';
			if (!empty($out)) {
				$umax_not = "AND {$umax} NOT IN (".implode(', ', array_column(array_column($out, 0), 4)).")";
			}

			if (empty($units)) {
				$qs = $state;
				$rows = $db->prepexec("SELECT {$ucols}, cnt FROM sliding WHERE {$qcols} {$umax_not} AND cnt > {$GLOBALS['min-cnt']} {$skip_keys} ORDER BY cnt DESC LIMIT {$GLOBALS['suggestions']}", $qs)->fetchAll();
			}
			else {
				//echo "Found ".count($units)." partial units matching $in: ".implode(', ', $units)."\n";
				$qs = array_merge($state, $units);
				$rows = $db->prepexec("SELECT {$ucols}, cnt FROM sliding WHERE {$qcols} {$umax_not} AND {$umax} IN (?".str_repeat(', ?', count($units)-1).") AND cnt > {$GLOBALS['min-cnt']} {$skip_keys} ORDER BY cnt DESC LIMIT {$GLOBALS['suggestions']}", $qs)->fetchAll();

				for ($i=2 ; $i < $GLOBALS['ngrams'] && empty($rows) ; ++$i) {
					array_shift($qs);
					$us = [];
					for ($u=$i ; $u < $GLOBALS['ngrams'] ; ++$u) {
						$us[] = "u{$u} = ?";
					}
					$us = implode(' AND ', $us);
					$rows = $db->prepexec("SELECT {$ucols}, cnt FROM sliding WHERE {$us} {$umax_not} AND {$umax} IN (?".str_repeat(', ?', count($units)-1).") AND cnt > {$GLOBALS['min-cnt']} {$skip_keys} ORDER BY cnt DESC LIMIT {$GLOBALS['suggestions']}", $qs)->fetchAll();
				}
			}
		}
	}
}

echo "Max cost: $max_cost\n";
echo "Total cost: $total_cost\n";
