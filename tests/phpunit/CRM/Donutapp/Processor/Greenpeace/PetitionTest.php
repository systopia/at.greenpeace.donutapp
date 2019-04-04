<?php

use CRM_Donutapp_ExtensionUtil as E;
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
class CRM_Donutapp_Processor_Greenpeace_PetitionTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use \Civi\Test\Api3TestTrait;

  const SUCCESSFUL_AUTH_RESPONSE = '{"access_token": "secret", "token_type": "Bearer", "expires_in": 172800, "scope": "read write"}';
  const PETITION_RESPONSE = '{"count":2,"total_pages":1,"next":null,"previous":null,"results":[{"donor_last_name":"Doe","uploadtime":"2019-01-17T10:20:37.649402Z","newsletter_optin":"1","uid":12345,"donor_house_number":"13","donor_salutation":2,"donor_email":"johndoe@example.com","on_hold_comment":"","campaign_id":51,"agency_id":"","donor_age_in_years":20,"donor_city":"Vienna","donor_zip_code":"1030","fundraiser_name":"Doe, Janet","comments":null,"on_hold":false,"change_note_public":"","donor_date_of_birth":"1999-01-05","donor_country":"AT","welcome_email_status":"sent","donor_first_name":"John","campaign_type":1,"organisation_id":null,"special1":"","campaign_type2":"city_campaign","donor_mobile":"+43 680 1234321","donor_sex":1,"donor_occupation":6,"donor_phone":null,"fundraiser_code":"gpat-1337","contact_by_email":0,"change_note_private":"","special2":"","donor_street":"Landstraße","donor_academic_title":null,"person_id":"12345","pdf":"https://donutapp.mock/api/v1/petitions/pdf/?uid=12345","contact_by_phone":0,"customer_id":532,"createtime":"2019-01-17T10:20:41.966000Z","petition_id":"{PETITION_ID}"},{"donor_last_name":"Doe","uploadtime":"2019-01-17T10:15:53.078149Z","newsletter_optin":null,"uid":76543,"donor_house_number":"33","donor_salutation":2,"donor_email":"lisadoe@example.org","on_hold_comment":"","campaign_id":51,"agency_id":"","donor_age_in_years":25,"donor_city":"Graz","donor_zip_code":"8041","fundraiser_name":"Doe, Janet","comments":null,"on_hold":false,"change_note_public":"","donor_date_of_birth":"1994-03-11","donor_country":"AT","welcome_email_status":"sent","donor_first_name":"Lisa","campaign_type":1,"organisation_id":null,"special1":"","campaign_type2":"city_campaign","donor_mobile":null,"donor_sex":2,"donor_occupation":6,"donor_phone":"+43 664 1234543","fundraiser_code":"gpat-1337","contact_by_email":0,"change_note_private":"","special2":"","donor_street":"Rathausplatz","donor_academic_title":null,"person_id":"34567","pdf":"https://donutapp.io/api/v1/petitions/pdf/?uid=76543","contact_by_phone":0,"customer_id":532,"createtime":"2019-01-17T10:15:47.396000Z","petition_id":"{PETITION_ID}"},{"donor_last_name":"Doe","uploadtime":"2019-01-17T10:15:53.078149Z","newsletter_optin":null,"uid":76542,"donor_house_number":"33","donor_salutation":2,"donor_email":null,"on_hold_comment":"","campaign_id":51,"agency_id":"","donor_age_in_years":25,"donor_city":"Wien","donor_zip_code":"1030","fundraiser_name":"Doe, Janet","comments":null,"on_hold":false,"change_note_public":"","donor_date_of_birth":"1993-03-11","donor_country":"AT","welcome_email_status":"sent","donor_first_name":"Sue","campaign_type":1,"organisation_id":null,"special1":"","campaign_type2":"city_campaign","donor_mobile":null,"donor_sex":2,"donor_occupation":6,"donor_phone":"+43 677 1234543","fundraiser_code":"gpat-1337","contact_by_email":0,"change_note_private":"","special2":"","donor_street":"Rathausplatz","donor_academic_title":null,"person_id":"34568","pdf":"https://donutapp.io/api/v1/petitions/pdf/?uid=76543","contact_by_phone":0,"customer_id":532,"createtime":"2019-01-17T10:15:47.396000Z","petition_id":"{PETITION_ID}"}]}';
  const CONFIRMATION_RESPONSE = '[{"status":"success","message":"","uid":{UID},"confirmation_date":"2019-03-01T18:00:04.592107Z"}]';

  private $petitionID;
  private $activityTypeID;
  private $mailingActivityTypeID;

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->install('de.systopia.xcm')
      ->install('at.greenpeace.gpapi')
      ->apply(TRUE); // recreate every time: ContactType.create seems to bypass TransactionalInterface
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

    $this->setUpFieldsAndData();

    // pretend we're userID 1
    $session = CRM_Core_Session::singleton();
    $session->set('userID', 1);

    $this->setUpXcm();
  }

  private function setUpFieldsAndData() {
    $this->callApiSuccess('OptionValue', 'create', [
      'option_group_id' => 'activity_type',
      'name'            => 'streetimport_error',
      'label'           => 'Import Error',
      'is_active'       => 1
    ]);

    $this->callAPISuccess('ContactType', 'create', [
      'parent_id' => 'Individual',
      'name'      => 'Dialoger',
    ]);

    $this->callAPISuccess('Campaign', 'create', [
      'name'                => 'DD',
      'title'               => 'Direct Dialog',
      'external_identifier' => 'DD',
    ]);

    $this->callAPISuccess('Group', 'create', [
      'title' => 'Community NL',
      'name'  => 'Community_NL',
    ]);

    $this->callAPISuccess('Group', 'create', [
      'title' => 'Donation Info',
      'name'  => 'Donation_Info',
    ]);

    $this->activityTypeID = civicrm_api3('OptionValue', 'getvalue', [
      'return'          => 'value',
      'option_group_id' => 'activity_type',
      'name'            => 'Petition',
    ]);

    $this->mailingActivityTypeID = reset($this->callAPISuccess('OptionValue', 'create', [
      'option_group_id' => 'activity_type',
      'name'            => 'Online_Mailing',
      'label'           => 'Online Mailing',
    ])['values'])['value'];

    $this->callAPISuccess('CustomGroup', 'create', [
      'title'                       => 'Petition Information',
      'name'                        => 'petition_information',
      'extends'                     => 'Activity',
      'extends_entity_column_value' => $this->activityTypeID,
    ]);

    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'petition_information',
      'label'           => 'Dialoger',
      'name'            => 'petition_dialoger',
      'data_type'       => 'ContactReference',
      'html_type'       => 'Autocomplete-Select',
    ]);

    $this->callAPISuccess('CustomGroup', 'create', [
      'title'                       => 'Email Information',
      'name'                        => 'email_information',
      'extends'                     => 'Activity',
      'extends_entity_column_value' => $this->mailingActivityTypeID,
    ]);

    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'email_information',
      'label'           => 'Email',
      'name'            => 'email',
      'data_type'       => 'String',
      'html_type'       => 'Text',
    ]);

    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'email_information',
      'label'           => 'Email Provider',
      'name'            => 'email_provider',
      'data_type'       => 'String',
      'html_type'       => 'Text',
    ]);

    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'email_information',
      'label'           => 'Mailing Subject',
      'name'            => 'mailing_subject',
      'data_type'       => 'String',
      'html_type'       => 'Text',
    ]);

    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'email_information',
      'label'           => 'Mailing Type',
      'name'            => 'mailing_type',
      'data_type'       => 'String',
      'html_type'       => 'Text',
    ]);

    $this->petitionID = $this->callAPISuccess('Survey', 'create', [
      'title'            => 'Save the Whales',
      'activity_type_id' => $this->activityTypeID,
    ])['id'];
  }

  private function setUpXcm() {
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
    $config->store();
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
      'confirm'       => TRUE,
      'limit'         => 100,
    ]);
    $processor->process();
    $contact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'johndoe@example.com',
    ]);
    $this->assertEquals('John', $contact['first_name']);
    $this->assertEquals('Doe', $contact['last_name']);
    // test phone set via donor_mobile
    $this->assertEquals('+43 680 1234321', $contact['phone']);
    $this->assertEquals(3, $contact['prefix_id']);

    $contact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'lisadoe@example.org',
    ]);
    $this->assertEquals('Lisa', $contact['first_name']);
    $this->assertEquals('Doe', $contact['last_name']);
    // test phone set via donor_phone
    $this->assertEquals('+43 664 1234543', $contact['phone']);
    $this->assertEquals(2, $contact['prefix_id']);
  }

  public function testPetitionSignature() {
    CRM_Donutapp_API_Client::setupClient(['handler' => $this->getMockStack()]);
    $processor = new CRM_Donutapp_Processor_Greenpeace_Petition([
      'client_id'     => 'client-id',
      'client_secret' => 'client-secret',
      'confirm'       => TRUE,
      'limit'         => 100,
    ]);
    $processor->process();

    $contact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'johndoe@example.com',
    ]);
    $activity = reset($this->callAPISuccess('Activity', 'get', [
      'target_contact_id' => $contact['id'],
      'campaign_id'       => 'DD',
      'activity_type_id'  => $this->activityTypeID,
    ])['values']);
    $this->assertEquals('Save the Whales', $activity['subject']);
    $this->assertEquals('2019-01-17 10:20:41', $activity['activity_date_time']);
    $dialoger_field = CRM_Core_BAO_CustomField::getCustomFieldID(
      'petition_dialoger',
      'petition_information',
      TRUE
    );
    $this->assertEquals('Doe, Janet', $activity[$dialoger_field]);
  }

  public function testWelcomeEmail() {
    CRM_Donutapp_API_Client::setupClient(['handler' => $this->getMockStack()]);
    $processor = new CRM_Donutapp_Processor_Greenpeace_Petition([
      'client_id'     => 'client-id',
      'client_secret' => 'client-secret',
      'confirm'       => TRUE,
      'limit'         => 100,
    ]);
    $processor->process();

    $contact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'johndoe@example.com',
    ]);

    // find the email action activity
    $activity = reset($this->callAPISuccess('Activity', 'get', [
      'target_contact_id' => $contact['id'],
      'campaign_id'       => 'DD',
      'activity_type_id'  => $this->mailingActivityTypeID,
    ])['values']);

    // activity_date_time should match signature date
    $this->assertEquals('2019-01-17 10:20:41', $activity['activity_date_time']);

    $email_medium_value = civicrm_api3('OptionValue', 'getvalue', [
      'return'          => 'value',
      'option_group_id' => 'encounter_medium',
      'name'            => 'email',
    ]);
    // encounter_medium should be email
    $this->assertEquals($email_medium_value, $activity['medium_id']);

    $email_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'email',
      'email_information'
    );
    // email should be stored in custom field
    $this->assertEquals('johndoe@example.com', $activity[$email_field]);
  }

  public function testNewsletter() {
    CRM_Donutapp_API_Client::setupClient(['handler' => $this->getMockStack()]);
    $processor = new CRM_Donutapp_Processor_Greenpeace_Petition([
      'client_id'     => 'client-id',
      'client_secret' => 'client-secret',
      'confirm'       => TRUE,
      'limit'         => 100,
    ]);
    $processor->process();

    $newsletter_group = civicrm_api3('Group', 'getvalue', [
      'title'  => 'Community NL',
      'return' => 'id',
    ]);

    // contact with email
    $contact_id = $this->callAPISuccess('Contact', 'getvalue', [
      'email'  => 'johndoe@example.com',
      'return' => 'id',
    ]);
    $result = civicrm_api3('GroupContact', 'get', [
      'group_id'   => $newsletter_group,
      'contact_id' => $contact_id,
    ]);
    // ... should have newsletter group
    $this->assertEquals(1, $result['count']);

    // contact without email
    $contact_id = $this->callAPISuccess('Contact', 'getvalue', [
      'first_name' => 'Sue',
      'last_name'  => 'Doe',
      'return'     => 'id',
    ]);
    $result = civicrm_api3('GroupContact', 'get', [
      'group_id'   => $newsletter_group,
      'contact_id' => $contact_id,
    ]);
    // ... should not have newsletter group
    $this->assertEquals(0, $result['count']);
  }

}
