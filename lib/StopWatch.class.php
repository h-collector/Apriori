<?php

/**
 * Simple stopwatch.
 *
 * @package HC
 * @author  h-collector <githcoll@gmail.com>
 *          
 * @link    http://hcoll.onuse.pl/view/HCImage
 * @license GNU LGPL (http://www.gnu.org/copyleft/lesser.html)
 */
class StopWatch {

    public $total;
    public $time;
    static private $printable = false;

    public function __construct() {
        $this->total = $this->time = microtime(true);
    }

    public function clock() {
        return -$this->time + ($this->time = microtime(true));
    }

    public function elapsed() {
        return microtime(true) - $this->total;
    }

    public function reset() {
        $this->total = $this->time = microtime(true);
    }

    public function printInterval($type ='') {
        $time = $this->clock();
        if (self::$printable)
            printf('Partial time of operation %s: %fs' . PHP_EOL, $type, $time);
    }

    public function printElapsed($type ='') {
        if (self::$printable)
            printf('Total time %s: %fs' . PHP_EOL, $type, $this->elapsed());
    }

    static public function setPrintable($printable) {
        self::$printable = $printable;
    }

}
