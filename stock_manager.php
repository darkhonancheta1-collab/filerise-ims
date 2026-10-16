<?php
/**
 * stock_manager.php — Stock Deduction & Addition Engine
 *
 * Provides two shared utility functions used across the application
 * (transaction.php, sales.php, returns.php, stock_requests.php, etc.)
 * to safely modify product stock quantities.
 *
 * deductStock() — Removes stock with smart box-to-pcs conversion
 *   When selling PCS from a BOX product, it automatically opens boxes
 *   (1 box = 10 pcs) to cover the deficit if loose pcs run out.
 *   Throws an Exception if insufficient stock.
 *
 * addStock() — Adds stock to the specified unit column (box/pcs/bottle).
 */
/* ============================================================
   [SEARCH TAG: DEDUCT_STOCK / DEDUCT_STOCK_FUNCTION / REDUCE_INVENTORY / INVENTORY_DEDUCTION]
   Removes stock with smart box-to-pcs conversion.
============================================================ */
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
        /* ============================================================
           [SEARCH TAG: BOX_TO_PIECES_RATIO / 10_PCS_PER_BOX / STOCK_CONVERSION_RATE / PCS_PER_BOX / BOX_CONVERSION]
           Ratio: 1 Box = 10 Pieces. Change this constant multiplier if box capacity changes.
        ============================================================ */
        $totalAvailablePcs = $pcs + ($box * 10);

        if ($qty > $totalAvailablePcs) {
            throw new Exception("Not enough stock. Only {$totalAvailablePcs} pcs available.");
        }

        $pcs -= $qty;

        // If pcs go negative, convert boxes to cover the deficit (1 box = 10 pcs)
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

/* ============================================================
   [SEARCH TAG: ADD_STOCK / ADD_STOCK_FUNCTION / INCREASE_INVENTORY / RESTOCK_INVENTORY]
============================================================ */
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