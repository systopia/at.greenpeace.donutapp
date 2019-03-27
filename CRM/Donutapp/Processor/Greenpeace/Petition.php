<?php

class CRM_Donutapp_Processor_Greenpeace_Petition extends CRM_Donutapp_Processor_Greenpeace_Base {


  /**
   * Should we defer importing this petititon?
   *
   * Import should be deferred if:
   *  - Petition is on hold OR
   *  - Petition was added in the last hour and welcome email is still
   *    queued or being retried
   *
   * @param \CRM_Donutapp_API_Petition $petition
   *
   * @throws \Exception
   *
   * @return bool
   */
  private function isDeferrable(CRM_Donutapp_API_Petition $petition) {
    // Welcome email is in queue or being retried
    $pending = in_array($petition->welcome_email_status, ['queued', 'retrying']);
    $created = new DateTime($petition->createtime);
    // Welcome email was created in the last hour
    $recent = new DateTime() < $created->modify('-1 hour');
    return $petition->on_hold || ($pending && $recent);
  }

  /**
   * Determine the Civi Campaign ID for a donation
   *
   * @todo this is currently hardcoded to the DD root campaign. It should return
   * a subcampaign based on something like the customer_id API field
   *
   * @param \CRM_Donutapp_API_Entity $petition
   *
   * @return int
   * @throws \CiviCRM_API3_Exception
   */
  protected function getCampaign(CRM_Donutapp_API_Entity $petition) {
    return civicrm_api3('Campaign', 'getsingle', [
      'external_identifier' => 'DD',
      'return'              => 'id'
    ])['id'];
  }

  /**
   * Fetch and process petitions
   *
   * @throws \CRM_Donutapp_API_Error_Authentication
   * @throws \CRM_Donutapp_API_Error_BadResponse
   * @throws \CiviCRM_API3_Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function process() {
    CRM_Donutapp_API_Client::setClientId($this->params['client_id']);
    CRM_Donutapp_API_Client::setClientSecret($this->params['client_secret']);
    $importedPetitions = CRM_Donutapp_API_Petition::all([
      'limit' => $this->params['limit']
    ]);
    foreach ($importedPetitions as $petition) {
      try {
        $this->processWithTransaction($petition);
      }
      catch (Exception $e) {
        // Create Import Error Activity
        CRM_Donutapp_Util::createImportError('Petition', $e, $petition);
      }
    }
  }

  /**
   * Process a petition within a database transaction
   *
   * @param \CRM_Donutapp_API_Petition $petition
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function processWithTransaction(CRM_Donutapp_API_Petition $petition) {
    $tx = new CRM_Core_Transaction();
    try {
      $this->processPetition($petition);
    }
    catch (Exception $e) {
      $tx->rollback();
      throw $e;
    }
  }

  /**
   * Process a petition
   *
   * @param \CRM_Donutapp_API_Petition $petition
   *
   * @throws \CRM_Donutapp_API_Error_Authentication
   * @throws \CRM_Donutapp_API_Error_BadResponse
   * @throws \CiviCRM_API3_Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function processPetition(CRM_Donutapp_API_Petition $petition) {
    if (!$this->isDeferrable($petition)) {
      $prefix = NULL;
      switch ($petition->donor_sex) {
        case 1:
          $prefix = 'Herr';
          break;

        case 2:
          $prefix = 'Frau';
          break;
      }
      $signature_date = new DateTime($petition->createtime);
      $signature_date->setTimezone(new DateTimeZone(date_default_timezone_get()));

      $phone = $petition->donor_mobile;
      if (empty($phone)) {
        $phone = $petition->donor_phone;
      }

      $params = [
        'prefix'              => $prefix,
        'first_name'          => $petition->donor_first_name,
        'last_name'           => $petition->donor_last_name,
        'birth_date'          => $petition->donor_date_of_birth,
        'phone'               => $phone,
        'email'               => $petition->donor_email,
        'campaign_id'         => $this->getCampaign($petition),
        'medium_id'           => CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'medium_id', 'in_person'),
        'petition_id'         => $petition->petition_id,
        'newsletter'          => $petition->newsletter_optin,
        'street_address'      => trim($petition->donor_street . ' ' . $petition->donor_house_number),
        'postal_code'         => $petition->donor_zip_code,
        'city'                => $petition->donor_city,
        'country'             => $petition->donor_country,
        'signature_date'      => $signature_date->format('YmdHis'),
      ];
      $dialoger = $this->findOrCreateDialoger($petition);
      if (is_null($dialoger)) {
        CRM_Core_Error::debug_log_message('Unable to create dialoger "' . $petition->fundraiser_code . '"');
      }
      else {
        $params['petition_dialoger'] = $dialoger;
      }

      $signature_response = reset(
        civicrm_api3(
          'Engage',
          'signpetition',
          $params
        )['values']
      );

      $parent_activity_id = civicrm_api3('Activity', 'getvalue', [
        'return'             => 'id',
        'activity_type_id'   => 'Petition',
        'target_contact_id'  => $signature_response['id'],
        'activity_date_time' => $signature_date->format('YmdHis'),
        'campaign_id'        => $this->getCampaign($petition)
      ]);
      $this->processWelcomeEmail($petition, $signature_response['id'], $parent_activity_id);

      // Should we confirm retrieval?
      if ($this->params['confirm']) {
        $petition->confirm();
      }
    }
  }
  /**
   * Get subject of thank you email
   *
   * @todo move this to a setting or determine via API
   *
   * @param \CRM_Donutapp_API_Entity $entity
   *
   * @return string
   */
  protected function getEmailSubject(CRM_Donutapp_API_Entity $entity) {
    return 'Danke für Ihre Unterstützung';
  }

}
