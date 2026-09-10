<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_5_1_0(Ps_Facetedsearch $module)
{
    // The tables were created with `DEFAULT CHARSET=utf8`, which MySQL resolves to the three-byte
    // utf8mb3. A four-byte character - an emoji in a facet's meta title or URL name - does not fit
    // such a column, so the write is refused or mangled. `CONVERT TO CHARACTER SET` is the only form
    // that rewrites the existing columns: `ALTER DATABASE` and `ALTER TABLE ... CHARSET=` change the
    // default for what is created afterwards and leave the current columns untouched.
    //
    // No COLLATE is given, so an upgraded install ends up with exactly what install() produces on
    // the same server.
    $tables = [
        'layered_category',
        'layered_filter',
        'layered_filter_block',
        'layered_filter_shop',
        'layered_indexable_attribute_group',
        'layered_indexable_attribute_group_lang_value',
        'layered_indexable_attribute_lang_value',
        'layered_indexable_feature',
        'layered_indexable_feature_lang_value',
        'layered_indexable_feature_value_lang_value',
        'layered_price_index',
        'layered_product_attribute',
    ];

    $db = Db::getInstance();

    foreach ($tables as $table) {
        $name = _DB_PREFIX_ . $table;

        // An install that predates one of these tables must not fail the whole upgrade.
        if (!$db->executeS('SHOW TABLES LIKE "' . pSQL($name) . '"')) {
            continue;
        }

        $db->execute('ALTER TABLE `' . pSQL($name) . '` CONVERT TO CHARACTER SET utf8mb4');
    }

    return true;
}
