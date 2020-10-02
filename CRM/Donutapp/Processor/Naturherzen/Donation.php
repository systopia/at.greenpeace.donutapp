<?php
/*-------------------------------------------------------+
| DonutApp Processor for Naturherzen                     |
| Copyright (C) 2020 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

use Tdely\Luhn\Luhn;
use CRM_Donutapp_ExtensionUtil as E;

class CRM_Donutapp_Processor_Naturherzen_Donation extends CRM_Donutapp_Processor_Naturherzen_Base {

  /**
   * Fetch and process donations

   * @throws CRM_Donutapp_API_Error_Authentication
   * @throws CRM_Donutapp_API_Error_BadResponse
   * @throws CiviCRM_API3_Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function process() {
    CRM_Donutapp_Util::$IMPORT_ERROR_ACTIVITY_TYPE = $this->getImportErrorActivityTypeID();
    CRM_Donutapp_API_Client::setClientId($this->params['client_id']);
    CRM_Donutapp_API_Client::setClientSecret($this->params['client_secret']);
    $donations = CRM_Donutapp_API_Donation::all(['limit' => $this->params['limit']]);

    foreach ($donations as $donation) {
      try {
        // preload PDF outside of transaction
        $donation->fetchPdf();
        $this->processWithTransaction($donation);
      }
      catch (Exception $e) {
        CRM_Core_Error::debug_log_message(
            'Uncaught Exception in CRM_Donutapp_Processor_Donation::process'
        );
        CRM_Core_Error::debug_var('Exception Details', [
            'message'   => $e->getMessage(),
            'exception' => $e
        ]);
        // Create Import Error Activity
        CRM_Donutapp_Util::createImportError('Donation', $e, $donation);
      }
    }
  }


  /**
   * Process a donation within a database transaction
   *
   * @param \CRM_Donutapp_API_Donation $donation
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function processWithTransaction(CRM_Donutapp_API_Donation $donation) {
    $tx = new CRM_Core_Transaction();
    try {
      $this->processDonation($donation);
    }
    catch (Exception $e) {
      $tx->rollback();
      throw $e;
    }
  }

  /**
   * Process a donation
   *
   * @param \CRM_Donutapp_API_Donation $donation
   *
   * @throws \CRM_Donutapp_API_Error_Authentication
   * @throws \CRM_Donutapp_API_Error_BadResponse
   * @throws \CiviCRM_API3_Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \CRM_Donutapp_Processor_Exception
   */
  protected function processDonation(CRM_Donutapp_API_Donation $donation) {
    $contact_id = $this->createContact($donation);
    $mandate = $this->createMandate($donation, $contact_id);
    $this->createRecruitmentActivity($donation, $contact_id, $mandate);

    // Should we confirm retrieval?
    if ($this->params['confirm']) {
      $donation->confirm();
    }
  }

  /**
   * Identify or create donor's CiviCRM contact
   *
   * @param CRM_Donutapp_API_Donation $donation
   *
   * @return integer
   *    contact id
   */
  protected function createContact(CRM_Donutapp_API_Donation $donation) {
    $contact_type = 'Individual';
    $prefix_id = '';
    $gender_id = '';
    switch ($donation->donor_sex) {
      case 0: // Herr
        $gender_id = 2;
        $prefix_id = 6;
        break;

      case 1: // Frau
        $gender_id = 1;
        $prefix_id = 5;
        break;

      case 2: // Familie
        $prefix_id = 7;
        break;

      case 3: // Firma
        $contact_type = 'Organization';
        break;

      case 4: // Sonstiges
        break;
    }

    // compile contact data
    $contact_data = [
      'xcm_profile'    => 'donutapp',
      'contact_type'   => $contact_type,
      'formal_title'   => $donation->donor_academic_title,
      'first_name'     => $donation->donor_first_name,
      'last_name'      => $donation->donor_last_name,
      'prefix_id'      => $prefix_id,
      'gender_id'      => $gender_id,
      'birth_date'     => $donation->donor_date_of_birth,
      'country_id'     => $donation->donor_country,
      'postal_code'    => $donation->donor_zip_code,
      'city'           => $donation->donor_city,
      'street_address' => trim(trim($donation->donor_street) . ' ' . trim($donation->donor_house_number)),
      'email'          => $donation->donor_email,
      'phone'          => $donation->donor_phone,
      'phone2'         => $donation->donor_mobile,
      // for identification only:
      'iban'           => preg_replace('/ +/', '', strtoupper($donation->bank_account_iban)),
    ];

    // TOOD: what's this about?
//    $external_contact_id = $donation->external_contact_id;
//    if (!empty($external_contact_id)) {
//      if (Luhn::isValid($external_contact_id)) {
//        // remove trailing check digit
//        $contact_data['id'] = substr($external_contact_id, 0, -1);
//      } else {
//        Civi::log()->warning("[donutapp] Got invalid value for external_contact_id: '{$external_contact_id}'");
//      }
//    }

    // remove empty attributes to prevent creation of useless diff activity
    foreach ($contact_data as $key => $value) {
      if (empty($value)) {
        unset($contact_data[$key]);
      }
    }

    // add fundraiser fields
    $fundraiser_fields = [
        'fundraiser_location' => 'custom_28', // todo: key not confirmed
        'fundraiser_name'     => 'custom_31',
        'createtime'          => 'custom_29',
        'uid'                 => 'custom_30',
        //'fundraiser_external_id' => 'custom_?',
        //'fundraiser_code'     => 'custom_?',
    ];
    foreach ($fundraiser_fields as $submission_field => $civicrm_field) {
      if (isset($donation->$submission_field)) {
        $contact_data[$civicrm_field] = $donation->$submission_field;
      }
    }

    // set data protection fields
    $data_protection_fields = [
        'contact_by_phone' => 'do_not_phone',
        'contact_by_email' => 'do_not_email',
        'contact_by_mail'  => 'do_not_mail',  // todo: key not confirmed
    ];
    foreach ($fundraiser_fields as $submission_field => $civicrm_field) {
      $contact_data[$civicrm_field] = empty($donation->$submission_field) ? 1 : 0;
    }

    // and match using XCM
    $contact_id = civicrm_api3('Contact', 'getorcreate', $contact_data)['id'];

    // todo: updates after? e.g. update privacy settings?

    return $contact_id;
  }


  /**
   * Will create a new mandate for the given donor
   *
   * @param CRM_Donutapp_API_Donation $donation
   *   the current donation object
   *
   * @param integer $contact_id
   *   the contact ID of the donor
   *
   * @return array
   *   mandate data
   */
  protected function createMandate($donation, $contact_id)
  {
    // todo: add a reference based on
    $mandate_data = [
      'type'               => 'RCUR',
      'contact_id'         => $contact_id,
      'iban'               => trim(strtoupper(preg_replace('/ +/', '', $donation->bank_account_iban))),
      'bic'                => trim(strtoupper(preg_replace('/ +/', '', $donation->bank_account_bic))),
      'campaign_id'        => $this->getCampaignID($donation),
      'financial_type_id'  => 5, // Gönner
      'source'             => 'DonutApp API',
      'frequency_interval' => (int) (12 / $donation->direct_debit_interval),
      'frequency_unit'     => 'month',
      'date'               => substr($donation->createtime, 0, 10),
      'start_date'         => empty($donation->special1) ? $donation->contract_start_date : $donation->special1,
    ];

    // fill BIC
    if (empty($mandate_data['bic'])) {
      $result = civicrm_api3('Bic', 'findbyiban', ['iban' => $mandate_data['iban']]);
      if (!empty($result['bic'])) {
        $mandate_data['bic'] = $result['bic'];
      }
    }

    // set amount - comma is decimal separator, no thousands separator
    $annualAmount = str_replace(',', '.', $donation->donation_amount_annual);
    $mandate_data['amount'] = number_format($annualAmount / $donation->direct_debit_interval, 2, '.', '');
    if ($mandate_data['amount'] * $donation->direct_debit_interval != $annualAmount) {
      throw new CRM_Donutapp_Processor_Exception(
          "Contract annual amount '{$annualAmount}' not divisible by frequency {$donation->direct_debit_interval}."
      );
    }

    // derive creditor
    switch ($donation->payment_method) {
      case 'postfinance_iban':
        $mandate_data['creditor_id'] = 2;
        $mandate_data['status'] = 'FRST';
        break;

      default:
      case 'alternative_iban':
        // TODO: is alternative_iban correct?
        $mandate_data['creditor_id'] = 3;
        $mandate_data['status'] = 'ONHOLD';
        break;
    }

    // todo: adjust onhold, delete validation date, update status?

    // create mandate
    $result = civicrm_api3('SepaMandate', 'createfull', $mandate_data);
    return civicrm_api3('SepaMandate', 'getsingle', ['id' => $result['id']]);
  }

  /**
   * Create an activity to reflect this recruitment
   *  The activity will have the status 'Scheduled' if there's anything left to do here,
   *   or 'Completed' if everything's fine
   *
   * @param CRM_Donutapp_API_Donation $donation
   *   the current donation object
   *
   * @param integer $contact_id
   *   the contact ID of the donor
   *
   * @param array $mandate
   *   sepa mandate created (data)
   */
  protected function createRecruitmentActivity($donation, $contact_id, $mandate)
  {
    // collect TODOs:
    $todos = [];
    // check whether the status should be 'Scheduled' instead
    if ($mandate['status'] != 'ONHOLD') {
      $todos[] = "Mandat muss noch verifiziert und aktiviert werden";
    }

    // compile activity data
    $activity_data = [
      'target_id'         => $contact_id,
      'activity_type_id'  => $this->getRecruitmentActivityTypeID(),
      'subject'           => 'Raise Together Recruitment (Formunauts)',
      'activity_datetime' => date('YmdHiS'),
      'status_id'         => empty($todos) ? 'Completed' : 'Scheduled',
      'campaign_id'       => $this->getCampaignID($donation),
    ];


    // render details
    $smarty = CRM_Core_Smarty::singleton();
    $smarty->assignAll([
        'contact'       => civicrm_api3('Contact', 'getsingle', ['id' => $contact_id]),
        'mandate'       => $mandate,
        'rcontribution' => civicrm_api3('ContributionRecur', 'getsingle', ['id' => $mandate['entity_id']]),
        'submission'    => $donation->getData(),
        'todos'         => $todos,
                       ]);
    $activity_data['details'] = $smarty->fetch(E::path('resources/Naturherzen/RecruitmentActivity.tpl'));

    // create activity
    $activity = civicrm_api3('Activity', 'create', $activity_data);

    // attach PDF
    $config = CRM_Core_Config::singleton();
    $uri = CRM_Utils_File::makeFileName("{$donation->uid}.pdf");
    $path = $config->customFileUploadDir . DIRECTORY_SEPARATOR . $uri;
    file_put_contents($path, $donation->pdf_content);
    $file = civicrm_api3('File', 'create', [
        'mime_type'   => 'application/pdf',
        'description' => 'Vertrag PDF',
        'uri'         => $uri,
    ]);
    CRM_Core_DAO::executeQuery("
        INSERT INTO civicrm_entity_file (entity_table,entity_id,file_id) 
        VALUES ('civicrm_activity', %1, %2)", [
          1 => [$activity['id'], 'Integer'],
          2 => [$file['id'],     'Integer']]);
  }
}
