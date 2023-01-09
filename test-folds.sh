#!/bin/bash
set -eo pipefail

for F in $(ls -1 --color=no | grep ^fold)
do
	pushd "$F"
	echo "Testing $F"
	echo "attach '../corpus.sqlite' as corp; select p_body_norm from corp.pars where p_id IN (select p_id from pars where p_test = 1) order by substr(p_id, -1) desc, p_id asc;" | sqlite3 ngrams.sqlite | grep '^	' | time nice -n20 ../query-tokens.php >query-3-keyb.log 2>&1 &
	popd
done

for job in `jobs -p`
do
	wait $job
done
