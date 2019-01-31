<?php
namespace rosasurfer\rt\synthetic\index;

use rosasurfer\exception\IllegalTypeException;

use rosasurfer\rt\synthetic\AbstractSynthesizer;
use rosasurfer\rt\synthetic\SynthesizerInterface as Synthesizer;


/**
 * NZDFX7 synthesizer
 *
 * A {@link Synthesizer} for calculating the New Zealand Dollar FX7 index.
 *
 * <pre>
 * Formulas:
 * ---------
 * NZDFX7 = USDLFX * NZDUSD
 * NZDFX7 = pow(USDCAD * USDCHF * USDJPY / (AUDUSD * EURUSD * GBPUSD), 1/7) * NZDUSD
 * NZDFX7 = pow(NZDCAD * NZDCHF * NZDJPY * NZDUSD / (AUDNZD * EURNZD * GBPNZD), 1/7)
 * </pre>
 */
class NZDFX7 extends AbstractSynthesizer {


    /** @var string[][] */
    protected $components = [
        'fast'    => ['NZDUSD', 'USDLFX'],
        'majors'  => ['AUDUSD', 'EURUSD', 'GBPUSD', 'NZDUSD', 'USDCAD', 'USDCHF', 'USDJPY'],
        'crosses' => ['AUDNZD', 'EURNZD', 'GBPNZD', 'NZDCAD', 'NZDCHF', 'NZDJPY', 'NZDUSD'],
    ];


    /**
     * {@inheritdoc}
     */
    public function calculateQuotes($day) {
        if (!is_int($day)) throw new IllegalTypeException('Illegal type of parameter $day: '.getType($day));

        if (!$symbols = $this->loadComponents(first($this->components)))
            return [];
        if (!$day && !($day = $this->findCommonHistoryM1Start($symbols)))   // if no day was specified find the oldest available history
            return [];
        if (!$this->symbol->isTradingDay($day))                             // skip non-trading days
            return [];
        if (!$quotes = $this->loadComponentHistory($symbols, $day))
            return [];

        // calculate quotes
        echoPre('[Info]    '.$this->symbolName.'  calculating M1 history for '.gmDate('D, d-M-Y', $day));
        $NZDUSD = $quotes['NZDUSD'];
        $USDLFX = $quotes['USDLFX'];

        $digits = $this->symbol->getDigits();
        $point  = $this->symbol->getPoint();
        $bars   = [];

        // NZDFX7 = USDLFX * NZDUSD
        foreach ($NZDUSD as $i => $bar) {
            $nzdusd = $NZDUSD[$i]['open'];
            $usdlfx = $USDLFX[$i]['open'];
            $open   = round($usdlfx * $nzdusd, $digits);
            $iOpen  = (int) round($open/$point);

            $nzdusd = $NZDUSD[$i]['close'];
            $usdlfx = $USDLFX[$i]['close'];
            $close  = round($usdlfx * $nzdusd, $digits);
            $iClose = (int) round($close/$point);

            $bars[$i]['time' ] = $bar['time'];
            $bars[$i]['open' ] = $open;
            $bars[$i]['high' ] = $iOpen > $iClose ? $open : $close;         // no min()/max() for performance
            $bars[$i]['low'  ] = $iOpen < $iClose ? $open : $close;
            $bars[$i]['close'] = $close;
            $bars[$i]['ticks'] = $iOpen==$iClose ? 1 : (abs($iOpen-$iClose) << 1);
        }
        return $bars;
    }
}
