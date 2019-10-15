<?php

use CRM_Donutapp_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test donation/contract import
 *
 * @group headless
 */
class CRM_Donutapp_Processor_Greenpeace_DonationTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  const DONATION_RESPONSE = '{"donor_last_name":"Doe","uploadtime":"2019-09-17T11:06:41.577889Z","newsletter_optin":null,"uid":{UID}},"donor_house_number":null,"donor_salutation":1,"donor_email":"jdo@example.com","on_hold_comment":"","campaign_id":115,"agency_id":null,"donor_age_in_years":67,"donor_city":null,"donor_zip_code":null,"fundraiser_name":"Doe, Sue","comments":null,"on_hold":false,"change_note_public":"","donor_date_of_birth":"1952-04-14","donor_country":"AT","welcome_email_status":"sent","donor_first_name":"Jon","campaign_type":1,"organisation_id":null,"special1":"","campaign_type2":null,"donor_mobile":null,"donor_sex":1,"donor_occupation":6,"donor_phone":null,"fundraiser_code":"gpat-1337","contact_by_email":0,"change_note_private":"","special2":"","donor_street":null,"donor_academic_title":null,"person_id":"12345","pdf":"https://donutapp.mock/api/v1/petitions/pdf/?uid=12345","contact_by_phone":0,"customer_id":42,"createtime":"2019-09-17T11:06:43.046000Z","petition_id":"22"}';

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->install('org.project60.sepa')
      ->install('org.project60.banking')
      ->apply();
  }

  public function setUp() {
    parent::setUp();
    // test dates against UTC
    date_default_timezone_set('UTC');

    // use mock URLs for DonutApp API
    CRM_Donutapp_API_Client::setAPIEndpoint('https://donutapp.mock/api/v1/');
    CRM_Donutapp_API_Client::setOAuth2Endpoint('https://donutapp.mock/o/token/?grant_type=client_credentials');

    // mock authentication
    $mock = new MockHandler([
      new Response(200, [], self::SUCCESSFUL_AUTH_RESPONSE),
    ]);
    $stack = HandlerStack::create($mock);
    CRM_Donutapp_API_Client::setupOauth2Client(['handler' => $stack]);

    // fetch the test creditor
    $creditor_id = $this->callAPISuccess('SepaCreditor', 'getvalue', [
      'return'  => 'id',
      'options' => [
        'limit' => 1
      ],
    ]);
    // make sure the test creditor has a creditor_type and currency
    // (they're not set in org.project60.sepa's db seed)
    $this->callAPISuccess('SepaCreditor', 'create', [
      'id'            => $creditor_id,
      'creditor_type' => 'SEPA',
      'currency'      => 'EUR',
    ]);
    // make the creditor the default
    CRM_Sepa_Logic_Settings::setSetting(
      'batching_default_creditor',
      civicrm_api3('SepaCreditor', 'getvalue', [
        'return'  => 'id',
        'options' => [
          'limit' => 1
        ],
      ])
    );
  }

  public function tearDown() {
    parent::tearDown();
  }

  private function getMockStack() {
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('{PETITION_ID}', $this->petitionID, self::PETITION_RESPONSE)
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('{UID}', '12345', self::CONFIRMATION_RESPONSE)
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('{UID}', '76543', self::CONFIRMATION_RESPONSE)
      ),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push($history);
    return $stack;
  }

  public function testContactCreation() {
    CRM_Donutapp_API_Client::setupClient(['handler' => $this->getMockStack()]);
    $processor = new CRM_Donutapp_Processor_Greenpeace_Petition([
      'client_id'     => 'client-id',
      'client_secret' => 'client-secret',
      'campaign_id'   => $this->campaignId,
      'confirm'       => TRUE,
      'limit'         => 100,
    ]);
    $processor->process();
    $contact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'johndoe@example.com',
    ]);
  }
}
