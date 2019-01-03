<?php

class CRM_Donutapp_Processor_Petition extends CRM_Donutapp_Processor_Base {


  /**
   * Should we defer importing this petititon?
   *
   * Import should be deferred if:
   *  - Petition is on hold OR
   *  - Petition was added in the last 24 hours and welcome email is still
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
    // Welcome email was created in the last 24 hours
    $recent = new DateTime() < $created->modify('-24 hour');
    return $petition->on_hold || ($pending && $recent);
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
        $this->processPetition($petition);
      }
      catch (Exception $e) {
        // Create Import Error Activity
        throw $e;
      }
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

      $params = [
        'prefix'              => $prefix,
        'first_name'          => $petition->donor_first_name,
        'last_name'           => $petition->donor_last_name,
        'birth_date'          => $petition->donor_date_of_birth,
        'phone'               => $petition->donor_mobile,
        'email'               => $petition->donor_email,
        'campaign'            => 'DD',
        'medium_id'           => CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'medium_id', 'in_person'),
        'petition_id'         => $petition->petition_id,
        'newsletter'          => $petition->newsletter_optin,
        'street_address'      => trim($petition->donor_street . ' ' . $petition->donor_house_number),
        'postal_code'         => $petition->donor_zip_code,
        'city'                => $petition->donor_city,
        'country'             => $petition->donor_country,
      ];
      $dialoger = $this->findOrCreateDialoger($petition);
      if (is_null($dialoger)) {
        CRM_Core_Error::debug_log_message('Unable to create dialoger "' . $petition->fundraiser_code . '"');
      }
      else {
        $params['petition_dialoger'] = $dialoger;
      }
      civicrm_api3('Engage', 'signpetition', $params);
      // Should we confirm retrieval?
      if ($this->params['confirm']) {
        $petition->confirm();
      }
    }
  }

}
