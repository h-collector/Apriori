#!/usr/php
<?php
if (!version_compare(PHP_VERSION, '5.3.0a', '>')) {
    print "Error this program needs to be run on PHP version >  5.3.x.\n";
    print "Installed version: " . phpversion() . "\n";
    exit(1);
}

require 'lib/Apriori.class.php';
require 'lib/StopWatch.class.php';

//error_reporting(E_ALL ^ (E_NOTICE | E_WARNING));
ini_set('memory_limit', '1536M');                   // memory usage is mostly low, but on saving can jump to high values
set_time_limit(0);                                  // on cli defualts to infinite, but just in case
date_default_timezone_set("Europe/Warsaw");         // php complains if there isn't any defualt timezone set

/**
 * Main function
 * @param array $argv
 * @param array $argc
 */
function main($argv, $argc) {
    if ($argc == 1) {
        echo "You need to specify some parameters. More info --help\n";
        return;
    }

    //evaluator types
    $maxLonglen = 0;
    $typeval    = array(
        'str'   => 'strval',
        'float' => 'floatval',
        'int'   => 'intval'
    );
    //declare options cases
    $cases   = array(
        array('s', 'minsupp   ', ':', "float","Minimal support (0..100>, default 5%"),
        array('c', 'minconf   ', ':', "float","Minimal confidence (0..100>, default 75%"),
        array('n', 'minitemset', ':', "int",  "Minimal size of item sets with strong rules"),
        array('x', 'maxitemset', ':', "int",  "Maximal size of frequent item set"),
        array('t', 'srctype   ', ':', "str",  "Type of data source [UNDEFINED,LOAD,DB,PLAIN,CSV].\n\t\t\t Default csv)"),
        array('d', 'srcdata   ', ':', "str",  "In regards to type, source of data,\n\t\t\t if plain then comma separated"),
        array('e', 'save      ', ':', "str",  "Save generated data to file"),
        array('l', 'load      ', ':', "str",  "Load generated data from file"),
        array('f', 'solve     ', '',  true,   "Generate frequent item sets"),
        array('g', 'genrules  ', '',  true,   "Generate association rules (after solve or load)"),
        array('i', 'disptrans ', '',  true,   "Display internal transactions databese"),
        array('a', 'disprules ', '',  true,   "Display genrated association rules"),
        array('r', 'recommend ', ':', "str",  "Display recommendations for given set (comma separated)"),
        array('w', 'timeit    ', '',  true,   "Display processing time of: solve, genrules, recommend"),
        array('v', 'verbose   ', '',  true,   "Display additional informations"),
        array('m', 'realmem   ', '',  true,   "Display real memory reserved by system for script\n\t\t\t (in verbose)"),
        array('p', 'pbar      ', '',  true,   "Display progressbar (verbose)"),
        array('o', 'resdir    ', ':', "str",  "Directory for saving results (disabled if empty).\n\t\t\t Default ./results"),
        array('q', 'noconsole ', '',  true,   "Do not display results in console (only save files)"),
        array('h', 'help      ', '',  true,   "List of available commandline parameters", function() use ($typeval, &$cases, &$maxLonglen) {
                echo 'Usage: php ' . basename(__FILE__) . " params\nParams:\n";
                foreach ($cases as $key => $case) {
                    if(!is_numeric($key))    
                        continue;
                    list($short, $long, $req, $type, $desc) = $case;
                    echo sprintf("%-2s %-{$maxLonglen}s %-7s %s\n"
                            , empty($short) ? '' : "-{$short}"
                            , empty($long)  ? '' : "--{$long}"
                            , isset($typeval[$type]) ? '<' . $type . '>' : ''
                            , $desc
                    );
                }
                exit(1);
            }),
    );
    //prepare options
    $shortOpts = '';
    foreach ($cases as &$case) {
        $case[1] = trim($case[1]);
        list($short, $long, $req) = $case;
        if(($len = strlen($long)) > $maxLonglen) 
            $maxLonglen = $len +2;
        $shortOpts  .= $short . $req;
        $longOpts[]  = $long  . $req;
        $cases[$short] = &$case;
        $cases[$long]  = &$case;
    }
    //parse options
    $args = array();
    foreach (getopt($shortOpts, $longOpts) as $opt => $val) {
        $val = is_array($val) ? end($val) : $val; 
        if (isset($cases[$opt])) {
            list($short, $long, $req, $type, $desc, $func) = $cases[$opt] + array(5 => null);
            $val         = isset($typeval[$type]) ? $typeval[$type]($val) : $type;
            $args[$long] = is_callable($func) ? $func($val) : $val;
        }
    }
    print_r($args);

    //default variables
    $minSupp = null;
    $minConf = null;

    $type = Apriori::SRC_CSV;
    if (isset($args['srctype'])) {
        if (defined("Apriori::SRC_{$args['srctype']}"))
            $type = constant("Apriori::SRC_{$args['srctype']}");
        else {
            $type = Apriori::SRC_UNDEFINED;
        }
    }
    $data = array(
        'file'  => 'data/transact.csv',
        'tid'   => 'transactId',
        'item'  => 'itemName',
        'delim' => "\t"
    );
    //for plain data in commandline
    if (isset($args['srcdata'])) {
        $data = $args['srcdata'];
        $data = str_replace('\t', "\t", $data);
        if (strpos($data, '|')) {
            $data = explode('|', $data);
        } else if (strpos($data, ',')) {
            $data = explode(',', $data);
            $tmp  = array();
            foreach ($data as $key => $value) {
                if (strpos($value, '=>')) {
                    list($k, $v) = explode('=>', $value, 2);
                    $tmp[$k] = $v;
                }
            }
            $data = $tmp;
        }
    }
    StopWatch::setPrintable(isset($args['timeit']));
    Apriori::setDebugInfo(isset($args['verbose']), isset($args['realmem']), isset($args['pbar']));
    Apriori::setResultTarget(isset($args['resdir']) ? $args['resdir'] : './results', !isset($args['noconsole']));

    $apri  = null;
    $watch = new StopWatch();
    try {
        if (isset($args['load'])) {
            $minSupp = isset($args['minsupp']) ? $args['minsupp'] : null;
            $minConf = isset($args['minconf']) ? $args['minconf'] : null;
            $apri    = new Apriori(Apriori::SRC_LOAD, $args['load']);
        } else {
            $minSupp = isset($args['minsupp']) ? $args['minsupp'] : 5;
            $minConf = isset($args['minconf']) ? $args['minconf'] : 75;
            $apri    = new Apriori($type, $data, $minSupp, $minConf);
        }
        $watch->printInterval('init');

        if (isset($args['minitemset']))
            $apri->setMinItemset($args['minitemset']);
        if (isset($args['maxitemset']))
            $apri->setMaxItemset($args['maxitemset']);

        if (isset($args['disptrans']))
            $apri->displayTransactions();
        if (isset($args['solve'])) {
            $watch->clock();
            $apri->solve($minSupp);
            $watch->printInterval('solve');
        }
        if (isset($args['genrules'])) {
            $watch->clock();
            $apri->generateRules($minConf);
            $watch->printInterval('genrules');
        }
        if (isset($args['save']))
            $apri->saveState($args['save']);
        if (isset($args['disprules']))
            $apri->displayRules();
        if (isset($args['recommend'])) {
            $watch->clock();
            $apri->displayRecommendations($args['recommend']);
            $watch->printInterval('recommend');
        }
    } catch (Exception $exc) {
        echo $exc->getMessage() . PHP_EOL;
    }
    $watch->printElapsed('program');

    unset($watch, $apri);
}

main($argv, $argc);
