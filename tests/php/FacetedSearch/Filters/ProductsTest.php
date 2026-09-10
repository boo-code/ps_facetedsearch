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

namespace PrestaShop\Module\FacetedSearch\Tests\Filters;

use Closure;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PrestaShop\Module\FacetedSearch\Filters\Products;
use PrestaShop\Module\FacetedSearch\Product\Search;
use Product;

class ProductsTest extends MockeryTestCase
{
    /** @var Products */
    private $filters;

    protected function setUp()
    {
        $search = Mockery::mock(Search::class);
        $search->shouldReceive('getSearchAdapter')->andReturn(Mockery::mock('AbstractAdapter'));

        $this->filters = new Products($search);
    }

    /**
     * The indexed envelope of a product. ps_layered_price_index stores both bounds as
     * DECIMAL(20, 6), which reaches PHP as a numeric string, so the fixtures use that shape rather
     * than a convenient int.
     */
    private function product($id, $priceMin, $priceMax)
    {
        return [
            'id_product' => $id,
            'price_min' => number_format($priceMin, 6, '.', ''),
            'price_max' => number_format($priceMax, 6, '.', ''),
        ];
    }

    /**
     * filterPrice() is private, and it takes the product list by reference. A bound closure keeps
     * the reference that ReflectionMethod::invokeArgs() would drop.
     */
    private function filterPrice(array &$products, array $priceFilter)
    {
        $invoke = function (&$list, $filter) {
            $this->filterPrice($list, true, true, $filter);
        };

        Closure::bind($invoke, $this->filters, Products::class)->__invoke($products, $priceFilter);
    }

    /**
     * Pins how many times the real price is asked for, not only which products survive. Without the
     * count, a version that recomputed every product would pass every exclusion assertion below and
     * quietly turn the containment check into dead code.
     */
    private function expectPriceLookups(array $pricesById, $times)
    {
        $productMock = Mockery::namedMock(Product::class);
        $productMock->shouldReceive('getPriceStatic')
            ->times($times)
            ->andReturnUsing(function ($idProduct) use ($pricesById) {
                return $pricesById[$idProduct];
            });
    }

    /**
     * The SQL that selected the product uses inclusive bounds (price_min <= max), so a product whose
     * indexed price_min lands exactly on the requested upper bound is selected. It has to be
     * re-checked like any other product sticking out of the range.
     */
    public function testDropsProductWhoseIndexedMinimumEqualsTheUpperBound()
    {
        $products = [$this->product(1, 30, 75)];
        $this->expectPriceLookups([1 => 75.0], 1);

        $this->filterPrice($products, ['min' => 3.0, 'max' => 30.0]);

        $this->assertSame([], $products);
    }

    /**
     * The mirror of the case above, on the lower bound: price_max == min.
     */
    public function testDropsProductWhoseIndexedMaximumEqualsTheLowerBound()
    {
        $products = [$this->product(1, 10, 30)];
        $this->expectPriceLookups([1 => 10.0], 1);

        $this->filterPrice($products, ['min' => 30.0, 'max' => 50.0]);

        $this->assertSame([], $products);
    }

    /**
     * The control that keeps the optimisation honest: an envelope entirely inside the requested
     * range cannot produce an out-of-range price, so the product is kept WITHOUT asking for its
     * real price. A fix that simply recomputes everything would still drop the right products
     * above, and only this assertion notices that it stopped being an optimisation.
     */
    public function testKeepsAContainedEnvelopeWithoutComputingItsRealPrice()
    {
        $products = [$this->product(1, 10, 20)];
        $this->expectPriceLookups([], 0);

        $this->filterPrice($products, ['min' => 5.0, 'max' => 25.0]);

        $this->assertSame([$this->product(1, 10, 20)], $products);
    }

    /**
     * An envelope that sticks out is re-checked, and the product stays when its real price is in
     * range - so the re-check is not a blanket exclusion.
     */
    public function testKeepsAStraddlingProductWhoseRealPriceIsInRange()
    {
        $products = [$this->product(1, 5, 40)];
        $this->expectPriceLookups([1 => 20.0], 1);

        $this->filterPrice($products, ['min' => 10.0, 'max' => 30.0]);

        $this->assertSame([$this->product(1, 5, 40)], $products);
    }

    public function testDropsAStraddlingProductWhoseRealPriceIsOutOfRange()
    {
        $products = [$this->product(1, 5, 40)];
        $this->expectPriceLookups([1 => 35.0], 1);

        $this->filterPrice($products, ['min' => 10.0, 'max' => 30.0]);

        $this->assertSame([], $products);
    }

    /**
     * A bound that is not a whole number was truncated before being compared, so a product could be
     * left unchecked against a range narrower than the one the visitor asked for.
     */
    public function testDoesNotTruncateFractionalBounds()
    {
        // The envelope sits inside the range once the lower bound is truncated to 3, and outside it
        // at the 3.5 the visitor actually asked for. Only the untruncated bound triggers the
        // re-check that drops this product.
        $products = [$this->product(1, 3.2, 20.0)];
        $this->expectPriceLookups([1 => 3.2], 1);

        $this->filterPrice($products, ['min' => 3.5, 'max' => 30.0]);

        $this->assertSame([], $products);
    }
}
