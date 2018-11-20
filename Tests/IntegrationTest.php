<?php

/*
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Geocoder\Provider\Geo6POI\Tests;

use Geocoder\IntegrationTest\ProviderIntegrationTest;
use Geocoder\Provider\Geo6\Geo6;
use Http\Client\HttpClient;

class IntegrationTest extends ProviderIntegrationTest
{
    protected $testAddress = true;

    protected $testReverse = false;

    protected $testIpv4 = false;

    protected $testIpv6 = false;

    protected $skippedTests = [
        'testGeocodeQuery'              => 'Belgium only.',
        'testGeocodeQueryWithNoResults' => 'Does not allow geocode query without data.',
        'testExceptions'                => 'Does not allow geocode query without data.',
    ];

    protected function createProvider(HttpClient $httpClient)
    {
        return new Geo6($httpClient, $this->getCustomerId(), $this->getApiKey());
    }

    protected function getCacheDir()
    {
        return __DIR__.'/.cached_responses';
    }

    protected function getApiKey()
    {
        return $_SERVER['GEO6_API_KEY'];
    }

    protected function getCustomerId()
    {
        return $_SERVER['GEO6_CUSTOMER_ID'];
    }
}
