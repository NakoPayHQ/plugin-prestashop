<?php
/**
 * Custom DB table wrapper. One row per invoice we open with NakoPay.
 * Schema-versioned via PrestaShop Configuration.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NakoPayOrders
{
    const DB_VERSION = '1';
    const OPTION_KEY = 'NAKOPAY_DB_VERSION';

    public static function tableName()
    {
        return _DB_PREFIX_ . 'nakopay_orders';
    }

    public static function install()
    {
        $table = self::tableName();
        $sql   = 'CREATE TABLE IF NOT EXISTS `' . $table . '` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_cart` INT UNSIGNED NOT NULL,
            `id_order` INT UNSIGNED NULL,
            `nakopay_invoice_id` VARCHAR(64) NOT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT "pending",
            `amount_fiat` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `currency` VARCHAR(8) NOT NULL DEFAULT "USD",
            `amount_crypto` DECIMAL(24,8) NULL,
            `crypto_code` VARCHAR(8) NULL,
            `address` VARCHAR(128) NULL,
            `payment_uri` TEXT NULL,
            `tx_hash` VARCHAR(128) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_invoice` (`nakopay_invoice_id`),
            KEY `idx_cart` (`id_cart`),
            KEY `idx_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
        $ok = Db::getInstance()->execute($sql);
        if ($ok) {
            Configuration::updateValue(self::OPTION_KEY, self::DB_VERSION);
        }
        return (bool) $ok;
    }

    public static function save(array $row)
    {
        $row['updated_at'] = date('Y-m-d H:i:s');
        $existing = (int) Db::getInstance()->getValue(
            'SELECT id FROM `' . self::tableName() . '` WHERE nakopay_invoice_id = "' . pSQL($row['nakopay_invoice_id']) . '" LIMIT 1'
        );
        if ($existing > 0) {
            return Db::getInstance()->update('nakopay_orders', $row, 'id = ' . $existing);
        }
        $row['created_at'] = isset($row['created_at']) ? $row['created_at'] : date('Y-m-d H:i:s');
        return Db::getInstance()->insert('nakopay_orders', $row);
    }

    public static function findByInvoice($invoice_id)
    {
        $sql = 'SELECT * FROM `' . self::tableName() . '` WHERE nakopay_invoice_id = "' . pSQL($invoice_id) . '" LIMIT 1';
        $row = Db::getInstance()->getRow($sql);
        return $row ? $row : null;
    }

    public static function findOpenForCart($id_cart)
    {
        $sql = 'SELECT * FROM `' . self::tableName() . '`
                WHERE id_cart = ' . (int) $id_cart . '
                  AND status NOT IN ("paid","completed","expired","cancelled")
                ORDER BY id DESC LIMIT 1';
        $row = Db::getInstance()->getRow($sql);
        return $row ? $row : null;
    }

    public static function updateStatus($invoice_id, $status, $tx = null)
    {
        $data = ['status' => pSQL($status), 'updated_at' => date('Y-m-d H:i:s')];
        if ($tx !== null && $tx !== '') {
            $data['tx_hash'] = pSQL($tx);
        }
        return Db::getInstance()->update(
            'nakopay_orders',
            $data,
            'nakopay_invoice_id = "' . pSQL($invoice_id) . '"'
        );
    }

    public static function attachOrder($invoice_id, $id_order)
    {
        return Db::getInstance()->update(
            'nakopay_orders',
            ['id_order' => (int) $id_order, 'updated_at' => date('Y-m-d H:i:s')],
            'nakopay_invoice_id = "' . pSQL($invoice_id) . '"'
        );
    }
}
