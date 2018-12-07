<?php

declare(strict_types=1);

/*
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Geocoder\Provider\Geo6;

use Geocoder\Collection;
use Geocoder\Exception\InvalidArgument;
use Geocoder\Exception\InvalidServerResponse;
use Geocoder\Exception\UnsupportedOperation;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Model\Address;
use Geocoder\Model\AddressBuilder;
use Geocoder\Model\AddressCollection;
use Geocoder\Model\Country;
use Geocoder\Provider\Provider;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Http\Client\HttpClient;

/**
 * @author Jonathan Beliën <jbe@geo6.be>
 */
final class Geo6 extends AbstractHttpProvider implements Provider
{
    const GEOCODE_ENDPOINT_URL = 'https://api-v2.geo6.be/';

    /**
     * @var string
     */
    private $clientId;

    /**
     * @var string|null
     */
    private $privateKey;

    /**
     * @param HttpClient $client an HTTP adapter
     */
    public function __construct(HttpClient $client, string $clientId, string $privateKey)
    {
        $this->clientId = $clientId;
        $this->privateKey = $privateKey;

        parent::__construct($client);
    }

    /**
     * {@inheritdoc}
     */
    public function geocodeQuery(GeocodeQuery $query): Collection
    {
        $address = $query->getText();

        $streetNumber = $query->getData('streetNumber') ?? '';
        $streetName = $query->getData('streetName');
        $postalCode = $query->getData('postalCode');
        $locality = $query->getData('locality');

        // This API does not support IP
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            throw new UnsupportedOperation('The Geo-6 provider does not support IP addresses, only street addresses.');
        }

        // Save a request if no valid address entered
        if (empty($address) && empty($streetName)) {
            throw new InvalidArgument('Address or Streetname cannot be empty.');
        }

        $language = '';
        if (!is_null($query->getLocale()) && preg_match('/^(fr|nl).*$/', $query->getLocale(), $matches) === 1) {
            $language = $matches[1];
        }

        $url = rtrim(self::GEOCODE_ENDPOINT_URL, '/');
        if (!is_null($postalCode) && !is_null($locality)) {
            $url = sprintf($url.'/geocode/getAddressList/%s/%s/%s/%s', rawurlencode($locality), rawurlencode($postalCode), rawurlencode($streetName), rawurlencode($streetNumber));
        } elseif (!is_null($postalCode) || !is_null($locality)) {
            $url = sprintf($url.'/geocode/getAddressList/%s/%s/%s', rawurlencode($postalCode ?? $locality), rawurlencode($streetName), rawurlencode($streetNumber));
        } elseif (!is_null($streetName)) {
            $url = sprintf($url.'/geocode/getAddressList/%s/%s', rawurlencode($streetName), rawurlencode($streetNumber));
        } else {
            $url = sprintf($url.'/geocode/getAddressList/%s', rawurlencode($address));
        }
        $json = $this->executeQuery($url);

        // no result
        if (empty($json->features)) {
            return new AddressCollection([]);
        }

        $results = [];
        foreach ($json->features as $feature) {
            $components_fr = self::extractComponents($feature->properties->components, 'fr');
            $components_nl = self::extractComponents($feature->properties->components, 'nl');

            $coordinates = $feature->geometry->coordinates;

            $address_fr = self::buildAddress($this->getName(), $components_fr, $coordinates);
            $address_nl = self::buildAddress($this->getName(), $components_nl, $coordinates);

            switch ($language) {
                case 'fr':
                    $results[] = $address_fr ?? $address_nl;
                    break;

                case 'nl':
                    $results[] = $address_nl ?? $address_fr;
                    break;

                default:
                    if (!is_null($address_fr)) {
                        $results[] = $address_fr;
                    }
                    if (!is_null($address_nl)) {
                        $results[] = $address_nl;
                    }
                    break;
            }
        }

        return new AddressCollection($results);
    }

    /**
     * {@inheritdoc}
     */
    public function reverseQuery(ReverseQuery $query): Collection
    {
        $language = '';
        if (!is_null($query->getLocale()) && preg_match('/^(fr|nl).*$/', $query->getLocale(), $matches) === 1) {
            $language = $matches[1];
        }

        $coordinates = $query->getCoordinates();

        $longitude = $coordinates->getLongitude();
        $latitude = $coordinates->getLatitude();

        $url = rtrim(self::GEOCODE_ENDPOINT_URL, '/');
        $url = sprintf($url.'/latlng/%s/%s', floatval($latitude), floatval($longitude));

        $json = $this->executeQuery($url);

        // no result
        if (empty($json->features)) {
            return new AddressCollection([]);
        }

        $results = [];

        $coordinates = [
            $json->query->longitude,
            $json->query->latitude,
        ];

        foreach ($json->features as $feature) {
            $components_fr = self::extractComponents($feature->properties->components, 'fr');
            $components_nl = self::extractComponents($feature->properties->components, 'nl');

            $address_fr = self::buildAddress($this->getName(), $components_fr, $coordinates);
            $address_nl = self::buildAddress($this->getName(), $components_nl, $coordinates);

            switch ($language) {
                case 'fr':
                    $results[] = !is_null($address_fr) ? $address_fr : $address_nl;
                    break;

                case 'nl':
                    $results[] = !is_null($address_nl) ? $address_nl : $address_fr;
                    break;

                default:
                    if (!is_null($address_fr)) {
                        $results[] = $address_fr;
                    }
                    if (!is_null($address_nl)) {
                        $results[] = $address_nl;
                    }
                    break;
            }
        }

        return new AddressCollection($results);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'geo6';
    }

    /**
     * @param string $url
     *
     * @return \stdClass
     */
    private function executeQuery(string $url): \stdClass
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (substr($path, 0, 23) === '/geocode/getAddressList') {
            $token = $this->getToken('/geocode/getAddressList');
        } elseif (substr($path, 0, 7) === '/latlng') {
            $token = $this->getToken('/latlng');
        } else {
            throw new UnsupportedOperation('The Geo-6 provider does not support this query.');
        }

        $request = $this->getRequest($url);

        $request = $request->withHeader('Referer', 'http'.(!empty($_SERVER['HTTPS']) ? 's' : '').'://'.($_SERVER['SERVER_NAME'] ?? 'localhost').'/');
        $request = $request->withHeader('X-Geo6-Consumer', $this->clientId);
        $request = $request->withHeader('X-Geo6-Timestamp', (string) $token['time']);
        $request = $request->withHeader('X-Geo6-Token', $token['token']);

        $body = $this->getParsedResponse($request);

        $json = json_decode($body);
        // API error
        if (!isset($json)) {
            throw InvalidServerResponse::create($url);
        }

        return $json;
    }

    /**
     * Generate token needed to query API.
     *
     * @param string $path
     *
     * @return array
     */
    private function getToken(string $path) : array
    {
        $time = time();

        $t = $this->clientId.'__';
        $t .= $time.'__';
        $t .= parse_url(self::GEOCODE_ENDPOINT_URL, PHP_URL_HOST).'__';
        $t .= 'GET'.'__';
        $t .= $path;

        $token = crypt($t, '$6$'.$this->privateKey.'$');

        return [
            'time'  => $time,
            'token' => $token,
        ];
    }

    /**
     * Extract address components in French or Dutch.
     *
     * @param array $components
     * @param string $language
     *
     * @return array
     */
    private static function extractComponents(array $components, string $language) : array
    {
        if (in_array($language, ['fr', 'nl'])) {
            foreach ($components as $component) {
                switch ($component->type) {
                    case 'country':
                        $country = $component->{'name_'.$language};
                        $countryCode = $component->id;
                        break;
                    case 'locality':
                        $locality = $component->{'name_'.$language};
                        break;
                    case 'municipality':
                        $municipality = $component->{'name_'.$language};
                        break;
                    case 'postal_code':
                        $postalCode = (string) $component->id;
                        break;
                    case 'province':
                        $province = $component->{'name_'.$language};
                        break;
                    case 'region':
                        $region = $component->{'name_'.$language};
                        break;
                    case 'street':
                        $street = $component->{'name_'.$language};
                        break;
                    case 'street_number':
                        $streetNumber = (string) $component->{'name_'.$language};
                        break;
                }
            }
        }

        return [
            'country' => $country ?? null,
            'countrycode' => $countryCode ?? null,
            'locality' => $locality ?? null,
            'municipality' => $municipality ?? null,
            'postalcode' => $postalCode ?? null,
            'province' => $province ?? null,
            'region' => $region ?? null,
            'street' => $street ?? null,
            'streetnumber' => $streetNumber ?? null,
        ];
    }

    /**
     * Create Address from components
     *
     * @param string $provider
     * @param array $components
     * @param array $coordinates
     *
     * @return Address
     */
    private static function buildAddress(string $provider, array $components, array $coordinates) : Address
    {
        $country = $components['country'];
        $countryCode = $components['countrycode'];
        $locality = $components['locality'];
        $municipality = $components['municipality'];
        $postalCode = $components['postalcode'];
        $province = $components['province'];
        $region = $components['region'];
        $streetName = $components['street'];
        $streetNumber = $components['streetnumber'];

        $builder = new AddressBuilder($provider);
        $builder->setCoordinates($coordinates[1], $coordinates[0])
            ->setStreetNumber($streetNumber)
            ->setStreetName($streetName)
            ->setLocality($municipality)
            ->setPostalCode($postalCode)
            ->setSubLocality($locality)
            ->setCountry($country)
            ->setCountryCode($countryCode);

        if (isset($region) && !is_null($region)) {
            $builder->addAdminLevel(1, $region);
        }
        if (isset($province) && !is_null($province)) {
            $builder->addAdminLevel(2, $province);
        }
        $builder->addAdminLevel(3, $municipality);

        return $builder->build();
    }
}
