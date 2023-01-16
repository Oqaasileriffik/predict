#!/bin/bash
#set -eo pipefail

for F in $(ls -1 --color=no | grep ^fold)
do
	cd "$F"
	#cat ../query-4-comp-keyb.n6.$F.log | perl -we '$m=0;$t=0; while(<STDIN>){ m@^(.+?) (\d+)$@; $m += length($1); $t += $2; } print "$m\t$t\n";'
	cat ../query-3-keyb.n5.$F.log | head -n 90000 | egrep -v '^(N|V|Pali|Conj|Adv|Interj|Pron|Prop|Num|Symbol)([+]|$)' | perl -we '$m=0;$t=0; while(<STDIN>){ m@^(.+?) (\d+)$@; $m += length($1); $t += $2; } print "$m\t$t\n";'
	cd ..
done
