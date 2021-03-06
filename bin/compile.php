#!/usr/bin/env php
<?php

$opts = getopt('i:t:h', array('help', 'debug'));
$optlength = 0;
// count all flags, and count string values twice (then it wasn't just a flag, so it'll use up two argv entries
// must do recursively since args can be repeated
array_walk_recursive($opts, function($value) use(&$optlength) { if(is_scalar($value)) $optlength++; if(is_string($value)) $optlength++; });

if(isset($opts['h']) || isset($opts['help']) || ($_SERVER['argc']-$optlength) < 3) {
	echo "Usage: " . basename(__FILE__) . " [OPTION]... JOBDIR OUTPUTDIR\n";
	echo "\n";
	echo "Options:\n";
	echo "     --debug  : Build debug version of package (with internal counters etc).\n";
	echo "  -h/--help   : Display this help screen.\n";
	echo "  -i PATH     : PATH of directory to package with phar (can be repeated).\n";
	echo "  -t TIMEZONE : Name of the TIMEZONE to force in generated scripts.\n";
	echo "                If not given, the timezone of this machine is used.\n";
	echo "\n";
	exit(1);
}

if(!Phar::canWrite()) {
	echo "Phar write mode not allowed; disable phar.readonly in php.ini\n\n";
	exit(2);
}

$jobdir = realpath($_SERVER['argv'][$_SERVER['argc']-2]);
if($jobdir === false || !is_dir($jobdir) || !is_readable($jobdir)) {
	echo sprintf("Input directory '%s' not found or not readable.\n\n", $_SERVER['argv'][$_SERVER['argc']-2]);
	exit(1);
}
$jobname = basename($jobdir);
$builddir = realpath($_SERVER['argv'][$_SERVER['argc']-1]);
if($builddir === false || !is_dir($builddir) || !is_writable($builddir)) {
	echo sprintf("Output directory '%s' not found or not writable.\n\n", $_SERVER['argv'][$_SERVER['argc']-1]);
	exit(1);
}
$jobphar = "$builddir/$jobname.phar";
$jobsh = "$builddir/$jobname.sh";

if(isset($opts['t'])) {
	$tz = $opts['t'];
} else {
	$tz = date_default_timezone_get();
}
try {
	new DateTimeZone($tz);
} catch(Exception $e) {
	echo sprintf("Invalid timezone '%s'.\n\n", $tz);
	exit(1);
}

$phar = new Phar($jobphar, RecursiveDirectoryIterator::CURRENT_AS_FILEINFO | RecursiveDirectoryIterator::KEY_AS_FILENAME, 'my.phar');
$phar->startBuffering();
$stub = $phar->createDefaultStub('Hadoophp/_run.php', false);
// inject timezone and add shebang (substr cuts off the leading "<?php" bit)
$stub = "#!/usr/bin/env php\n<?php\ndate_default_timezone_set('$tz');\ndefine('HADOOPHP_DEBUG', " . var_export(isset($opts['debug']), true) . ");\n" . substr($stub, 5);
$phar->setStub($stub);
// for envs without phar, this will work and not create a checksum error, but invocation needs to be "php archive.phar then":
// $phar->setStub($phar->createDefaultStub('Hadoophp/_run.php', 'Hadoophp/_run.php'));

$buildDirectories = array(
	realpath(__DIR__ . '/../lib/'),
	realpath($jobdir),
);
if(isset($opts['i'])) {
	$buildDirectories = array_merge($buildDirectories, (array)$opts['i']);
}
$filterRegex = '#^((/|^)(?!\\.)[^/]*)+$#';
foreach($buildDirectories as $path) {
	$phar->buildFromDirectory(realpath($path), $filterRegex);
}

$phar->stopBuffering();

if(file_exists("$jobdir/ARGUMENTS")) {
	$mapRedArgs = trim(file_get_contents("$jobdir/ARGUMENTS"));
} else {
	$mapRedArgs = array();
	// must do reducer first because of the fallback... -D args must be listed first or hadoop-streaming.jar complains
	if(file_exists("$jobdir/Reducer.php")) {
		$mapRedArgs[] = "-reducer 'php -d detect_unicode=off $jobname.phar reducer'";
	} else {
		$mapRedArgs[] = "-D mapred.reduce.tasks=0";
	}
	if(file_exists("$jobdir/Combiner.php")) {
		$mapRedArgs[] = "-combiner 'php -d detect_unicode=off $jobname.phar combiner'";
	}
	$mapRedArgs[] = "-mapper 'php -d detect_unicode=off $jobname.phar mapper'";
	$mapRedArgs = implode(" \\\n", $mapRedArgs) . " \\";
}

file_put_contents($jobsh, sprintf('#!/bin/sh
confswitch=""
streaming=""
while getopts ":c:s:" opt; do
	case $opt in
		c) confswitch="--config $OPTARG";;
		s) streaming="$OPTARG";;
		\?) echo "Invalid option: -$OPTARG"; exit 1;;
		:) echo "Option -$OPTARG requires an argument."; exit 1;;
	esac
done
shift $((OPTIND-1))

if [ $# -lt 2 ]
then
	echo "Usage: $0 [OPTION...] HDFSINPUTPATH... HDFSOUTPUTPATH"
	echo ""
	echo "HDFSINPUTPATH can be repeated to use multiple paths as input for the job."
	echo ""
	echo "Options:"
	echo " -c HADOOPCONFDIR  Gets passed to hadoop via "--config" (see hadoop help)."
	echo " -s STREAMINGJAR   Path to hadoop-streaming-*.jar"
	echo ""
	exit 1
fi

input=""
output=""
index=0
for path in $*
do
	index=`expr $index + 1`
	if [ $index -ne $# ]
	then
		input=$input" -input $path"
	else
		output="-output $path"
	fi
done

if [ $HADOOP_HOME ]
then
	hadoop=$HADOOP_HOME/bin/hadoop
	if [ -z $streaming ]
	then
		streaming=$HADOOP_HOME"/contrib/streaming/hadoop-streaming-*.jar"
	fi
else
	hadoop="hadoop"
	if [ -z $streaming ]
	then
		streaming="/usr/lib/hadoop/contrib/streaming/hadoop-streaming-*.jar"
	fi
fi
dir=`dirname $0`

$hadoop $confswitch jar $streaming \\
%s
$input \\
$output \\
-file $dir/%s.phar
', $mapRedArgs, $jobname));

echo "
Build done, generated files:
  $jobsh
  $jobphar

If you re-built the job, make sure to check the modifications in $jobname.sh

Do not forget to chmod
  $jobname.sh
and
  $jobname.phar
to be executable before checking in.

";

?>