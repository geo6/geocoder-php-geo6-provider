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
use Geocoder\Exception\InvalidCredentials;
use Geocoder\Exception\InvalidServerResponse;
use Geocoder\Exception\QuotaExceeded;
use Geocoder\Exception\UnsupportedOperation;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Model\Address;
use Geocoder\Model\AddressCollection;
use Geocoder\Provider\Provider;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Http\Client\HttpClient;

/**
 * @author Jonathan Beliën <jbe@geo6.be>
 */
final class Geo6 extends AbstractHttpProvider implements Provider
{
    const GEOCODE_ENDPOINT_URL = 'https://api.geo6.be/';

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

    private function getGeocodeEndpointUrl(): string
    {
        $url = rtrim(self::GEOCODE_ENDPOINT_URL, '/');

        return $url.'/geocode/getAddressList/%s/%s/%s';
    }

    /**
     * {@inheritdoc}
     */
    public function geocodeQuery(GeocodeQuery $query): Collection
    {
        $address = $query->getText();

        $streetName = $query->getData('streetName');
        $streetNumber = $query->getData('streetNumber');
        $postalCode = $query->getData('postalCode') ?? null;
        $locality = $query->getData('locality') ?? null;

        // This API does not support IP
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            throw new UnsupportedOperation('The Geo-6 provider does not support IP addresses, only street addresses.');
        }

        // Save a request if no valid address entered
        if (empty($address) || empty($streetName) || (empty($postalCode) && empty($locality))) {
            throw new InvalidArgument('Address, Street Name, and Locality (or Postal Code) cannot be empty.');
        }

        $language = '';
        if (!is_null($query->getLocale()) && preg_match('/^(fr|nl).*$/', $query->getLocale(), $matches) === 1) {
            $language = $matches[1];
        }

        $url = sprintf($this->getGeocodeEndpointUrl(), urlencode($postalCode ?? $locality), urlencode($streetName), urlencode($streetNumber));
        $json = $this->executeQuery($url);

        // no result
        if (empty($json->features)) {
            return new AddressCollection([]);
        }

        $results = [];
        foreach ($json->features as $feature) {
            $coordinates = $feature->geometry->coordinates;

            foreach ($feature->properties->components as $component) {
                switch ($component->type) {
                    case 'municipality':
                        if ($language === 'nl') {
                            $municipality = $component->name_nl ?? $component->name_fr;
                        } else {
                            $municipality = $component->name_fr ?? $component->name_nl;
                        }
                        break;
                    case 'postal_code':
                        $postalCode = (string) $component->id;
                        break;
                    case 'street':
                        if ($language === 'nl') {
                            $streetName = $component->name_nl ?? $component->name_fr;
                        } else {
                            $streetName = $component->name_fr ?? $component->name_nl;
                        }
                        break;
                    case 'street_number':
                        $streetNumber = (string) $component->name_fr;
                        break;
                }
            }

            $results[] = Address::createFromArray([
                'providedBy'   => $this->getName(),
                'latitude'     => $coordinates[1],
                'longitude'    => $coordinates[0],
                'streetNumber' => $streetNumber,
                'streetName'   => $streetName,
                'locality'     => $municipality,
                'postalCode'   => $postalCode,
                'countryCode'  => 'BE',
            ]);
        }

        return new AddressCollection($results);
    }

    /**
     * {@inheritdoc}
     */
    public function reverseQuery(ReverseQuery $query): Collection
    {
        // This API does not support reverse geocoding
        throw new UnsupportedOperation('The Geo-6 provider does not support reverse geocoding.');
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
        $content = $this->getUrlContents($url);
        $json = json_decode($content);
        // API error
        if (!isset($json)) {
            throw InvalidServerResponse::create($url);
        }

        return $json;
    }

    /**
     * Get URL and return contents. If content is empty, an exception will be thrown.
     *
     * @param string $url
     *
     * @return string
     *
     * @throws InvalidServerResponse
     */
    protected function getUrlContents(string $url): string
    {
        $token = $this->getToken();

        $request = $this->getMessageFactory()->createRequest('GET', $url, [
            'Referer'          => 'http'.(!empty($_SERVER['HTTPS']) ? 's' : '').'://'.$_SERVER['SERVER_NAME'].'/',
            'X-Geo6-Consumer'  => $this->clientId,
            'X-Geo6-Timestamp' => $token->time,
            'X-Geo6-Token'     => $token->token,
        ]);
        $response = $this->getHttpClient()->sendRequest($request);
        $statusCode = $response->getStatusCode();
        if (401 === $statusCode || 403 === $statusCode) {
            throw new InvalidCredentials();
        } elseif (429 === $statusCode) {
            throw new QuotaExceeded();
        } elseif ($statusCode >= 300) {
            throw InvalidServerResponse::create($url, $statusCode);
        }
        $body = (string) $response->getBody();
        if (empty($body)) {
            throw InvalidServerResponse::emptyResponse($url);
        }
        return $body;
    }


    public function getToken() {
        $time = time();

        $t  = $this->clientId.'__';
        $t .= $time.'__';
        $t .= parse_url(self::GEOCODE_ENDPOINT_URL, PHP_URL_HOST).'__';
        $t .= 'GET'.'__';
        $t .= '/geocode/getAddressList';

        $token = crypt($t, '$6$'.$this->privateKey.'$');

        return (object) [
            'time'     => $time,
            'token'    => $token,
        ];
    }
}
