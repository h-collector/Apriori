<?php
require '../lib/Apriori.class.php';

//variables
$minSupp  = 5;                  //minimal support
$minConf  = 75;                 //minimal confidence
$type     = Apriori::SRC_PLAIN; //data type
$recomFor = 'beer';             //recommendation for
$dataFile = 'data.json.gz';     //file for saving of state 

//some example of data source
$data = array();
switch ($type) {
    case Apriori::SRC_PLAIN:
        //transactions
        $data = array(
            'bread, milk',
            'sugar, milk, beer',
            'bread',
            'bread, milk, beer',
            'sugar, milk, beer'
        ); //id(items)  
        //$data = 'plain.txt';
        break;
    case Apriori::SRC_DB:
        //database
        $data = array(
            100 => array(1, 'A'),
            101 => array(1, 'C'),
            102 => array(1, 'D'),
            200 => array(2, 'B'),
            201 => array(2, 'C'),
            202 => array(2, 'E'),
            300 => array(3, 'A'),
            301 => array(3, 'B'),
            302 => array(3, 'C'),
            303 => array(3, 'E'),
            400 => array(4, 'B'),
            401 => array(4, 'E')
        ); //id(user,item)     
        break;
    case Apriori::SRC_CSV:
        $data = array(
            'file' => '../data/transact.csv',
            'tid' => 'transactId',
            'item' => 'itemName',
            'delim' => "\t"
        );
        break;
}

try {
    $apri = new Apriori($type, $data, $minSupp, $minConf);
    $apri->displayTransactions()
            ->solve()
            ->saveState($dataFile);                 //saving of state without rules
    
    unset($apri);
    
    $b = new Apriori(Apriori::SRC_LOAD, $dataFile); //laod state and generate rules
    $b->generateRules()
            ->displayRules()
            ->displayRecommendations($recomFor)
            ->saveState($dataFile);                 //save state with rules
    
    unset($b);
    
    file_put_contents('unpacked.txt',print_r(Apriori::loadAndPrintStateFile($dataFile,false),true));
} catch (Exception $exc) {
    echo $exc->getMessage();
}