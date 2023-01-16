#!/bin/bash
set -eo pipefail

for F in $(ls -1 --color=no | grep ^fold)
do
	pushd "$F"
	echo "Pruning $F"
	cp -av ngrams.sqlite full.sqlite
	echo 'begin; delete from sliding where cnt <= 1; commit; vacuum;' | sqlite3 ngrams.sqlite
	popd
done
