#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__.'/vendor/autoload.php';
ini_set('memory_limit', '24G');

$wlen = 0;
$mlen = 0;

$fracs = [
	'min' => ['f' => 9, 'w' => '', 'm' => ''],
	'max' => ['f' => 0, 'w' => '', 'm' => ''],
	];

$word = '';
for ($i=0 ; $line = fgets(STDIN) ; ++$i) {
	if (preg_match('~^"<(.+)>"~', $line, $m)) {
		$word = $m[1];
	}
	else if (preg_match('~^\t"[a-z]~', $line) && preg_match('~^[a-z]~', $word) && strpos($line, 'N+Abs+Sg') !== false && strpos($line, 'Poss') === false) {
		$line = trim($line);
		// Skip tokens with embedded words
		if (preg_match('~ i?(N|V|Pali|Conj|Adv|Interj|Pron|Prop|Num|Symbol) ~', $line)) {
			continue;
		}
		// Skip half-transitive
		if (preg_match('~ HTR\+~', $line)) {
			continue;
		}
		do {
			$orig = $line;
			$line = preg_replace('~ i?(N|V|Pali|Conj|Adv|Interj|Pron|Prop|Num|Symbol)\+\S*~', ' ', $line);
			$line = preg_replace('~ i\S*~', ' ', $line);
			$line = preg_replace('~ (\d*Sg|\d*Pl|Abs|Trm|Lok)( |$)~', ' ', $line);
			$line = preg_replace('~\+[nv][nv]( |$)~', ' ', $line);
		} while ($line !== $orig);
		$line = preg_replace('~ %\S*~', ' ', $line);
		$line = str_replace(' CONJ-', ' ', $line);
		$line = str_replace(' ADV-', ' ', $line);
		$line = str_replace('"', '', $line);
		$line = trim($line);

		// Not fair to add up lengths of words that have no morphemes
		if (strpos($line, ' ') === false) {
			continue;
		}

		$line = preg_replace('~\s+~', '', $line);

		$wl = strlen($word);
		$ml = strlen($line);

		$frac = $ml / $wl;
		if ($frac > 1.5) {
			fprintf(STDERR, "Skip: %s %s        \n", $word, $line);
			continue;
		}
		if ($frac < $fracs['min']['f']) {
			$fracs['min']['f'] = $frac;
			$fracs['min']['w'] = $word;
			$fracs['min']['m'] = $line;
		}
		if ($frac > $fracs['max']['f']) {
			$fracs['max']['f'] = $frac;
			$fracs['max']['w'] = $word;
			$fracs['max']['m'] = $line;
		}
		$wlen += $wl;
		$mlen += $ml;
	}
	if ($wlen && $i % 100000 === 0) {
		$frac = $mlen / $wlen;
		echo "$wlen $mlen $frac        \r";
	}
}

$frac = $mlen / $wlen;
echo str_repeat(' ', 70)."\r";
echo "$wlen\t$mlen\t$frac        \n";
echo var_export($fracs, true)."\n";
