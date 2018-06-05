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
use Geocoder\Model\AdminLevelCollection;
use Geocoder\Model\Coordinates;
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

    /**
     * {@inheritdoc}
     */
    public function geocodeQuery(GeocodeQuery $query): Collection
    {
        $address = $query->getText();

        $streetName = $query->getData('streetName');
        $streetNumber = $query->getData('streetNumber') ?? '';
        $postalCode = $query->getData('postalCode') ?? null;
        $locality = $query->getData('locality') ?? null;

        // This API does not support IP
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            throw new UnsupportedOperation('The Geo-6 provider does not support IP addresses, only street addresses.');
        }

        // Save a request if no valid address entered
        if (empty($address) || empty($streetName)) {
            throw new InvalidArgument('Address and streetname cannot be empty.');
        }

        $language = '';
        if (!is_null($query->getLocale()) && preg_match('/^(fr|nl).*$/', $query->getLocale(), $matches) === 1) {
            $language = $matches[1];
        }

        $url = rtrim(self::GEOCODE_ENDPOINT_URL, '/');
        if (!is_null($postalCode) && !is_null($locality)) {
            $url = sprintf($url.'/geocode/getAddressList/%s/%s/%s/%s', urlencode($locality), urlencode($postalCode), urlencode($streetName), urlencode($streetNumber));
        } elseif (!is_null($postalCode) || !is_null($locality)) {
            $url = sprintf($url.'/geocode/getAddressList/%s/%s/%s', urlencode($postalCode ?? $locality), urlencode($streetName), urlencode($streetNumber));
        } else {
            $url = sprintf($url.'/geocode/getAddressList/%s/%s', urlencode($streetName), urlencode($streetNumber));
        }
        $json = $this->executeQuery($url);

        // no result
        if (empty($json->features)) {
            return new AddressCollection([]);
        }

        $results = [];
        foreach ($json->features as $feature) {
            $address_fr = $this->extractComponents($feature, 'fr');
            $address_nl = $this->extractComponents($feature, 'nl');

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
     * @throws InvalidServerResponse
     *
     * @return string
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

    /**
     * Generate token needed to query API.
     *
     * @return object
     */
    private function getToken()
    {
        $time = time();

        $t = $this->clientId.'__';
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

    /**
     * Extract address components in French or Dutch.
     *
     * @param object $feature
     * @param string $language
     */
    private function extractComponents(object $feature, string $language)
    {
        $coordinates = $feature->geometry->coordinates;

        if (in_array($language, ['fr', 'nl'])) {
            foreach ($feature->properties->components as $component) {
                switch ($component->type) {
                    case 'locality':
                        $locality = $component->{'name_'.$language};
                        break;
                    case 'municipality':
                        $municipality = $component->{'name_'.$language};
                        break;
                    case 'postal_code':
                        $postalCode = (string) $component->id;
                        break;
                    case 'street':
                        $streetName = $component->{'name_'.$language};
                        break;
                    case 'street_number':
                        $streetNumber = (string) $component->{'name_'.$language};
                        break;
                }
            }
        }

        if (isset($municipality, $postalCode, $streetName, $streetNumber) &&
            !is_null($municipality) && !is_null($postalCode) && !is_null($streetName)
        ) {
            return new Address(
                $this->getName(),
                new AdminLevelCollection([]),
                new Coordinates($coordinates[1], $coordinates[0]),
                null,
                $streetNumber ?? null,
                $streetName ?? null,
                $postalCode ?? null,
                $municipality ?? null,
                $locality ?? null,
                new Country('Belgium', 'BE'),
                'Europe/Brussels'
            );
        }
    }
}
