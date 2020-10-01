<?php

use Tdely\Luhn\Luhn;

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
    $importedDonations = CRM_Donutapp_API_Donation::all(['limit' => $this->params['limit']]);

    foreach ($importedDonations as $donation) {
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

    // and match using XCM
    $contact_id = civicrm_api3('Contact', 'getorcreate', $contact_data)['id'];

    // todo: fill fundraiser fields
//    fundraiser_external_id = null
//    fundraiser_code = "rt-systopia"
//    fundraiser_name = "Fundraiser, Test"


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
    $mandate_data = [
      'type'               => 'RCUR',
      'contact_id'         => $contact_id,
      'iban'               => trim(strtoupper(preg_replace('/ +/', '', $donation->bank_account_iban))),
      'bic'                => trim(strtoupper(preg_replace('/ +/', '', $donation->bank_account_bic))),
      'campaign_id'        => $this->getCampaign($donation),
      'financial_type_id'  => 5, // Gönner
      'source'             => 'DonutApp API',
      'frequency_interval' => (int) (12 / $donation->direct_debit_interval),
      'frequency_unit'     => 'month',
    ];

    // fill BIC
    if (empty($mandate_data['bic'])) {
      // todo: resolve via little BIC extension
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

  }

  /**
   * Store the contract PDF as a File entity
   *
   * @todo this should ideally be implemented using the Attachment.create API,
   *       which is not possible as of Civi 5.7 due to an overly-sensitive
   *       permission check. Switch to Attachment.create once
   *       https://lab.civicrm.org/dev/core/issues/690 lands.
   *
   * @param $fileName
   * @param $content
   * @param $membershipId
   *
   * @throws CiviCRM_API3_Exception
   */
  protected function storeContractFile($fileName, $content, $membershipId) {
    $config = CRM_Core_Config::singleton();
    $uri = CRM_Utils_File::makeFileName($fileName);
    $path = $config->customFileUploadDir . DIRECTORY_SEPARATOR . $uri;
    file_put_contents($path, $content);
    $file = civicrm_api3('File', 'create', [
      'mime_type' => 'application/pdf',
      'uri' => $uri,
    ]);
    $custom_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID('contract_file', 'membership_general');
    civicrm_api3('custom_value', 'create', [
      'entity_id' => $membershipId,
      $custom_field => $file['id'],
    ]);
  }
}
