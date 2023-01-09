Work on predictive text strategies for Kalaallisut (Greenlandic).

Load corpus: `rm -fv corpus.sqlite; zcat corp-kal/*.gz | time ./load-corpus.php`

Frequency list of tokens: `echo 'select p_body from pars;' | sqlite3 corpus.sqlite | grep '^"<' | perl -Mutf8 -wpne 's/^"<//; s/>"$//;' | sort | uniq -c | sort -nr | perl -wpne 's/^\s*(\d+)\s+(.+)$/$1\t$2/;' > freq-tokens.tsv`

Frequency list of morph units: `echo 'select p_body_norm from pars;' | sqlite3 corpus.sqlite | grep '^	' | perl -Mutf8 -wpne 's/^\s+//g; s/\s+$/\n/g; s/\s+/\n/g;' | sort | uniq -c | sort -nr | perl -wpne 's/^\s*(\d+)\s+(.+)$/$1\t$2/;' > freq-morphs.tsv`

Frequency list of token morpheme unit counts, with an example of each: `echo 'select p_body_norm from pars;' | sqlite3 corpus.sqlite | grep '^        ' | egrep '"[a-zA-Z]' | php -r '$m=array_fill(0, 20, 0); $x=$m; while($l=fgets(STDIN)) { $l = trim($l); $n = substr_count($l, " "); $m[$n] += 1; $x[$n] = $l; } foreach ($m as $k => $v) { echo "$k\t$v\t{$x[$k]}\n"; }' | tee freq-lengths.tsv`

ToDo:
* Compare completion of fullforms vs. morphemes vs. morpheme+keyboard
* Compare trimmed (delete cnt=1) vs. untrimmed
* Compare showing 3, 4, 4+preview; all plus keyboard
* Consider having separate verb and noun keyboards
