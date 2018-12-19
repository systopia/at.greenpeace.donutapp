<?php

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

class CRM_Donutapp_API_Client {
  const API_ENDPOINT = 'https://donutapp.io/api/v1/';
  const OAUTH2_ENDPOINT = 'https://donutapp.io/o/token/?grant_type=client_credentials';

  static $apiEndpoint = self::API_ENDPOINT;
  static $oauth2Endpoint = self::OAUTH2_ENDPOINT;
  static $clientId = NULL;
  static $clientSecret = NULL;
  static $accessToken = NULL;
  static $userAgent = NULL;

  /**
   * @var Client
   */
  static $client = NULL;

  public static function setAPIEndpoint($apiEndpoint) {
    self::$apiEndpoint = $apiEndpoint;
  }

  public static function setOAuth2Endpoint($oauth2Endpoint) {
    self::$oauth2Endpoint = $oauth2Endpoint;
  }

  public static function setClientId($clientId) {
    self::$clientId = $clientId;
  }

  public static function setClientSecret($clientSecret) {
    self::$clientSecret = $clientSecret;
  }

  public static function getUserAgent() {
    if (is_null(self::$userAgent)) {
      $version = civicrm_api3('Extension', 'getvalue', [
        'return' => 'version',
        'full_name' => CRM_Donutapp_ExtensionUtil::LONG_NAME,
      ]);
      self::$userAgent = CRM_Donutapp_ExtensionUtil::LONG_NAME . '/' . $version;
    }
    return self::$userAgent;
  }

  public static function setupClient() {
    self::$client = new Client([
      'base_uri' => self::$apiEndpoint,
      'timeout'  => 60,
    ]);
  }

  public static function getAccessToken() {
    try {
      $client = new Client([
        'base_uri' => self::$oauth2Endpoint,
        'timeout'  => 60,
        'request.options' => [
          'headers' => [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'User-Agent'    => self::getUserAgent(),
          ],
        ],
        'auth' => [self::$clientId, self::$clientSecret, 'Basic'],
      ]);
      $response = $client->post('');
      $authResponse = json_decode($response->getBody());
      self::$accessToken = $authResponse->access_token;
      self::setupClient();
    } catch (GuzzleHttp\Exception\ClientException $e) {
      throw new CRM_Donutapp_API_Error_Authentication($e->getMessage());
    } catch (GuzzleHttp\Exception\BadResponseException $e) {
      throw new CRM_Donutapp_API_Error_BadResponse($e->getMessage());
    }
  }

  public static function sanitizeEndpoint($endpoint) {
    if (substr($endpoint, 0, 1) == '/') {
      $endpoint = substr($endpoint, 1);
    }
    if (substr($endpoint, -1) != '/') {
      $endpoint = $endpoint . '/';
    }
    return $endpoint;
  }

  public static function buildUri($endpoint, array $params = NULL) {
    $uri = self::sanitizeEndpoint($endpoint);
    if (!is_null($params)) {
      $uri .= '?' . http_build_query($params);
    }
    return $uri;
  }

  public static function bootstrap() {
    if (is_null(self::$accessToken)) {
      self::getAccessToken();
    }
  }

  public static function sendRequest($method, $uri, $body = NULL) {
    $request = new Request(
      $method,
      $uri,
      [
        'Authorization' => 'Bearer ' . self::$accessToken,
        'Accept'        => 'application/json',
        'Content-Type'  => 'application/json',
        'User-Agent'    => self::getUserAgent(),
      ],
      $body
    );
    if (defined('CIVICRM_DONUTAPP_LOGGING') && CIVICRM_DONUTAPP_LOGGING) {
      CRM_Core_Error::debug_log_message(
        'Donutapp API Request: ' . $request->getMethod() . ' ' . $request->getUri()
      );
    }
    try {
      $response = self::$client->send($request);
      $body = $response->getBody();
      if (defined('CIVICRM_DONUTAPP_LOGGING') && CIVICRM_DONUTAPP_LOGGING) {
        CRM_Core_Error::debug_log_message(
          'Donutapp API Response: ' . $body
        );
      }
      return json_decode($body);
    } catch (GuzzleHttp\Exception\ClientException $e) {
      throw new CRM_Donutapp_API_Error_Authentication($e->getMessage());
    } catch (GuzzleHttp\Exception\BadResponseException $e) {
      throw new CRM_Donutapp_API_Error_BadResponse($e->getMessage());
    }
  }

  public static function get($uri, array $params = NULL) {
    self::bootstrap();
    return self::sendRequest('GET', $uri);
  }

  public static function postJSON($uri, array $data) {
    self::bootstrap();
    return self::sendRequest('POST', $uri, json_encode($data));
  }
}