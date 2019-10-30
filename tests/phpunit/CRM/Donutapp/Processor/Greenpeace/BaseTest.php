<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Test petition import
 *
 * @group headless
 */
abstract class CRM_Donutapp_Processor_Greenpeace_BaseTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use \Civi\Test\Api3TestTrait;

  public function setUp() {
    parent::setUp();
    // test dates against UTC
    date_default_timezone_set('UTC');

    // use mock URLs for DonutApp API
    CRM_Donutapp_API_Client::setAPIEndpoint('https://donutapp.mock/api/v1/');
    CRM_Donutapp_API_Client::setOAuth2Endpoint('https://donutapp.mock/o/token/?grant_type=client_credentials');

    // pretend we're userID 1
    $session = CRM_Core_Session::singleton();
    $session->set('userID', 1);

    $this->setUpXcm();
  }

  private function setUpXcm() {
    $profiles = CRM_Xcm_Configuration::getProfileList();
    if (!array_key_exists('engagement', $profiles)) {
      $config = CRM_Xcm_Configuration::getConfigProfile();
      $options = $config->getOptions();
      $options['default_location_type'] = $this->callAPISuccess(
        'LocationType',
        'get',
        ['is_default' => 1]
      );
      $options['fill_details'] = ['email', 'phone'];
      $options['fill_details_primary'] = 1;
      $config->setOptions($options);
      $config->cloneProfile('engagement');
    }
  }

  public function tearDown() {
    parent::tearDown();
  }

  protected function getLastImportError() {
    return reset(civicrm_api3('Activity', 'get', [
      'activity_type_id' => 'streetimport_error',
      'options'          => [
        'limit' => 1,
        'sort'  => 'activity_date_time DESC'
      ],
    ])['values']);
  }

}
