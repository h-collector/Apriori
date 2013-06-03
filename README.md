Apriori
=====================
Simple Recommendation Engine using apriori method

Example input
---------------------
<pre lang="php"><code>
$minSupp  = 5;                  //minimal support
$minConf  = 75;                 //minimal confidence
$type     = Apriori::SRC_PLAIN; //data type
$recomFor = 'beer';             //recommendation for
$dataFile = 'data.json.gz';     //file for saving of state 
//transactions
$data = array(
    'bread, milk',
    'sugar, milk, beer',
    'bread',
    'bread, milk, beer',
    'sugar, milk, beer'
); //id(items)  
</code></pre>

Example code
---------------------
<pre lang="php"><code>
try {
    $apri = new Apriori($type, $data, $minSupp, $minConf);
    $apri->displayTransactions()
         ->solve()
         ->generateRules()
         ->displayRules()
         ->displayRecommendations($recomFor)
         ->saveState($dataFile);                 //save state with rules
} catch (Exception $exc) {
    echo $exc->getMessage();
}
</code></pre>

Example output
---------------------
<pre>
--------------------------------------------------------------------------------
 Tr_id  Items
--------------------------------------------------------------------------------
     0  bread,milk
     1  sugar,milk,beer
     2  bread
     3  bread,milk,beer
     4  sugar,milk,beer
--------------------------------------------------------------------------------
--------------------------------------------------------------------------------
   No.                     Set   Support                        Rule Confidence
--------------------------------------------------------------------------------
     0               beer,milk    60.00%                  beer=>milk   100.00%
     1               beer,milk    60.00%                  milk=>beer    75.00%
     2              beer,sugar    40.00%                 sugar=>beer   100.00%
     3              milk,sugar    40.00%                 sugar=>milk   100.00%
     4         beer,milk,sugar    40.00%            sugar=>beer,milk   100.00%
     5         beer,bread,milk    20.00%            beer,bread=>milk   100.00%
     6         beer,milk,sugar    40.00%            milk,sugar=>beer   100.00%
     7         beer,milk,sugar    40.00%            beer,sugar=>milk   100.00%
--------------------------------------------------------------------------------
--------------------------------------------------------------------------------
   No.   Support  Confidence  Recommendation (5.00%/75.00%) for: beer
--------------------------------------------------------------------------------
     0    60.00%     100.00%  milk
--------------------------------------------------------------------------------
</pre>