<?php
namespace App\Service;
final class OrderService {
    public function total(array $items): int {
        $total = 0;
        foreach ($items as $item) {
            if ($item > 0) {
                $total += $item;
            }
        }
        $total = max(0, $total);
        return $total;
    }
}
