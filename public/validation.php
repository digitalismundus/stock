<?php
declare(strict_types=1);
if (!defined('STOCK_API')) { http_response_code(404); exit; }

function stockFinite(float|int $value): float {
    requireThat(is_finite((float)$value), 'Wartości portfela przekraczają bezpieczny zakres obliczeń.');
    return (float)$value;
}
function stockDecimal(float|int $value): array {
    requireThat(is_finite((float)$value) && $value >= 0, 'Nieprawidłowa podstawa prowizji.');
    if ($value == 0) return ['0', 0];
    $text = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    requireThat(preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/D', $text, $m) === 1, 'Nieprawidłowa podstawa prowizji.');
    $fraction = $m[2] ?? '';
    return [ltrim($m[1].$fraction, '0') ?: '0', (int)($m[3] ?? 0) - strlen($fraction)];
}
function stockMultiplyDigits(string $a, string $b): string {
    $digits = array_fill(0, strlen($a) + strlen($b), 0);
    for ($i = strlen($a)-1; $i >= 0; $i--) for ($j = strlen($b)-1; $j >= 0; $j--) $digits[$i+$j+1] += (int)$a[$i] * (int)$b[$j];
    for ($i = count($digits)-1; $i > 0; $i--) { $digits[$i-1] += intdiv($digits[$i], 10); $digits[$i] %= 10; }
    return ltrim(implode('', $digits), '0') ?: '0';
}
function stockDecimalInteger(float|int $a, float|int $b): int {
    [$ca, $ea] = stockDecimal($a); [$cb, $eb] = stockDecimal($b);
    $digits = stockMultiplyDigits($ca, $cb); $exponent = $ea + $eb;
    if ($digits === '0') return 0;
    if ($exponent >= 0) $rounded = $digits.str_repeat('0', $exponent);
    else {
        $cut = strlen($digits) + $exponent;
        $rounded = $cut > 0 ? substr($digits, 0, $cut) : '0';
        $next = $cut >= 0 ? ($digits[$cut] ?? '0') : '0';
        if ($next >= '5') {
            $chars = str_split($rounded); $carry = 1;
            for ($i = count($chars)-1; $i >= 0 && $carry; $i--) { $n = (int)$chars[$i] + $carry; $chars[$i] = (string)($n % 10); $carry = intdiv($n, 10); }
            $rounded = ($carry ? '1' : '').implode('', $chars);
        }
    }
    $rounded = ltrim($rounded, '0') ?: '0'; $maximum = '9007199254740991';
    requireThat(strlen($rounded) < strlen($maximum) || (strlen($rounded) === strlen($maximum) && strcmp($rounded, $maximum) <= 0), 'Prowizja przekracza bezpieczny zakres obliczeń.');
    return (int)$rounded;
}
function stockPercentFee(float|int $base, float|int $percent, float|int $minimum): float {
    stockFinite($base);
    return max(stockDecimalInteger($base, $percent), stockDecimalInteger($minimum, 100)) / 100;
}
function stockPercentFeeMatches(float|int $base, float|int $percent, float|int $minimum, float|int $actual): bool {
    $exact = stockPercentFee($base, $percent, $minimum);
    // Preserve actual historical fees produced by the former JavaScript calculation on import.
    $legacy = floor(stockFinite((max($base*$percent/100, $minimum)+PHP_FLOAT_EPSILON)*100)+0.5)/100;
    return abs($exact-$actual)<1e-7 || abs($legacy-$actual)<1e-7;
}
function stockCurrentRate(array $rate, string $field): float {
    $day = $rate['date']; $value = $rate[$field];
    foreach ($rate['history'] ?? [] as $point) if ($point['date'] > $day) { $day = $point['date']; $value = $point[$field === 'price' ? 'close' : 'rate']; }
    return (float)$value;
}
function stockValidateFinancialRange(array $state, array $ordered): void {
    $positions = []; $cash = []; $maxCash = []; $totals = ['fees'=>0.0,'deposits'=>0.0,'withdrawals'=>0.0,'dividends'=>0.0,'realized'=>0.0];
    foreach ($state['assets'] as $asset) $positions[$asset['id']] = ['asset'=>$asset,'quantity'=>0.0,'maxQuantity'=>0.0,'realized'=>0.0,'dividends'=>0.0,'lots'=>[]];
    $addCash = function(string $currency, float $amount) use (&$cash, &$maxCash): void { $cash[$currency] = stockFinite(($cash[$currency] ?? 0) + $amount); $maxCash[$currency] = max($maxCash[$currency] ?? 0, abs($cash[$currency])); };
    foreach ($ordered as $tx) {
        $fee = $tx['fee']; $fx = $tx['fx']; $type = $tx['type'];
        $totals['fees'] = stockFinite($totals['fees'] + stockFinite($fee*$fx));
        if ($type === 'buy' || $type === 'sell') {
            $value = stockFinite($tx['quantity']*$tx['price']); $id = $tx['assetId'];
            if ($type === 'buy') {
                $cost = stockFinite($value+$fee); $addCash($tx['currency'], -$cost);
                $positions[$id]['lots'][] = ['quantity'=>$tx['quantity'],'unit'=>stockFinite($cost/$tx['quantity']),'fx'=>$fx];
                $positions[$id]['quantity'] = stockFinite($positions[$id]['quantity']+$tx['quantity']);
                $positions[$id]['maxQuantity'] = max($positions[$id]['maxQuantity'], $positions[$id]['quantity']);
            } else {
                $held = $positions[$id]['quantity']; $tolerance = max($held, $tx['quantity'])*PHP_FLOAT_EPSILON*8;
                requireThat($held >= $tx['quantity'] || ($held>0 && $tx['quantity']-$held <= $tolerance), 'Sprzedaż przekracza posiadaną liczbę jednostek.');
                $cost = 0.0;
                if (abs($held-$tx['quantity']) <= $tolerance) {
                    foreach ($positions[$id]['lots'] as $lot) $cost = stockFinite($cost+stockFinite(stockFinite($lot['quantity']*$lot['unit'])*$lot['fx']));
                    $positions[$id]['lots'] = [];
                } else {
                    $remaining = $tx['quantity'];
                    foreach ($positions[$id]['lots'] as &$lot) {
                        if ($remaining <= 0) break;
                        $quantity = min($remaining, $lot['quantity']);
                        $cost = stockFinite($cost + stockFinite(stockFinite($quantity*$lot['unit'])*$lot['fx']));
                        $lot['quantity'] -= $quantity; $remaining -= $quantity;
                    }
                    unset($lot);
                    requireThat($remaining <= 0, 'Nieprawidłowe partie zakupu.');
                    $positions[$id]['lots'] = array_values(array_filter($positions[$id]['lots'], fn($lot)=>$lot['quantity']>0));
                }
                $profit = stockFinite(stockFinite(($value-$fee)*$fx)-$cost);
                $totals['realized'] = stockFinite($totals['realized']+$profit);
                $positions[$id]['realized'] = stockFinite($positions[$id]['realized']+$profit);
                $positions[$id]['quantity'] = 0.0; foreach ($positions[$id]['lots'] as $lot) $positions[$id]['quantity'] = stockFinite($positions[$id]['quantity']+$lot['quantity']);
                $addCash($tx['currency'], stockFinite($value-$fee));
            }
        } else {
            $amount = $tx['amount']; $converted = stockFinite($amount*$fx);
            if ($type === 'deposit') { $addCash($tx['currency'], stockFinite($amount-$fee)); $totals['deposits'] = stockFinite($totals['deposits']+$converted); }
            if ($type === 'withdrawal') { $addCash($tx['currency'], stockFinite(-$amount-$fee)); $totals['withdrawals'] = stockFinite($totals['withdrawals']+$converted); }
            if ($type === 'dividend') { $net = stockFinite($amount-($tx['tax'] ?? 0)-$fee); $addCash($tx['currency'], $net); $convertedNet = stockFinite($net*$fx); $totals['dividends'] = stockFinite($totals['dividends']+$convertedNet); $positions[$tx['assetId']]['dividends'] = stockFinite($positions[$tx['assetId']]['dividends']+$convertedNet); }
            if ($type === 'fee') { $addCash($tx['currency'], stockFinite(-$amount-$fee)); $totals['fees'] = stockFinite($totals['fees']+$converted); }
            if ($type === 'exchange') { $addCash($tx['currency'], stockFinite(-$amount-$fee)); $addCash($tx['targetCurrency'], (float)$tx['targetAmount']); stockFinite($tx['targetAmount']*$tx['targetFx']); }
        }
    }
    $invested = 0.0; $allCost = 0.0; $cashValue = 0.0; $allUnrealized = 0.0;
    foreach ($positions as $position) {
        $cost = 0.0; $nativeCost = 0.0;
        foreach ($position['lots'] as $lot) { $native = stockFinite($lot['quantity']*$lot['unit']); $nativeCost = stockFinite($nativeCost+$native); $cost = stockFinite($cost+stockFinite($native*$lot['fx'])); }
        $allCost = stockFinite($allCost+$cost); $asset = $position['asset'];
        if (!isset($asset['quote'], $state['fx'][$asset['currency']])) continue;
        $price = stockCurrentRate($asset['quote'], 'price'); $rate = stockCurrentRate($state['fx'][$asset['currency']], 'rate');
        $value = stockFinite(stockFinite($position['quantity']*$price)*$rate);
        $unrealized = stockFinite($value-$cost); $invested = stockFinite($invested+$value); $allUnrealized = stockFinite($allUnrealized+$unrealized);
        stockFinite(stockFinite($position['quantity']*$price-$nativeCost)*$rate); stockFinite($nativeCost*$rate-$cost);
        if ($cost > 0) stockFinite(stockFinite($unrealized/$cost)*100);
    }
    foreach ($cash as $currency=>$amount) if (isset($state['fx'][$currency])) $cashValue = stockFinite($cashValue+stockFinite($amount*stockCurrentRate($state['fx'][$currency], 'rate')));
    $total = stockFinite($invested+$cashValue); $netDeposits = stockFinite($totals['deposits']-$totals['withdrawals']); $profit = stockFinite($total-$netDeposits);
    if ($netDeposits > 0) stockFinite(stockFinite($profit/$netDeposits)*100);
    // A conservative upper bound also protects earlier quotes without recalculating every historical day.
    $maxRates = []; $envelope = 0.0;
    foreach ($state['fx'] as $currency=>$rate) { $maxRates[$currency] = (float)$rate['rate']; foreach ($rate['history'] ?? [] as $point) $maxRates[$currency] = max($maxRates[$currency], $point['rate']); }
    foreach ($positions as $position) {
        $asset = $position['asset']; if (!isset($asset['quote'], $maxRates[$asset['currency']])) continue;
        $maxPrice = $asset['quote']['price']; foreach ($asset['quote']['history'] as $point) $maxPrice = max($maxPrice, $point['close']);
        $envelope = stockFinite($envelope+stockFinite(stockFinite($position['maxQuantity']*$maxPrice)*$maxRates[$asset['currency']]));
    }
    foreach ($maxCash as $currency=>$amount) if (isset($maxRates[$currency])) $envelope = stockFinite($envelope+stockFinite($amount*$maxRates[$currency]));
    $retirement = 0.0;
    foreach ($state['retirement'] ?? [] as $account) { $history = $account['history']; usort($history, fn($a,$b)=>strcmp($a['date'],$b['date'])); $retirement = stockFinite($retirement+end($history)['amount']); }
    stockFinite($total+$retirement);
}
function stockIsFuture(array $asset): bool {
    return str_ends_with(strtoupper($asset['providerSymbol'] ?? ''), '=F') || str_ends_with(strtoupper($asset['symbol']), '=F');
}
function stockValidateNewTrades(array $state, array $previous): void {
    $assets = []; $oldAssets = []; $oldTrades = [];
    foreach ($state['assets'] as $asset) $assets[$asset['id']] = $asset;
    foreach ($previous['assets'] ?? [] as $asset) $oldAssets[$asset['id']] = $asset;
    foreach ($previous['ledger'] ?? [] as $tx) $oldTrades[$tx['id']] = $tx;
    foreach ($state['ledger'] as $tx) {
        if (!in_array($tx['type'], ['buy','sell'], true) || !stockIsFuture($assets[$tx['assetId']])) continue;
        $old = $oldTrades[$tx['id']] ?? null; $oldAsset = $oldAssets[$tx['assetId']] ?? null; $unchanged = $old !== null && $oldAsset !== null && stockIsFuture($oldAsset);
        foreach (['type','assetId','date','currency','quantity','price','fee','fx'] as $field) if (in_array($field,['quantity','price','fee','fx'],true) ? (float)($old[$field]??-1)!==(float)$tx[$field] : ($old[$field]??null)!==$tx[$field]) $unchanged = false;
        foreach (['symbol','providerSymbol'] as $field) if (($oldAsset[$field] ?? '') !== ($assets[$tx['assetId']][$field] ?? '')) $unchanged = false;
        requireThat($unchanged, 'Kontrakty futures (=F) nie obsługują zakupów ani sprzedaży: model portfela nie uwzględnia mnożnika i depozytu zabezpieczającego. Historyczne wpisy można odczytać lub usunąć.');
    }
}
function stockTechnicalHistory(array $result, ?DateTimeImmutable $now = null): array {
    $currency = $result['meta']['currency'] ?? ''; $factor = in_array($currency, ['GBp','GBX'], true) ? 0.01 : 1;
    if ($factor !== 1) $currency = 'GBP';
    if (!validCurrency($currency)) throw new RuntimeException('Brak potwierdzonej waluty notowań.');
    $adjusted = $result['indicators']['adjclose'][0]['adjclose'] ?? null;
    if (!is_array($adjusted)) throw new RuntimeException('Brak skorygowanej historii. Nie zastępujemy jej nieskorygowanymi cenami.');
    $zone = new DateTimeZone($result['meta']['exchangeTimezoneName'] ?? 'UTC');
    $sourceDay = ($now ?? new DateTimeImmutable('now'))->setTimezone($zone)->format('Y-m-d'); $points = [];
    foreach ($result['timestamp'] ?? [] as $i=>$timestamp) {
        $value = $adjusted[$i] ?? null; if (!validNumber($value, true)) continue;
        $day = (new DateTimeImmutable('@'.(int)$timestamp))->setTimezone($zone)->format('Y-m-d');
        if ($day < $sourceDay) $points[] = ['date'=>$day,'close'=>$value*$factor];
    }
    return ['history'=>$points,'currency'=>$currency,'marketDay'=>$sourceDay,'timezone'=>$zone->getName()];
}
