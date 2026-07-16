<?php

function deductStock($pdo, $product_id, $unit, $qty, $user_id)
{
    $stmt = $pdo->prepare("
        SELECT box_qty, pcs_qty, bottle_qty, unit_type
        FROM products
        WHERE id=? AND user_id=?
    ");
    $stmt->execute([$product_id, $user_id]);
    $stock = $stmt->fetch();

    if (!$stock) {
        throw new Exception("Product not found.");
    }

    $box    = (int) $stock['box_qty'];
    $pcs    = (int) $stock['pcs_qty'];
    $bottle = (int) $stock['bottle_qty'];

    if ($unit === 'PCS') {
        // Total available pcs = loose pcs + (boxes × 10)
        $totalAvailablePcs = $pcs + ($box * 10);

        if ($qty > $totalAvailablePcs) {
            throw new Exception("Not enough stock. Only {$totalAvailablePcs} pcs available.");
        }

        $pcs -= $qty;

        // If pcs go negative, convert boxes to cover the deficit
        while ($pcs < 0 && $box > 0) {
            $box--;
            $pcs += 10;
        }

        // Final safety check — should never reach here after pre-check above
        if ($pcs < 0) {
            throw new Exception("Not enough stock.");
        }
    }

    if ($unit === 'BOX') {
        if ($qty > $box) {
            throw new Exception("Not enough stock. Only {$box} box(es) available.");
        }
        $box -= $qty;
    }

    if ($unit === 'BOTTLE') {
        if ($qty > $bottle) {
            throw new Exception("Not enough stock. Only {$bottle} bottle(s) available.");
        }
        $bottle -= $qty;
    }

    // Final guard — never allow negative values to be saved
    $box    = max(0, $box);
    $pcs    = max(0, $pcs);
    $bottle = max(0, $bottle);

    $update = $pdo->prepare("
        UPDATE products
        SET box_qty=?, pcs_qty=?, bottle_qty=?
        WHERE id=? AND user_id=?
    ");
    $update->execute([$box, $pcs, $bottle, $product_id, $user_id]);
}

function addStock($pdo, $product_id, $unit, $qty, $user_id)
{
    if ($unit === "BOX") {
        $pdo->prepare("
            UPDATE products 
            SET box_qty = box_qty + ?
            WHERE id=? AND user_id=?
        ")->execute([$qty, $product_id, $user_id]);
    } elseif ($unit === "PCS") {
        $pdo->prepare("
            UPDATE products 
            SET pcs_qty = pcs_qty + ?
            WHERE id=? AND user_id=?
        ")->execute([$qty, $product_id, $user_id]);
    } elseif ($unit === "BOTTLE") {
        $pdo->prepare("
            UPDATE products 
            SET bottle_qty = bottle_qty + ?
            WHERE id=? AND user_id=?
        ")->execute([$qty, $product_id, $user_id]);
    }
}