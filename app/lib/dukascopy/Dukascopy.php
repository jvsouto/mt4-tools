<?php
namespace rosasurfer\rt\lib\dukascopy;

use rosasurfer\console\io\Output;
use rosasurfer\core\Object;
use rosasurfer\exception\IllegalArgumentException;
use rosasurfer\exception\IllegalTypeException;
use rosasurfer\exception\InvalidArgumentException;
use rosasurfer\exception\RuntimeException;
use rosasurfer\file\FileSystem as FS;
use rosasurfer\log\Logger;

use rosasurfer\rt\lib\LZMA;
use rosasurfer\rt\lib\dukascopy\HttpClient as DukascopyClient;
use rosasurfer\rt\model\DukascopySymbol;

use function rosasurfer\rt\fxTime;
use function rosasurfer\rt\periodToStr;

use const rosasurfer\rt\DUKASCOPY_BAR_SIZE;
use const rosasurfer\rt\DUKASCOPY_TICK_SIZE;
use const rosasurfer\rt\PERIOD_M1;
use const rosasurfer\rt\PERIOD_D1;


/**
 * Dukascopy
 *
 * Functionality for downloading and processing Dukascopy history data.
 */
class Dukascopy extends Object {


    /** @var HttpClient */
    protected $httpClient;

    /** @var array[] - internal cache for single fetched history start data */
    protected $historyStarts;

    /** @var array[] - internal cache for all fetched history start data */
    protected $allHistoryStarts;

    /** @var array - symbol mapping from lower-case to Dukascopy name */
    protected $symbolMapping;


    /**
     * Return a Dukascopy specific HTTP client. The instance is kept to enable "keep-alive" connections.
     *
     * @return HttpClient
     */
    protected function getHttpClient() {
        if (!$this->httpClient) {
            $this->httpClient = new DukascopyClient();
        }
        return $this->httpClient;
    }


    /**
     * Fetch history start for the specified symbol from Dukascopy.
     *
     * @param  string $symbol
     *
     * @return array - history start times per timeframe or an empty value in case of errors
     *
     * <pre>
     * Array (
     *     [{timeframe-id}] => [{timestamp}],               // e.g.: PERIOD_TICK => Mon, 04-Aug-2003 10:03:02.837,
     *     [{timeframe-id}] => [{timestamp}],               //       PERIOD_M1   => Mon, 04-Aug-2003 10:03:00,
     *     [{timeframe-id}] => [{timestamp}],               //       PERIOD_H1   => Mon, 04-Aug-2003 10:00:00,
     *     ...                                              //       PERIOD_D1   => Mon, 25-Nov-1991 00:00:00,
     * )
     * </pre>
     */
    public function fetchHistoryStart($symbol) {
        $symbolU = strtoupper($symbol);

        if (isset($this->allHistoryStarts[$symbolU]))
            return $this->allHistoryStarts[$symbolU];
        if (isset($this->historyStarts[$symbolU]))
            return $this->historyStarts[$symbolU];

        /** @var Output $output */
        $output = $this->di(Output::class);
        $output->out('[Info]    '.$symbol.'  fetching history start times from Dukascopy...');

        $data = $this->getHttpClient()->downloadHistoryStart($symbol);
        if (strlen($data))
            return $this->historyStarts[$symbolU] = $this->readHistoryStartSection($data);
        return [];
    }


    /**
     * Fetch history start times from Dukascopy for all available symbols. Returns a list of arrays with history start times
     * for each available symbol.
     *
     * @return array[] - list of arrays in a format as follows:
     *
     * <pre>
     * Array (
     *     [{symbol}] => [
     *         [{timeframe-id}] => [{timestamp}],           // e.g.: PERIOD_TICK => Mon, 04-Aug-2003 10:03:02.837,
     *         [{timeframe-id}] => [{timestamp}],           //       PERIOD_M1   => Mon, 04-Aug-2003 10:03:00,
     *         [{timeframe-id}] => [{timestamp}],           //       PERIOD_H1   => Mon, 04-Aug-2003 10:00:00,
     *         [{timeframe-id}] => [{timestamp}],           //       PERIOD_D1   => Mon, 25-Nov-1991 00:00:00,
     *     ],
     *     [{symbol}] => [
     *         ...
     *     ],
     *     ...
     * )
     * </pre>
     */
    public function fetchHistoryStarts() {
        if ($this->allHistoryStarts)
            return $this->allHistoryStarts;

        /** @var Output $output */
        $output = $this->di(Output::class);
        $output->out('[Info]    Fetching history start times from Dukascopy...');

        $data = $this->getHttpClient()->downloadHistoryStart();
        if (strlen($data))
            return $this->allHistoryStarts = $this->readHistoryStarts($data);
        return [];
    }


    /**
     * Get history for the specified symbol, timeframe and time. Downloads the required data and converts Dukascopy GMT times
     * to FXT. The covered range of the returned timeseries depends on the requested timeframe.
     *
     * @param  string $symbol
     * @param  int    $timeframe
     * @param  int    $time
     * @param  int    $priceType
     *
     * @return array[] - If history for the specified time and timeframe is not available an empty array is returned.
     *                   Otherwise a timeseries array is returned with each element describing a single price bar as follows:
     * <pre>
     * Array [
     *     'time'  => (int),            // bar open time (FXT)
     *     'open'  => (float),          // open value
     *     'high'  => (float),          // high value
     *     'low'   => (float),          // low value
     *     'close' => (float),          // close value
     *     'ticks' => (int),            // ticks or volume (if available)
     * ];
     * </pre>
     */
    public function getHistory($symbol, $timeframe, $time, $priceType) {
        return [];
        /*
        $date = gmdate('D, d-M-Y', $time);
        $types = ['bid', 'ask'];
        foreach ($types as $type) {
            if (!isset($barBuffer[$type][$date]) || sizeof($barBuffer[$type][$date])!=PERIOD_D1) {
                loadHistory($symbol, $day, $type);      // Bid- und Ask-Daten laden
            }
        }
        mergeHistory($symbol, $day);                    // beide mergen
        */
    }


    /**
     * Decompress a compressed Dukascopy data string and return it.
     *
     * @param  string $data              - compressed string with bars or ticks
     * @param  string $saveAs [optional] - if specified the decompressed string is additionally stored in the given file
     *                                     (default: no storage)
     *
     * @return string - decompressed data string
     */
    public static function decompressHistoryData($data, $saveAs = null) {
        if (!is_string($data))       throw new IllegalTypeException('Illegal type of parameter $data: '.gettype($data));
        if (isset($saveAs)) {
            if (!is_string($saveAs)) throw new IllegalTypeException('Illegal type of parameter $saveAs: '.gettype($saveAs));
            if (!strlen($saveAs))    throw new InvalidArgumentException('Invalid parameter $saveAs: ""');
        }

        $rawData = LZMA::decompressData($data);

        if (isset($saveAs)) {
            FS::mkDir(dirname($saveAs));
            $tmpFile = tempnam(dirname($saveAs), basename($saveAs));
            file_put_contents($tmpFile, $rawData);
            if (is_file($saveAs)) unlink($saveAs);
            rename($tmpFile, $saveAs);
        }
        return $rawData;
    }


    /**
     * Decompress a compressed Dukascopy data file and return its content.
     *
     * @param  string $compressedFile    - name of the compressed data file
     * @param  string $saveAs [optional] - if specified the decompressed content is additionally stored in the given file
     *                                     (default: no storage)
     *
     * @return string - decompressed file content
     */
    public static function decompressHistoryFile($compressedFile, $saveAs = null) {
        if (!is_string($compressedFile)) throw new IllegalTypeException('Illegal type of parameter $compressedFile: '.gettype($compressedFile));
        return self::decompressHistoryData(file_get_contents($compressedFile), $saveAs);
    }


    /**
     * Parse a string with Dukascopy bar data and convert it to a timeseries array.
     *
     * @param  string $data   - string with Dukascopy bar data
     * @param  string $symbol - Dukascopy symbol
     * @param  string $type   - meta info for error message generation
     * @param  int    $time   - ditto
     *
     * @return array[] - DUKASCOPY_BAR[] data as a timeseries array
     */
    public static function readBarData($data, $symbol, $type, $time) {
        /** @var DukascopySymbol $dukaSymbol */
        $dukaSymbol = DukascopySymbol::dao()->getByName($symbol);
        $symbol     = $dukaSymbol->getName();
        $digits     = $dukaSymbol->getDigits();
        $divider    = pow(10, $digits);

        if (!is_string($data))                        throw new IllegalTypeException('Illegal type of parameter $data: '.gettype($data));
        $lenData = strlen($data);
        if (!$lenData || $lenData%DUKASCOPY_BAR_SIZE) throw new RuntimeException('Odd length of passed '.$symbol.' '.$type.' data: '.$lenData.' (not an even DUKASCOPY_BAR_SIZE)');

        $offset  = 0;
        $bars    = [];
        $i       = -1;

        static $isLittleEndian = null; is_null($isLittleEndian) && $isLittleEndian=isLittleEndian();

        while ($offset < $lenData) {
            $i++;
            $bars[] = unpack("@$offset/NtimeDelta/Nopen/Nclose/Nlow/Nhigh", $data);
            $s      = substr($data, $offset+20, 4);
            $lots   = unpack('f', $isLittleEndian ? strrev($s) : $s);   // unpack doesn't support explicit big-endian floats, on little-endian
            $bars[$i]['lots'] = round($lots[1], 2);                     // machines the byte order of field "lots" must be reversed manually
            $offset += DUKASCOPY_BAR_SIZE;

            // validate bar data
            if ($bars[$i]['open' ] > $bars[$i]['high'] ||               // from (H >= O && O >= L) follws (H >= L)
                $bars[$i]['open' ] < $bars[$i]['low' ] ||               // don't use min()/max() as it's slow
                $bars[$i]['close'] > $bars[$i]['high'] ||
                $bars[$i]['close'] < $bars[$i]['low' ]) {

                $O = number_format($bars[$i]['open' ]/$divider, $digits);
                $H = number_format($bars[$i]['high' ]/$divider, $digits);
                $L = number_format($bars[$i]['low'  ]/$divider, $digits);
                $C = number_format($bars[$i]['close']/$divider, $digits);

                Logger::log("Illegal ".$symbol." $type data for bar[$i] of ".gmdate('D, d-M-Y H:i:s', $time).": O=$O H=$H L=$L C=$C, adjusting high/low...", L_WARN);

                $bars[$i]['high'] = max($bars[$i]['open'], $bars[$i]['high'], $bars[$i]['low'], $bars[$i]['close']);
                $bars[$i]['low' ] = min($bars[$i]['open'], $bars[$i]['high'], $bars[$i]['low'], $bars[$i]['close']);
            }
        }
        return $bars;
    }


    /**
     * Parse a file with Dukascopy bar data and convert it to a data array.
     *
     * @param  string $fileName - name of file with Dukascopy bar data
     * @param  string $symbol   - meta infos for generating better error messages (Dukascopy data may contain errors)
     * @param  string $type     - ...
     * @param  int    $time     - ...
     *
     * @return array - DUKASCOPY_BAR[] data
     */
    public static function readBarFile($fileName, $symbol, $type, $time) {
        if (!is_string($fileName)) throw new IllegalTypeException('Illegal type of parameter $fileName: '.gettype($fileName));
        return self::readBarData(file_get_contents($fileName), $symbol, $type, $time);
    }


    /**
     * Parse a string with Dukascopy tick data and convert it to a data array.
     *
     * @param  string $data - string with Dukascopy tick data
     *
     * @return array - DUKASCOPY_TICK[] data
     */
    public static function readTickData($data) {
        if (!is_string($data)) throw new IllegalTypeException('Illegal type of parameter $data: '.gettype($data));

        $lenData = strlen($data); if (!$lenData || $lenData%DUKASCOPY_TICK_SIZE) throw new RuntimeException('Odd length of passed data: '.$lenData.' (not an even DUKASCOPY_TICK_SIZE)');
        $offset  = 0;
        $ticks   = [];
        $i       = -1;

        static $isLittleEndian = null; is_null($isLittleEndian) && $isLittleEndian=isLittleEndian();

        // unpack doesn't support explicit big-endian floats, on little-endian machines the byte order
        // of fields "bidSize" and "askSize" has to be reversed manually
        while ($offset < $lenData) {
            $i++;
            $ticks[] = unpack("@$offset/NtimeDelta/Nask/Nbid", $data);
            $s1      = substr($data, $offset+12, 4);
            $s2      = substr($data, $offset+16, 4);
            $size    = unpack('fask/fbid', $isLittleEndian ? strrev($s1).strrev($s2) : $s1.$s2);    // manually reverse
            $ticks[$i]['askSize'] = round($size['ask'], 2);                                         // on little-endian machines
            $ticks[$i]['bidSize'] = round($size['bid'], 2);
            $offset += DUKASCOPY_TICK_SIZE;
        }
        return $ticks;
    }


    /**
     * Parse a file with Dukascopy tick data and convert it to a data array.
     *
     * @param  string $fileName - name of file with Dukascopy tick data
     *
     * @return array - DUKASCOPY_TICK[] data
     */
    public static function readTickFile($fileName) {
        if (!is_string($fileName)) throw new IllegalTypeException('Illegal type of parameter $fileName: '.gettype($fileName));
        return self::readTickData(file_get_contents($fileName));
    }


    /**
     * Parse a string with history start records of multiple symbols.
     *
     * @param  string $data - binary data
     *
     * @return array[] - associative list of arrays with variable number of elements each describing a symbol's history start
     *                   of a single timeframe as follows:
     * <pre>
     * Array (
     *     [{symbol}] => [
     *         [{timeframe-id}] => [{timestamp}],           // e.g.: PERIOD_TICK => Mon, 04-Aug-2003 10:03:02.837,
     *         [{timeframe-id}] => [{timestamp}],           //       PERIOD_M1   => Mon, 04-Aug-2003 10:03:00,
     *         [{timeframe-id}] => [{timestamp}],           //       PERIOD_H1   => Mon, 04-Aug-2003 10:00:00,
     *         [{timeframe-id}] => [{timestamp}],           //       PERIOD_D1   => Mon, 25-Nov-1991 00:00:00,
     *     ],
     *     [{symbol}] => [
     *         ...
     *     ],
     *     ...
     * )
     * </pre>
     */
    protected function readHistoryStarts($data) {
        if (!is_string($data)) throw new IllegalTypeException('Illegal type of parameter $data: '.gettype($data));
        $lenData = strlen($data);
        if (!$lenData)         throw new IllegalArgumentException('Illegal length of history start data: '.$lenData);

        $symbols = [];
        $start   = $length = $symbol = $high = $count = null;
        $offset  = 0;

        while ($offset < $lenData) {
            extract(unpack("@$offset/Cstart/Clength", $data));
            if ($start)                     throw new RuntimeException('Unexpected data format in DUKASCOPY_HISTORY_START at offset '.$offset.': start='.$start);
            $offset += 2;
            extract(unpack("@$offset/A${length}symbol/Nhigh/Ncount", $data));
            if (strlen($symbol) != $length) throw new RuntimeException('Unexpected data format in DUKASCOPY_HISTORY_START at offset '.$offset.': symbol="'.$symbol.'"  length='.$length);
            if ($high)                      throw new RuntimeException('Unexpected data format in DUKASCOPY_HISTORY_START at offset '.($offset+$length).': highInt='.$high);
            if ($count != 4)                throw new RuntimeException('Unexpected data format in DUKASCOPY_HISTORY_START at offset '.($offset+$length+1).': count='.$count);
            $offset += $length + 8;

            $timeframes = $this->readHistoryStartSection($data, $offset, $count);
            if ($timeframes) {                                                  // skip symbols without history
                ksort($timeframes);
                $symbols[strtoupper($symbol)] = $timeframes;
            }
            $offset += $count*16;
        }
        ksort($symbols);
        return $symbols;
    }


    /**
     * Parse a binary string with a history start section (consecutive history start records).
     *
     * @param  string $data              - binary data
     * @param  int    $offset [optional] - string offset to start     (default: 0)
     * @param  int    $count  [optional] - number of records to parse (default: until the end of the string)
     *
     * @return array - array with variable number of elements each describing history start of a single timeframe
     *                 as follows:
     * <pre>
     * Array (
     *     [{timeframe-id}] => [{timestamp}],               // e.g.: PERIOD_TICK => Mon, 04-Aug-2003 10:03:02.837,
     *     [{timeframe-id}] => [{timestamp}],               //       PERIOD_M1   => Mon, 04-Aug-2003 10:03:00,
     *     [{timeframe-id}] => [{timestamp}],               //       PERIOD_H1   => Mon, 04-Aug-2003 10:00:00,
     *     ...                                              //       PERIOD_D1   => Mon, 25-Nov-1991 00:00:00,
     * )
     * </pre>
     */
    protected function readHistoryStartSection($data, $offset = 0, $count = null) {
        $lenData = strlen($data);
        if (!is_int($offset) || $offset < 0)    throw new IllegalArgumentException('Invalid parameter $offset: '.$offset.' ('.gettype($offset).')');
        if ($offset >= $lenData)                throw new IllegalArgumentException('Invalid parameters, mis-matching $offset/$lenData: '.$offset.'/'.$lenData);
        if (!isset($count)) $count = PHP_INT_MAX;
        elseif (!is_int($count) || $count <= 0) throw new IllegalArgumentException('Invalid parameter $count: '.$count.' ('.gettype($count).')');

        $timeframes = [];

        while ($offset < $lenData && $count) {
            $timeframes += $this->readHistoryStartRecord($data, $offset);
            $offset += 16;
            $count--;
        }
        ksort($timeframes);
        return $timeframes;
    }


    /**
     * Parse a DUKASCOPY_TIMEFRAME_START record at the given offset of a binary string.
     *
     * @param  string $data   - binary data
     * @param  int    $offset - offset
     *
     * @return array - a key-value pair [{timeframe-id} => {timestamp}] or an empty array if history of the given timeframe
     *                 is not available
     */
    protected function readHistoryStartRecord($data, $offset) {
        // check platform
        if (PHP_INT_SIZE == 8) {
            // 64-bit integers and format codes are supported
            $record = unpack("@$offset/J2", $data);
            if ($record[1] == -1)                                               // uint64_max: sometimes used as tickdata identifier
                $record[1] = 0;
            $timeframe = $record[1] / 1000 / MINUTES;
            if (!is_int($timeframe) || (string)$timeframe==periodToStr($timeframe)) throw new RuntimeException('Unexpected Dukascopy timeframe identifier: '.$record[1]);
            $record[1] = $timeframe;
            if ($record[2] < 0) throw new \RangeException('Invalid Java timestamp: '.sprintf('%u', $record[2]).' (out of range)');
            if ($record[2] == PHP_INT_MAX)
                return [];                                                      // int64_max: no history available
            if ($record[2] % 1000) $record[2] = round($record[2]/1000, 3);
            else                   $record[2] = (int)($record[2]/1000);
        }
        else {
            // 32-bit integers: 64-bit format codes are not supported
            $ints = unpack("@$offset/N4", $data);
            $record = [];
            foreach ($ints as $i => $int) {
                $int = sprintf('%u', $int);
                if ($i % 2) $record[($i+1)/2] = bcmul($int, '4294967296', 0);   // 2 ^ 32
                else        $record[ $i=$i/2] = bcadd($record[$i], $int, 0);
            }
            if ($record[1] == '18446744073709551615')                           // uint64_max: sometimes used as tickdata identifier
                $record[1] = '0';
            /** @var int $timeframe */
            $timeframe = ((int) bcdiv($record[1], '1000', 0)) / MINUTES;
            if (!is_int($timeframe) || (string)$timeframe==periodToStr($timeframe)) throw new RuntimeException('Unexpected Dukascopy timeframe identifier: '.$record[1]);
            $record[1] = $timeframe;
            if ($record[2] == '9223372036854775807')                            // int64_max: no history available
                return [];
            if (!bcmod($record[2], '1000')) $record[2] =   (int) bcdiv($record[2], '1000', 0);
            else                            $record[2] = (float) bcdiv($record[2], '1000', 3);
        }

        if ($record[1] == PERIOD_D1) $record[2] -= ($record[2] % DAY);
        else                         $record[2]  = fxTime($record[2]);

        return [$record[1] => $record[2]];
    }
}
