<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;

class SharedVatRateResolver
{
    public function resolveForOrder(Order $order): ?float
    {
        $vatRates = [];

        /** @var Item $item */
        foreach ($order->getAllItems() as $item) {
            if ($item->isDummy()) {
                continue;
            }

            if ((float)$item->getQtyOrdered() <= 0.0) {
                continue;
            }

            $taxPercent = $item->getTaxPercent();
            if ($taxPercent === null) {
                return null;
            }

            $vatRates[] = (float)$taxPercent;
        }

        if (!count($vatRates)) {
            return null;
        }

        $vatRates = array_values(array_unique(array_map(
            static fn (float $rate): string => number_format($rate, 4, '.', ''),
            $vatRates
        )));

        if (count($vatRates) !== 1) {
            return null;
        }

        return (float)$vatRates[0];
    }
}
