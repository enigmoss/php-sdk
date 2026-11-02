<?php

declare(strict_types=1);

namespace Enigmoss\Payout;

/**
 * Exchange rate source of a payout with conversion. The project setting is used when omitted.
 */
enum RateSource: string
{
    case Binance = 'Binance';
    case BinanceP2p = 'BinanceP2p';
    case Exmo = 'Exmo';
    case Garantexio = 'Garantexio';
    case Kucoin = 'Kucoin';
}
