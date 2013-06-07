@echo off
setlocal
set command_name=php cmd_apriori.php
set options=( ^
	--solve ^
	--genrules ^
	--disprules ^
	--timeit ^
	--verbose
	rem --noconsole
  rem --pbar
	rem --disptrans
	rem --realmem
	rem --help
)

set parameter=( ^
	--minsupp=5.0 ^
	--minconf=60.0 ^
	--srctype=CSV ^
	--srcdata="file=>data/msdn.csv,tid=>transactId,item=>softwareName,enclosure=>'" ^
	--recommend="Virtual PC 2007"
  rem --resdir="./results"
	rem --charset=utf-8
	rem --minitemset=2
	rem --maxitemset=3
	rem --save=msdn_5_60.gz
	rem --load=msdn_5_60.gz
)

%command_name% %options% %parameter%

pause
