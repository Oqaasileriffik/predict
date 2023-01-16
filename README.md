Work on predictive text strategies for Kalaallisut (Greenlandic).

Load corpus: `rm -fv corpus.sqlite; zcat corp-kal/*.gz | time ./load-corpus.php`

Frequency list of tokens: `echo 'select p_body from pars;' | sqlite3 corpus.sqlite | grep '^"<' | perl -Mutf8 -wpne 's/^"<//; s/>"$//;' | sort | uniq -c | sort -nr | perl -wpne 's/^\s*(\d+)\s+(.+)$/$1\t$2/;' > freq-tokens.tsv`

Frequency list of morph units: `echo 'select p_body_norm from pars;' | sqlite3 corpus.sqlite | grep '^	' | perl -Mutf8 -wpne 's/^\s+//g; s/\s+$/\n/g; s/\s+/\n/g;' | sort | uniq -c | sort -nr | perl -wpne 's/^\s*(\d+)\s+(.+)$/$1\t$2/;' > freq-morphs.tsv`

Frequency list of token morpheme unit counts, with an example of each: `echo 'select p_body_norm from pars;' | sqlite3 corpus.sqlite | cg-sort -1 | grep '^	' | egrep '"[a-zA-Z]' | php -r '$m=array_fill(0, 20, 0); $x=$m; while($l=fgets(STDIN)) { $l = trim($l); $n = substr_count($l, " "); $m[$n] += 1; $x[$n] = $l; } foreach ($m as $k => $v) { echo "$k\t$v\t{$x[$k]}\n"; }' | tee freq-lengths.tsv`

Prediction rate for stem vs. non-stem: `cat query-3.n6.* | egrep '^[A-Z]' | egrep -v '^(N|V|Pali|Conj|Adv|Interj|Pron|Prop|Num|Symbol)([+]|$)' | perl -we '$m=0;$t=0; while(<STDIN>){ m@^(.+?) (\d+)$@; $m += length($1); $t += $2; } print "$m\t$t\n"; print "".(($m-$t)/$m)."\n";'`

ToDo:
* Compare completion of fullforms vs. morphemes vs. morpheme+keyboard; Skipped
* Compare trimmed (delete cnt=1) vs. untrimmed; NB: this shouldn't logically matter, as the FST can provide all with cnt=1; Skipped
* Compare showing 3, 4, 4+preview; all plus keyboard; Done
* Consider having separate verb and noun keyboards
* Does morpheme length correspond to word length? Yes, around 6.5% longer.
