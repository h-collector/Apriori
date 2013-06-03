<?php

/**
 * Class to generate association rules
 * from dataset using apriori method
 * and simple recommendation engine
 *
 * @author  h-collector <githcoll@gmail.com>
 *          
 * @link    http://hcoll.onuse.pl/view/HCImage
 * @license GNU LGPL (http://www.gnu.org/copyleft/lesser.html)
 * @version 0.9.2
 */
class Apriori {

    private $minSupp     = .50;     ///< Minimal support
    private $minConf     = .80;     ///< minimal confidence
    private $trans       = array(); ///< Transaction array
    private $L           = array(); ///< Frequent itemsets
    private $C           = array(); ///< Candidate itemsets
    private $rules       = array(); ///< Rules
    private $transCount  = 0;       ///< Transaction count
    private $LLevels     = 0;       ///< Size of biggest set
    private $minItemsets = 1;       ///< Minimal size of set (for solve())
    private $maxItemsets = -1;      ///< Maximal size of set (for generateRules())

    const SRC_UNDEFINED = -1;       ///< Udefined datasource (nie inicjalizuje bazy transakcji)
    const SRC_LOAD      = 0;        ///< Load data from saved file
    const SRC_DB        = 1;        ///< Data from array array(id=>array(tid,item),..id=>array(tid,item))
    const SRC_PLAIN     = 2;        ///< Data from plain structure (file/array), transactions in rows, items separated by comma
    const SRC_CSV       = 3;        ///< Data from .csv file. Parametr array(file=>?,tid=>?,item=>?,delim=>?,enclosure=>?)
//    const SRC_ARFF = 4;         ///< Data from .arff file
    const ITEM_SEP      = ',';       ///< Separator for generated items in set
    const SET_IMPL      = '=>';      ///< Implication symbol for rules
    const HORIZ_SEP_LEN = 80;       ///< Length of vertical separator (in console/results)
    const NO_REDUNDANCY = true;     ///< If there should be only unique items in single transaction

    private static $REAL_MEMORY_USAGE    = false;  ///< Shows memory used in PHP script (false) or reserved by system(true) in debug
    private static $DEBUG_INFO           = false;         ///< Shows additional info about progress in aprioriGen and solve
    private static $PRETTY_PROGRESS      = false;         ///< Display progressbar (in console)
    private static $PROGRESS_BAR         = null;
    private static $PROGRESS_BAR_FORMAT  = ' * %fraction% [%bar%] %percent%, %elapsed%'; ///< Format of progressbar
    private static $PROGRESS_BAR_OPTIONS = array();       //array('ansi_terminal' => true);
    private static $TARGET_DIR           = './results';   ///< Results output directory (display*() )
    private static $DISPLAY              = true;          ///< Tru if results should be also displayed

    /**
     * Constructor of Apriori class
     * 
     * @param const $type [Optional] Type of input data
     * @param mixed $dataSrc [Optional] Data source(array or filepath)
     * @param float $minSupp [Optional] Minimal support
     * @param float $minConf [Optional] Minimal confidence
     * @see Apriori::SRC_DB
     * @see Apriori::SRC_LOAD
     * @see Apriori::SRC_PLAIN
     * @see Apriori::SRC_CSV
     * @see setTransactions()
     * @see setTransactionsFromDB()
     * @see setTransactionsFromCSV()
     */

    public function __construct($type = self::SRC_PLAIN, &$dataSrc = null, $minSupp = 50, $minConf = 80) {
        switch ($type) {
            case self::SRC_DB:
                $this->setTransactionsFromDB($dataSrc);
                break;
            case self::SRC_PLAIN:
                $this->setTransactions($dataSrc);
                break;
            case self::SRC_CSV:
                $this->setTransactionsFromCSV($dataSrc);
                break;
            case self::SRC_LOAD:
                $this->loadState($dataSrc);
                break;
            default: throw new OutOfBoundsException('Unknown input data type ' . $type);
                break;
        }
        if ($type !== self::SRC_LOAD) {
            $this->transCount = count($this->trans);
            $this->setMinSupport($minSupp);
            $this->setMinConfidence($minConf);
        }
    }

    public function __destruct() {
        unset($this->trans, $this->L, $this->C, $this->rules);
        self::debugInfo('D: Maximal use of RAM in session %s' . PHP_EOL, self::sizeHumRead(memory_get_peak_usage(self::$REAL_MEMORY_USAGE)));
    }

    /**
     * Merge 2 or more arrays by adding values in elements with the same keys
     * @param array $a1 Input array 1
     * @param array $a2 Input array 2
     * @param array ... [optional] variable number of additional arrays
     * @return array <p>Array which contain elements from all input arrays with 
     * summated values in corresponding keys</p>
     */
    static public function arrayAdd(array $a1, array $a2) {
        $aRes = $a1;
        foreach (array_slice(func_get_args(), 1) as $aRay) {
            foreach (array_intersect_key($aRay, $aRes) as $key => $val)
                $aRes[$key] += $val;
            $aRes += $aRay;
        }
        return $aRes;
    }

    /**
     * Shortened form of explode() function.
     * @param string $string String to tokenize
     * @return array Array of tokens, where separtor was defined globally
     * @see explode()
     */
    static public function _explode($string) {
        return explode(self::ITEM_SEP, $string);
    }

    /**
     * Shortened form of join() function.
     * @param array $array Array tokens to glue
     * @return string Glued string, where separtor was defined globally
     * @see implode(), join()
     */
    static public function _join(array &$array) {
        return join(self::ITEM_SEP, $array);
    }

    /**
     * Return elements of $small array, which are also in $big array (are subsets)
     * @param array $small
     * @param array $big
     * @return array
     */
    static public function &subset(array &$small, array &$big) {
        $ret = array();
        foreach ($small as $set => &$val) {
            $items     = self::_explode($set);
            if (array_intersect($items, $big) === $items)
                $ret[$set] = $val; //$small[$set];
        }
        return $ret;
    }

    /**
     * Return formated key for internal assiociation table
     * @param mixed $set Set of elements in form of array or comma separated string
     * @return string Key - sorted and glued
     */
    static public function toValidKey($set) {
        if (empty($set))
            throw new Exception('Set should not be empty');

        if (!is_array($set)) {
            $set = explode(',', $set);
        }
        $set = array_map('trim', $set);
        natcasesort($set);
        return self::_join($set);
    }

    /**
     * <p>Fill te internal transaction array from from plain array.</p>
     * <p>Plain array is array specified as follows: 
     * array(tid1=>set1,tid2=>array(set2),set3,array(set4)...)</p>
     * @param mixed $plainData array of transactions
     * @see __construct()
     * @see setTransactions()
     * @see setTransactionsFromCSV()
     */
    private function setTransactionsFromDB(array $db) {
        //prepare internal transaction base and 1-element candidating sets
        foreach ($db as $id => $t) {
            if (!is_array($t))
                throw new RuntimeException('Bad data structure');

            list($tid, $item) = $t;
            $item = trim($item);

            if (self::NO_REDUNDANCY && isset($this->trans[$tid]) && (false !== array_search($item, $this->trans[$tid])))
                continue;

            $this->trans[$tid][] = $item;
            if (!isset($this->C[1][$item]))
                $this->C[1][$item]   = 0;
            $this->C[1][$item]++;
        }//tid(items)
    }

    /**
     * <p>Fill te internal transaction array from from plain array or data file.</p>
     * <p>In transaction file items are separated by specified before static separator
     *  (by default comma), one transaction per line.</p>
     * <p>Plain array is array specified as follows: 
     * array(tid1=>set1,tid2=>array(set2),set3,array(set4)...)</p>
     * @param mixed $plainData array of transactions or filepath
     * @see __construct()
     * @see setTransactionsFromDB()
     * @see setTransactionsFromCSV()
     * @see Apriori::ITEM_SEP
     */
    private function setTransactions($plainData) {
        $trans = array();
        if (!is_array($plainData)) {
            if (!is_file($plainData))
                throw new RuntimeException('Data file not found');
            $trans = file($plainData, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        }
        else
            $trans = $plainData;

        //fill array of 1-element candidating sets
        $this->C[1] = array();
        foreach ($trans as $tid => $set) {
            $items = is_array($set) ? $set : self::_explode($set);
            $items = array_map('trim', $items);
            $items = array_filter($items, function(&$item) {
                        return (!empty($item));
                    });
            if (self::NO_REDUNDANCY)
                $items             = array_unique($items);
            $this->C[1]        = self::arrayAdd($this->C[1], array_count_values($items));
            $this->trans[$tid] = $items;
        }
    }

    /**
     * Fill te internal transaction array from CSV file.
     * <pre>
     * Array of parameters in $csv:
     *     Key       | Meaning
     *     --------------------------------------------------------------------
     *     file      | Path to .csv file
     *     tid       | Name of column with id of transaction
     *     item      | Name of column with items of transaction
     *     delim     | Optional. 1 character column separator. By default ",". tabulator is "\t"
     *     enclosure | Optional. 1 character column encloser. By default "'"
     * </pre>
     * @param array $csv assiociation array of parameters describing CSV file
     * @see __construct()
     * @see setTransactionsFromDB()
     * @see setTransactions()
     */
    private function setTransactionsFromCSV(array &$csv) {
        if (empty($csv['file']) || empty($csv['tid']) || empty($csv['item']))
            throw new InvalidArgumentException('Not enough info about data file (file, tid, item[, delim[, enclosure]]): ' . join(', ', $csv));

        $delim      = isset($csv['delim']) ? $csv['delim'] : ',';
        $enclosure  = isset($csv['enclosure']) ? $csv['enclosure'] : '\''; // as null?
        $tidColumn  = 0;
        $itemColumn = 0;
        $firstLine  = true;
        if (($handle     = @fopen($csv['file'], "r")) !== false) {
            while (($data = fgetcsv($handle, 0, $delim, $enclosure)) !== false) {
                if ($firstLine) {
                    $data       = array_map('trim', $data);
                    $firstLine  = false;
                    $tidColumn  = array_search($csv['tid'], $data);
                    $itemColumn = array_search($csv['item'], $data);
                    if ($tidColumn === false || $itemColumn === false)
                        throw new RuntimeException('No all needed fields were found in file (tid, item): ' . $csv['tid'] . ', ' . $csv['item']);
                } else {
                    $tid  = $data[$tidColumn];
                    $item = $data[$itemColumn];

                    if (self::NO_REDUNDANCY && isset($this->trans[$tid]) && (false !== array_search($item, $this->trans[$tid])))
                        continue;

                    $this->trans[$tid][] = $item;
                    if (!isset($this->C[1][$item]))
                        $this->C[1][$item]   = 0;
                    $this->C[1][$item]++;
                }
            }
            fclose($handle);
        }
        else
            throw new RuntimeException('Could not open data file: ' . $csv['file']);
    }

    /**
     * Return internal transaction table.
     * @return array Array of transactions
     */
    public function getTransactions() {
        return $this->trans;
    }

    /**
     * Display internal transaction table.
     * @return Apriori $this
     */
    public function displayTransactions() {
        $file = 'transactions.txt';
        $str  = '';
        $str .= str_repeat('-', self::HORIZ_SEP_LEN) . PHP_EOL;
        $str .= sprintf(' %5s  %s' . PHP_EOL, 'Tr_id', 'Items');
        $str .= str_repeat('-', self::HORIZ_SEP_LEN) . PHP_EOL;
        self::saveDisplay($file, $str);

        foreach ($this->trans as $tid => $t) {
            $str = sprintf(' %5d  %s' . PHP_EOL, $tid, self::_join($t));
            self::saveDisplay($file, $str, true);
        }
        $str = str_repeat('-', self::HORIZ_SEP_LEN) . PHP_EOL;
        self::saveDisplay($file, $str, true);

        return $this;
    }

    /**
     * Generate candidating sets from frequent itemsets.
     * @param array $Lk frequent itemsets of size k
     * @return array array of k+1 elements candidating sets 
     */
    private function &aprioriGen(array &$Lk) {
        $Ck      = array();
        $keys    = array_keys($Lk);
        $keys    = array_map('self::_explode', $keys);
        $LkCount = count($keys);
        $length  = ($LkCount != 0) ? count($keys[0]) : 0;

        self::debugInfo('AG: generation, level: %d, frequent itemsets %d ' . PHP_EOL, $length, $LkCount);

        //krok 1 - generacja (join)
        for ($i = 0; $i < $LkCount - 1; $i++) {
            $Lk1 = $keys[$i];
            for ($j = $i + 1; $j < $LkCount; $j++) {
                $Lk2 = $keys[$j];
                $C   = array();
                if ($length === 1) {                         //count($Lk1) 
                    $C = array_merge($Lk1, $Lk2);
                } else {
                    $l1 = array_slice($Lk1, 0, -1);         //to $length-1,-> k-1
                    $l2 = array_slice($Lk2, 0, -1);
                    if ($l1 === $l2) {//&& $Lk1[$length - 1] < $Lk2[$length - 1] //not needed
                        $C = array_merge($Lk1, (array) $Lk2[$length - 1]);
                    }//else empty
                }
                if (!empty($C)) {
                    natcasesort($C);
                    $Ck[self::_join($C)] = 0;
                }
            }
        }

        self::debugInfo('AG: prune, in: candidates: %d' . PHP_EOL, count($Ck));

        //step 2 - prune
        foreach ($Ck as $c => $dummy) {                     // itemsets c ∈ Ck
            $set = self::_explode($c);                      // (k)-set of c
            foreach ($set as $s) {                          // item s 
                $subset = array_diff($set, (array) $s);     // (k-1)-subset of c (set-item)
                $subset = self::_join($subset);
                if (!isset($this->L[$length][$subset])) {   // s  ∉ Lk-1
                    unset($Ck[$c]);                         // delete c from Ck;
                    continue;
                }
            }
        }

        self::debugInfo('AG: prune, out: candidates: %d' . PHP_EOL, count($Ck));

        return $Ck;
    }

    /**
     * Generate frequent itemsets;
     * @param float $minSupp [optional] Minimal support, will be set for class object
     * @return Apriori $this
     */
    public function solve($minSupp = null) {
        if ($minSupp !== null)
            $this->setMinSupport($minSupp);
        $minSupp = $this->minSupp;

        //we will reset array of candidating sets
        $this->L = array();

        //1-element frequent itemsets
        foreach ($this->C[1] as $item => $count) {
            if ($count >= $minSupp)
                $this->L[1][$item] = $count;
        }
        if (empty($this->L))
            throw new RuntimeException('There are not any 1-element frequent itemsets. To high minimal support?');
        ksort($this->L[1]);

        //searching for all frequent itemsets
        for ($k = 2; isset($this->L[$k - 1]); $k++) {

            if ($this->maxItemsets > 0 && $k > $this->maxItemsets)
                break; //upper bound of size of generated sets

            $this->C[$k] = $this->aprioriGen($this->L[$k - 1]);
            if (empty($this->C[$k]))  // no need for searching base if no candidating sets
                continue;

            self::debugInfo('Solve: Searching for candidating sets in base, level %d' . PHP_EOL, $k);

            foreach ($this->trans as &$t) {
                $Ct = self::subset($this->C[$k], $t);
                foreach ($Ct as $c => $count)
                    $this->C[$k][$c]++;
                self::debugProgressBar($this->transCount);
            }
            $this->L[$k] = array_filter($this->C[$k], // Lk = {c ∈ Ck | c.count ≥ minsup}
                    function(&$c) use ($minSupp) {
                        return ($c >= $minSupp);
                    });

            self::debugInfo('Solve: new frequent itemsets of level %d' . PHP_EOL, $k);
        }

        $this->LLevels = count($this->L);
        //free memory, C[1] is left in case of redo (solve)
        $this->C       = array_slice($this->C, 0, 1, true);
        return $this;
    }

    /**
     * Return frequent itemsets.
     * @return array Aassociation array of frequent itemsets with number of occurence
     */
    public function getFrequentItems() {
        return $this->L;
    }

    /**
     * Add new association rule to internal array of rules.
     * @param array $left Left side of implication.
     * @param array $right Right side od implication.
     * @param string $ruleStr <p>Frequent itemset, whose subset are both sides 
     * of association implication</p>
     * @see generateRules()
     */
    private function addRule(array $left, array $right, $ruleStr) {
        $lLen = count($left);
        $rLen = count($right);
        $lt   = self::_join($left);
        $rt   = self::_join($right);

//        $rule['set'] = $ruleStr;//needs more RAM
        $rule['supp'] = $this->L[$lLen + $rLen][$ruleStr];
        $rule['conf'] = $rule['supp'] / $this->L[$lLen][$lt];
        if ($rule['conf'] >= $this->minConf) {
            $rule['supp'] *= 100 / $this->transCount;
            $rule['conf'] *= 100;
//        $rule['X'] = $lt; // searching by key is faster
            $rule['Y']          = $rt;
            $this->rules[$lt][] = $rule;
        }
    }

    /**
     * Generation of association rules.
     * @param float $minConfidence [Optional] Minimal confidence for association rules
     * @return Apriori $this
     */
    public function generateRules($minConfidence = null) {
        if ($minConfidence !== null)
            $this->setMinConfidence($minConfidence);

        $this->rules = array();
        for ($k = 2; $k <= $this->LLevels; $k++) {
            if ($k < $this->minItemsets)
                continue; //lower bound of size of generated sets
            self::debugInfo('GR: genaration of association rules, level %d' . PHP_EOL, $k);

            foreach ($this->L[$k] as $set => $count) {
                $items = self::_explode($set);
                if ($k === 2) {
                    $left  = (array) $items[0];
                    $right = (array) $items[1];
                    $this->addRule($left, $right, $set);
                    $this->addRule($right, $left, $set);
                } else {
                    for ($i = 0; $i < $k - 2; $i++) {
                        for ($j = 0; $j < $k - $i; $j++) {
                            $left   = (array) array_slice($items, 0, $i);
                            $left[] = $items[$i + $j];
                            $right  = array_diff($items, $left);
                            $this->addRule($left, $right, $set);
                            $this->addRule($right, $left, $set);
                        }
                    }
                }
            }
        }
        self::debugInfo('GR: association rules: %d' . PHP_EOL, array_sum(array_map('count', $this->rules)));
        return $this;
    }

    /**
     * Return generated association rules.
     * @return array Association array of generated rules
     */
    public function getRules() {
        return $this->rules;
    }

    /**
     * Display generated association rules as table.
     * @param string $saveToDir Rules will be saved to this file
     * @return Apriori $this
     */
    public function displayRules() {
        $file = 'rules.txt';
        $str  = '';
        $str .= str_repeat('-', self::HORIZ_SEP_LEN) . PHP_EOL;
        $str .= sprintf(' %5s %23s %9s %27s %9s' . PHP_EOL, 'No.', 'Set', 'Support', 'Rule', 'Confidence');
        $str .= str_repeat('-', self::HORIZ_SEP_LEN) . PHP_EOL;
        self::saveDisplay($file, $str);

        $num = 0;
        foreach ($this->rules as $X => &$rules) {
            $str = '';
            foreach ($rules as $r) {
                $r['rule'] = $X . self::SET_IMPL . $r['Y'];
                /* lowing memory usage by gluing rules here*/
                $r['set']  = $X . self::ITEM_SEP . $r['Y'];
                $r['set']  = self::_explode($r['set']);
                natcasesort($r['set']);
                $r['set']  = self::_join($r['set']);

                $str .= sprintf(' %5d %23s %8.2f%% %27s %8.2f%%' . PHP_EOL, $num++, $r['set'], $r['supp'], $r['rule'], $r['conf']);
            }
            self::saveDisplay($file, $str, true);
        }
        $str = str_repeat('-', self::HORIZ_SEP_LEN) . PHP_EOL;
        self::saveDisplay($file, $str, true);

        return $this;
    }

    /**
     * Return recommendations for given set.
     * @param string $set Set for which recommendations should be given.
     * @return array Recommendations
     */
    public function getRecommendations($set) {
        $set = self::toValidKey($set);
        return isset($this->rules[$set]) ? $this->rules[$set] : array();
    }

    /**
     * Display recommendations for given set as a table.
     * @param float $set Set for which recommendations should be given.
     * @return Apriori $this
     */
    public function displayRecommendations($set) {
        $set = self::toValidKey($set);

        $file = 'recommendations.txt';
        $str  = '';

        $str .= str_repeat('-', self::HORIZ_SEP_LEN) . PHP_EOL;
        $str .= sprintf(' %5s %9s %11s  Recommendation (%.2f%%/%.2f%%) for: %s' . PHP_EOL, 'No.', 'Support', 'Confidence', $this->getMinSupport(), $this->getMinConfidence(), $set);
        $str .= str_repeat('-', self::HORIZ_SEP_LEN) . PHP_EOL;

        if (isset($this->rules[$set]))
            foreach ($this->rules[$set] as $num => $rule) {
                $str .= sprintf(' %5d %8.2f%% %10.2f%%  %s' . PHP_EOL, $num, $rule['supp'], $rule['conf'], $rule['Y']);
            }

        $str .= str_repeat('-', self::HORIZ_SEP_LEN) . PHP_EOL;
        self::saveDisplay($file, $str);
        return $this;
    }

    /**
     * Return minimal support in percents.
     * @return float Minimal support
     */
    public function getMinSupport() {
        return $this->minSupp * 100 / $this->transCount;
    }

    /**
     * Return minimal confidence in percents.
     * @return float Minimal confidence
     */
    public function getMinConfidence() {
        return $this->minConf * 100.0;
    }

    /**
     * Set minimal support level.
     * @param float $minSupp Minimal support in range (0,100>%.
     * @return Apriori $this
     */
    public function setMinSupport($minSupp) {
        if ($minSupp <= 0 || $minSupp > 100)
            throw new OutOfRangeException('Support must be greater than 0 and no greater than 100%');
        $this->minSupp = $minSupp * $this->transCount / 100.0; // percent -> occurence number in transactions
        return $this;
    }

    /**
     * Set minimal confidence level.
     * @param float $minConf Minimal confidence in range (0,100>%.
     * @return Apriori $this
     */
    public function setMinConfidence($minConf) {
        if ($minConf <= 0 || $minConf > 100)
            throw new OutOfRangeException('Confidence must be greater than 0 and no greater than 100%');
        $this->minConf = $minConf / 100.0;
        return $this;
    }

    /**
     * Return minimal size of frequent itemset.
     * From this length association rules will be generated. 
     * @return int Minimal size of set with strong rules.
     * @see generateRules()
     */
    public function getMinItemset() {
        return $this->minItemsets;
    }

    /**
     * Return maximal size of frequent itemset.
     * To this level sets will be generated.
     * @return int Maximal size of frequent itemset.
     * @see solve()
     */
    public function getMaxItemset() {
        return $this->maxItemsets;
    }

    /**
     * Set minimal size of set with strong rules (support and confidence satisfied).
     * @param int $min Minimal size of Minimal size of set.
     * @return Apriori $this
     * @see getMinItemset()
     */
    public function setMinItemset($min) {
        if (!is_numeric($min))
            throw new InvalidArgumentException('Given value must be a number');
        $this->minItemsets = $min;
        return $this;
    }

    /**
     * Set maximal size of frequent itemset.
     * @param int $max Maximal size of set
     * @return Apriori $this
     * @see getMaxItemset()
     */
    public function setMaxItemset($max) {
        if (!is_numeric($max))
            throw new InvalidArgumentException('Given value must be a number');
        $this->maxItemsets = $max;
        return $this;
    }

    /**
     * Save internal sate of class object from compressed json.gz.
     * Internal state is: minimal support/confidence, 1-item candidate sets, 
     * frequent itemsets, association rules, transactions, transaction count,
     * number of elements in bigest frequent itemset.
     * @param string $gzDataFile Name of data file
     * @return Apriori $this
     * @see loadState(), loadAndPrintStateFile()
     */
    public function saveState($gzDataFile) {
        self::debugInfo('SD: saving data to file(B)' . PHP_EOL);

        $data = array(
            $this->minConf, $this->minSupp,
            $this->C, $this->L, $this->rules, $this->trans,
            $this->transCount, $this->LLevels);
        //json_encode can eat "really" big amount of RAM
        if (!is_dir(dirname($gzDataFile)))
            @mkdir(dirname($gzDataFile), 0750, true);
        $res  = file_put_contents($gzDataFile, gzcompress(json_encode($data)));
        if ($res === false || json_last_error() !== JSON_ERROR_NONE)
            throw new RuntimeException('Error while saving data');

//        //altenative method, less RAM than json, faster tan json
//        $data = igbinary_serialize($data);
//        //faster, medium compression
//        $res = file_put_contents($gzDataFile.'.flz',fastlz_compress($data));
//        //slower, better compression
//        $res = file_put_contents($gzDataFile.'.gz',gzcompress($data));
//        if ($res === false)
//            throw new Exception('Error while saving data');

        self::debugInfo('SD: saving data to file(E)' . PHP_EOL);

        return $this;
    }

    /**
     * Load saved internal sate of class object from compressed json.gz.
     * @param string $gzDataFile Name of data file
     * @return Apriori $this
     * @see saveState(), loadAndPrintStateFile()
     */
    public function loadState($gzDataFile) {
        list($this->minConf, $this->minSupp,
                $this->C, $this->L, $this->rules, $this->trans,
                $this->transCount, $this->LLevels) = self::loadAndPrintStateFile($gzDataFile, false);
        return $this;
    }

    /**
     * Load and/or print saved internal sate of class object from compressed json.gz.
     * @param string $gzDataFile Name of data file
     * @param boolean $printData [Optional] If true data will also be displayed
     * @return Apriori $this
     * @see saveState(), loadState()
     */
    public static function &loadAndPrintStateFile($gzDataFile, $printData = true) {
        if (is_array($gzDataFile) || !is_file($gzDataFile))
            throw new Exception('Data source not found');

        self::debugInfo('LD: loading data from file(B)' . PHP_EOL);

        $data = json_decode(gzuncompress(file_get_contents($gzDataFile)), true);
        if (json_last_error() !== JSON_ERROR_NONE)
            throw new RuntimeException('Error while loading data');

        self::debugInfo('LD: loading data from file(E)' . PHP_EOL);

        if ($printData)
            print_r($data);
        return $data;
    }

    /**
     * Convert size in bytes to kB, MB etc.
     * @param int $size Size in bytes.
     * @return string Formated string.
     */
    public static function sizeHumRead($size) {
        $i   = 0;
        $iec = array("B", "kB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB");
        while (($size / 1024.0) > 1) {
            $size = round(($size / 1024.0), 2);
            $i++;
        }
        return substr($size, 0, strpos($size, '.') + 3) . $iec[$i];
    }

    /**
     * Display formated string if $DEBUG_INFO is set to true.
     * @param string $format see sprintf for more info.
     * @param mixed $args [optional]
     * @param mixed $_ [optional] 
     * @see $DEBUG_INFO
     * @see printf(), sprintf()
     */
    private static function debugInfo($format, $args = null, $_ = null) {
        if (self::$DEBUG_INFO) {
            $args = func_get_args();
            array_shift($args);
            array_unshift($args, self::sizeHumRead(memory_get_usage(self::$REAL_MEMORY_USAGE)));
            array_unshift($args, date("H:i:s") . substr((string) microtime(), 1, 4));
            vprintf('%s (%8s) - ' . $format, $args);
        }
    }

    /**
     * Display simple progress or progressbar
     * in relation to sate of static variables $DEBUG_INFO i $PRETTY_PROGRESS.
     * @param int $total Total number of elements.
     * @see $DEBUG_INFO
     * @see $PRETTY_PROGRESS
     */
    private static function debugProgressBar($total) {
        if (self::$DEBUG_INFO) {
            static $current = 0;

            if (self::$PRETTY_PROGRESS) {
                if ($current === 0) {
                    self::$PROGRESS_BAR = new Console_ProgressBar(
                            self::$PROGRESS_BAR_FORMAT, '=>', '-', self::HORIZ_SEP_LEN, $total, self::$PROGRESS_BAR_OPTIONS);
                }
                ++$current;
                if (self::$PROGRESS_BAR)
                    self::$PROGRESS_BAR->update($current);
            } else {
                $char = "\r";
                switch (++$current % 4) {
                    case 0: $char .= '-';
                        break;
                    case 1: $char .= '\\';
                        break;
                    case 2: $char .= '|';
                        break;
                    case 3: $char .= '/';
                        break;
                }
                printf('%s %3.2f%%', $char, ($current / $total * 100));
            }
            if ($current >= $total) {
                $current = 0;
                echo PHP_EOL;
            }
        }
    }

    /**
     * Set debug mode flags.
     * @param boolean $debug [Optional] Enable debug mode.
     * @param boolean $realMemory [Optional] RealMemory mode.
     * @param boolean $prettyProgress [Optional] Display progressbar.
     * @see $DEBUG_INFO
     * @see $REAL_MEMORY_USAGE 
     * @see $PRETTY_PROGRESS
     */
    public static function setDebugInfo($debug = true, $realMemory = false, $prettyProgress = false) {
        self::$DEBUG_INFO = (bool) $debug;
        self::$REAL_MEMORY_USAGE = (bool) $realMemory;
        self::$PRETTY_PROGRESS = (bool) $prettyProgress;

        if (self::$PRETTY_PROGRESS) {
            require_once 'Console/ProgressBar.php';
        }
    }

    /**
     * Echo to console and/or save progress to file
     * @param boolean $filename Name of file.
     * @param boolean $data Data to save/print.
     * @param boolean $append [Optional] Appends data to file if true, overwrite otherwise
     * @see displayTransactions()
     * @see displayRules() 
     * @see displayRecommendations()
     */
    private static function saveDisplay($filename, $data, $append = false) {
        if (!empty(self::$TARGET_DIR)) {
            if (!is_dir(self::$TARGET_DIR))
                @mkdir(self::$TARGET_DIR, 0750, true);
            file_put_contents(self::$TARGET_DIR . '/' . $filename, $data, ($append) ? FILE_APPEND : null );
        }
        if (self::$DISPLAY)
            echo $data;
    }

    /**
     * Set if results should be displayed and/or saved to file and where.
     * @param boolean $targetDir [Optional] Destination directory for saving results.
     * @param boolean $display [Optional] If true, then results are displayed in console
     * @see $DISPLAY
     * @see $TARGET_DIR
     */
    public static function setResultTarget($targetDir = null, $display = true) {
        self::$DISPLAY = $display;
        self::$TARGET_DIR = $targetDir;
    }

}
