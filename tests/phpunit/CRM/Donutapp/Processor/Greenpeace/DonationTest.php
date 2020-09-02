<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tdely\Luhn\Luhn;

/**
 * Test donation/contract import
 *
 * @todo DRY up code, lots of duplicated fragments from PetitionTest
 *
 * @group headless
 */
class CRM_Donutapp_Processor_Greenpeace_DonationTest extends CRM_Donutapp_Processor_Greenpeace_BaseTest {

  const SUCCESSFUL_AUTH_RESPONSE = '{"access_token": "secret", "token_type": "Bearer", "expires_in": 172800, "scope": "read write"}';
  const DONATION_RESPONSE = '{"count":2,"total_pages":1,"next":null,"previous":null,"results":[{"payment_method":"donut-sepa","on_hold_comment":"","fundraiser_code":"gpat-1337","raisenow_epp_transaction_id":null,"change_note_private":"","bank_account_bic":"","membership_channel":"Kontaktart:F2F","welcome_email_status":"sent","donor_first_name":"Jon","campaign_type":null,"bank_account_was_validated":false,"donor_occupation":4,"donor_phone":null,"donor_company_name":null,"special2":"","special1":"","location":"","donor_city":"Castle Black","donor_last_name":"Snow","organisation_id":null,"donor_salutation":2,"donor_email":"snow@thewatch.example.org","bank_account_bank_name":"","fundraiser_name":"Stark, Benjen","donor_date_of_birth":"1961-11-14","donor_country":"AT","donor_house_number":"1","bank_card_checked":null,"bank_account_holder":"Jon Snow","donor_mobile":"+43664123456","donor_street":"Main Street","donation_amount_annual":"180,00","uploadtime":"2019-10-26T14:56:25.535888Z","uid":12345,"campaign_id":261,"contact_by_email":0,"contract_start_date":"2019-10-26","change_note_public":"","on_hold":false,"donor_sex":2,"interest_group":"Tierfreunde","shirt_type":"","comments":"","person_id":"GT123456","customer_id":158,"direct_debit_interval":12,"membership_type":"Landwirtschaft","contact_by_phone":0,"topic_group":"Wald","agency_id":null,"donor_age_in_years":57,"donor_zip_code":"1234","bank_account_iban":"AT483200000012345864","donor_academic_title":null,"shirt_size":"","pdf":"https://donutapp.mock/api/v1/donations/pdf/?uid=12345","campaign_type2":null,"createtime":"2019-10-29T16:30:24.227000Z"},{"payment_method":"donut-sepa","on_hold_comment":"","fundraiser_code":"gpat-1337","raisenow_epp_transaction_id":null,"change_note_private":"","bank_account_bic":"","membership_channel":"Kontaktart:F2F","welcome_email_status":"sent","donor_first_name":"Jane","campaign_type":null,"bank_account_was_validated":false,"donor_occupation":4,"donor_phone":null,"donor_company_name":null,"special2":"","special1":"","location":"","donor_city":null,"donor_last_name":"Doe","organisation_id":null,"donor_salutation":2,"donor_email":"jadoe@example.org","bank_account_bank_name":"","fundraiser_name":"Some, Person","donor_date_of_birth":"1960-11-14","donor_country":null,"donor_house_number":null,"bank_card_checked":null,"bank_account_holder":"Jane Doe","donor_mobile":"+43660123456","donor_street":null,"donation_amount_annual":"150,00","uploadtime":"2019-10-26T14:56:25.535888Z","uid":54321,"campaign_id":261,"contact_by_email":0,"contract_start_date":"2019-10-26","change_note_public":"","on_hold":false,"donor_sex":1,"interest_group":"Tierfreunde","shirt_type":"","comments":"","person_id":"GT123457","customer_id":158,"direct_debit_interval":12,"membership_type":"Landwirtschaft","contact_by_phone":0,"topic_group":"Wald","agency_id":null,"donor_age_in_years":57,"donor_zip_code":null,"bank_account_iban":"DE75512108001245126199","donor_academic_title":null,"shirt_size":"","external_campaign_id":{EXTERNAL_CAMPAIGN_ID},"external_contact_id":{EXTERNAL_CONTACT_ID},"pdf":"https://donutapp.mock/api/v1/donations/pdf/?uid=54321","campaign_type2":null,"createtime":"2019-10-29T16:30:24.227000Z"}]}';
  const CONFIRMATION_RESPONSE = '[{"status":"success","message":"","uid":{UID},"confirmation_date":"2019-10-30T11:25:12.335209Z"}]';

  private $campaignId;
  private $altCampaignId;
  private $contactId;
  private $mailingActivityTypeID;

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->install('de.systopia.xcm')
      ->install('org.project60.sepa')
      ->install('org.project60.banking')
      ->install('de.systopia.contract')
      ->apply(TRUE);
  }

  /**
   * Setup contract extension and its dependencies
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function setUpContractExtension() {
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
      'uses_bic'      => FALSE,
    ]);
    // make the creditor the default
    CRM_Sepa_Logic_Settings::setSetting(
      civicrm_api3('SepaCreditor', 'getvalue', [
        'return'  => 'id',
        'options' => [
          'limit' => 1
        ],
      ]),
      'batching_default_creditor'
    );
  }

  public function setUp() {
    parent::setUp();
    $this->setUpContractExtension();
    $this->setUpFieldsAndData();
    // mock authentication
    $mock = new MockHandler([
      new Response(200, [], self::SUCCESSFUL_AUTH_RESPONSE),
    ]);
    $stack = HandlerStack::create($mock);
    CRM_Donutapp_API_Client::setupOauth2Client(['handler' => $stack]);
  }

  private function getMockStack() {
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace(
          ['{EXTERNAL_CAMPAIGN_ID}', '{EXTERNAL_CONTACT_ID}'],
          [$this->altCampaignId, Luhn::create($this->contactId)],
          self::DONATION_RESPONSE
        )
      ),
      new Response(
        200,
        ['Content-Type' => 'application/pdf'],
        'test pdf'
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('{UID}', '12345', self::CONFIRMATION_RESPONSE)
      ),
      new Response(
        200,
        ['Content-Type' => 'application/pdf'],
        'test pdf'
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('{UID}', '54321', self::CONFIRMATION_RESPONSE)
      ),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push($history);
    return $stack;
  }

  private function setUpFieldsAndData() {
    $this->callApiSuccess('OptionValue', 'create', [
      'option_group_id' => 'activity_type',
      'name'      => 'streetimport_error',
      'label'     => 'Import Error',
      'is_active' => 1
    ]);

    $this->campaignId = reset($this->callAPISuccess('Campaign', 'create', [
      'name'                => 'DD',
      'title'               => 'Direct Dialog',
      'external_identifier' => 'DD',
    ])['values'])['id'];


    $this->altCampaignId = reset($this->callAPISuccess('Campaign', 'create', [
      'name'                => 'DDTFR',
      'title'               => 'Direct Dialog TFR',
      'external_identifier' => 'DDTFR',
    ])['values'])['id'];

    $this->contactId = reset($this->callAPISuccess('Contact', 'create', [
      'email'        => 'random@example.org',
      'contact_type' => 'Individual',
    ])['values'])['id'];

    $this->callAPISuccess('MembershipType', 'create', [
      'member_of_contact_id' => 1,
      'financial_type_id'    => 'Member Dues',
      'duration_unit'        => 'lifetime',
      'duration_interval'    => 1,
      'period_type'          => 'rolling',
      'name'                 => 'Landwirtschaft',
    ]);

    $this->callAPISuccess('OptionValue', 'create', [
      'option_group_id' => 'contact_channel',
      'value'           => 'F2F',
      'label'           => 'F2F',
    ]);

    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'membership_general',
      'label'           => 'contract_file',
      'data_type'       => 'File',
      'html_type'       => 'File',
    ]);

    $this->callAPISuccess('Group', 'create', [
      'title' => 'Community NL',
    ]);

    $this->callAPISuccess('Group', 'create', [
      'title' => 'Tierfreunde',
    ]);

    $this->callAPISuccess('Group', 'create', [
      'title' => 'Wald',
    ]);

    $this->mailingActivityTypeID = reset($this->callAPISuccess('OptionValue', 'create', [
      'option_group_id' => 'activity_type',
      'name'            => 'Online_Mailing',
      'label'           => 'Online Mailing',
    ])['values'])['value'];

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
  }

  public function testContractCreation() {
    CRM_Donutapp_API_Client::setupClient(['handler' => $this->getMockStack()]);
    $processor = new CRM_Donutapp_Processor_Greenpeace_Donation([
      'client_id'     => 'client-id',
      'client_secret' => 'client-secret',
      'campaign_id'   => $this->campaignId,
      'confirm'       => TRUE,
      'limit'         => 100,
    ]);
    $processor->process();
    $contact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'snow@thewatch.example.org',
    ]);
    $this->assertEquals('Jon', $contact['first_name']);
    $this->assertEquals('Snow', $contact['last_name']);
    $this->assertFalse(
      $this->getLastImportError(),
      'Should not create any import error activities'
    );
    $contract = civicrm_api3('Contract', 'getsingle', [
      'contact_id' => $contact['id'],
    ]);
    $this->assertEquals('2019-10-26', $contract['join_date']);
    $this->assertEquals(date('Y-m-d'), $contract['start_date']);
    $number_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
    'membership_contract',
    'membership_general'
    );
    $this->assertEquals('GT123456', $contract[$number_field]);
    $dialoger_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'membership_dialoger',
      'membership_general'
    );
    $this->assertEquals('Stark, Benjen', $contract[$dialoger_field]);
    $channel_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'membership_channel',
      'membership_general'
    );
    $this->assertEquals('F2F', $contract[$channel_field]);
    $annual_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
        'membership_annual',
        'membership_payment'
      );
    $this->assertEquals('180.00', $contract[$annual_field]);
    $mandate = civicrm_api3('SepaMandate', 'getsingle', [
      'contact_id' => $contact['id'],
    ]);
    $this->assertEquals('civicrm_contribution_recur', $mandate['entity_table']);
    $this->assertEquals(CRM_Sepa_Logic_Settings::getSetting('batching_default_creditor'), $mandate['creditor_id']);
    $this->assertEquals('AT483200000012345864', $mandate['iban']);
    $this->assertEquals('NOTPROVIDED', $mandate['bic']);
    $this->assertEquals('RCUR', $mandate['type']);
    $this->assertEquals('FRST', $mandate['status']);

    $otherContact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'jadoe@example.org',
    ]);
    $this->assertEquals(
      $this->contactId,
      $otherContact['id'],
      'Contact should have been matched via external_contact_id'
    );
    $otherContract = civicrm_api3('Contract', 'getsingle', [
      'contact_id' => $otherContact['id'],
    ]);
    $this->assertEquals(
      $this->altCampaignId,
      $otherContract['campaign_id'],
      'Campaign specified via external_campaign_id should be used'
    );
  }

}
