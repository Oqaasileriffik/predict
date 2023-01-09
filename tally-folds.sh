#!/bin/bash
set -eo pipefail

for F in $(ls -1 --color=no | grep ^fold)
do
	cd "$F"
	#cat query-3-keyb.log | perl -we '$m=0;$t=0; while(<STDIN>){ m@^(.+?) (\d+)$@; $m += length($1); $t += $2; } print "$m\t$t\n";'
	cat query-3-keyb.log | egrep -v '^(N|V|Pali|Conj|Adv|Interj|Pron|Prop|Num|Symbol)([+]|$)' | perl -we '$m=0;$t=0; while(<STDIN>){ m@^(.+?) (\d+)$@; $m += length($1); $t += $2; } print "$m\t$t\n";'
	cd ..
done

for job in `jobs -p`
do
	wait $job
done
