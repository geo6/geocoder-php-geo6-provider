<?php

declare(strict_types=1);

/*
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Geocoder\Provider\Geo6\Tests;

use Geocoder\IntegrationTest\BaseTestCase;
use Geocoder\Provider\Geo6\Geo6;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;

class Geo6Test extends BaseTestCase
{
    protected function getCacheDir()
    {
        return __DIR__.'/.cached_responses';
    }

    /**
     * @expectedException \Geocoder\Exception\UnsupportedOperation
     * @expectedExceptionMessage The Geo-6 provider does not support IP addresses, only street addresses.
     */
    public function testGeocodeWithLocalhostIPv4()
    {
        $provider = new Geo6($this->getMockedHttpClient(), '', '');
        $provider->geocodeQuery(GeocodeQuery::create('127.0.0.1'));
    }

    /**
     * @expectedException \Geocoder\Exception\UnsupportedOperation
     * @expectedExceptionMessage The Geo-6 provider does not support IP addresses, only street addresses.
     */
    public function testGeocodeWithLocalhostIPv6()
    {
        $provider = new Geo6($this->getMockedHttpClient(), '', '');
        $provider->geocodeQuery(GeocodeQuery::create('::1'));
    }

    /**
     * @expectedException \Geocoder\Exception\UnsupportedOperation
     * @expectedExceptionMessage The Geo-6 provider does not support IP addresses, only street addresses.
     */
    public function testGeocodeWithRealIPv6()
    {
        $provider = new Geo6($this->getMockedHttpClient(), '', '');
        $provider->geocodeQuery(GeocodeQuery::create('::ffff:88.188.221.14'));
    }

    public function testReverseQuery()
    {
        if (!isset($_SERVER['GEO6_CUSTOMER_ID']) || !isset($_SERVER['GEO6_API_KEY'])) {
            $this->markTestSkipped('You need to configure the GEO6_CUSTOMER_ID and GEO6_API_KEY value in phpunit.xml.dist');
        }

        $provider = new Geo6($this->getHttpClient(), $_SERVER['GEO6_CUSTOMER_ID'], $_SERVER['GEO6_API_KEY']);

        $query = ReverseQuery::fromCoordinates(50.841973, 4.362288)
            ->withLocale('fr');

        $results = $provider->reverseQuery($query);

        $this->assertInstanceOf('Geocoder\Model\AddressCollection', $results);
        $this->assertCount(1, $results);

        /** @var \Geocoder\Model\Address $result */
        $result = $results->first();
        $this->assertInstanceOf('\Geocoder\Model\Address', $result);
        $this->assertEquals(50.841973, $result->getCoordinates()->getLatitude(), '', 0.00001);
        $this->assertEquals(4.362288, $result->getCoordinates()->getLongitude(), '', 0.00001);
        $this->assertEquals('1000', $result->getPostalCode());
        $this->assertEquals('BRUXELLES', $result->getLocality());
    }

    public function testReverseQueryWithRadius()
    {
        if (!isset($_SERVER['GEO6_CUSTOMER_ID']) || !isset($_SERVER['GEO6_API_KEY'])) {
            $this->markTestSkipped('You need to configure the GEO6_CUSTOMER_ID and GEO6_API_KEY value in phpunit.xml.dist');
        }

        $provider = new Geo6($this->getHttpClient(), $_SERVER['GEO6_CUSTOMER_ID'], $_SERVER['GEO6_API_KEY']);

        $query = ReverseQuery::fromCoordinates(50.841973, 4.362288)
            ->withLocale('fr')
            ->withData('radius', 100);

        $results = $provider->reverseQuery($query);

        $this->assertInstanceOf('Geocoder\Model\AddressCollection', $results);
        $this->assertCount(1, $results);

        /** @var \Geocoder\Model\Address $result */
        $result = $results->first();
        $this->assertInstanceOf('\Geocoder\Model\Address', $result);
        $this->assertEquals(50.841973, $result->getCoordinates()->getLatitude(), '', 0.00001);
        $this->assertEquals(4.362288, $result->getCoordinates()->getLongitude(), '', 0.00001);
        $this->assertEquals('1000', $result->getPostalCode());
        $this->assertEquals('BRUXELLES', $result->getLocality());
    }

    public function testGeocodeQuery()
    {
        if (!isset($_SERVER['GEO6_CUSTOMER_ID']) || !isset($_SERVER['GEO6_API_KEY'])) {
            $this->markTestSkipped('You need to configure the GEO6_CUSTOMER_ID and GEO6_API_KEY value in phpunit.xml.dist');
        }

        $provider = new Geo6($this->getHttpClient(), $_SERVER['GEO6_CUSTOMER_ID'], $_SERVER['GEO6_API_KEY']);

        $query = GeocodeQuery::create('1 Place des Palais 1000 Bruxelles')
            ->withLocale('fr')
            ->withData('streetName', 'Place des Palais')
            ->withData('streetNumber', '1')
            ->withData('postalCode', '1000')
            ->withData('locality', 'Bruxelles');

        $results = $provider->geocodeQuery($query);

        $this->assertInstanceOf('Geocoder\Model\AddressCollection', $results);
        $this->assertCount(1, $results);

        /** @var \Geocoder\Model\Address $result */
        $result = $results->first();
        $this->assertInstanceOf('\Geocoder\Model\Address', $result);
        $this->assertEquals(50.841973, $result->getCoordinates()->getLatitude(), '', 0.00001);
        $this->assertEquals(4.362288, $result->getCoordinates()->getLongitude(), '', 0.00001);
        $this->assertEquals('1', $result->getStreetNumber());
        $this->assertEquals('Place des Palais', $result->getStreetName());
        $this->assertEquals('1000', $result->getPostalCode());
        $this->assertEquals('BRUXELLES', $result->getLocality());
    }
}
