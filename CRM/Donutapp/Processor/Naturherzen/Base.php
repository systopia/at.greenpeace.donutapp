<?php

abstract class CRM_Donutapp_Processor_Naturherzen_Base extends CRM_Donutapp_Processor_Base {

  public function verifySetup()
  {
    // preprocessing / preconditions
    $this->assertExtensionInstalled('de.systopia.xcm');
    $this->assertExtensionInstalled('org.project60.sepa');
    $this->assertExtensionInstalled('org.project60.bic');

    // meddle with the URLs for testing
    CRM_Donutapp_API_Client::$apiEndpoint = 'https://staging.donutapp.io/api/v1/';
    CRM_Donutapp_API_Client::$oauth2Endpoint = 'https://staging.donutapp.io/o/token/?grant_type=client_credentials';
  }

  /**
   * Get the activity activity type ID of the
   *   recruitment activity
   *
   * @return integer
   *  activity type ID
   */
  protected function getRecruitmentActivityTypeID()
  {
    static $recruitment_activity_id = null;
    if ($recruitment_activity_id === null) {
      $recruitment_activity_id = 0;
      try {
        $recruitment_activity_id = civicrm_api3('OptionValue', 'getvalue', [
            'option_group_id' => 'activity_type',
            'name'            => 'donutapp_recruitment',
            'return'          => 'id'
        ]);
      } catch (CiviCRM_API3_Exception $ex) {
        // doesn't exist? lets create it
        $result = civicrm_api3('OptionValue', 'create', [
            'option_group_id' => 'activity_type',
            'name'            => 'donutapp_recruitment',
            'label'           => "RaiseTogether Werbung",
            'is_active'       => 1,
        ]);
        $recruitment_activity_id = $result['id'];
      }
    }
    return $recruitment_activity_id;
  }


  /**
   * Get the activity activity type ID to
   *  record an import error
   *
   * @return integer
   *  activity type ID
   */
  protected function getImportErrorActivityTypeID()
  {
    static $import_error_activity_type_id = null;
    if ($import_error_activity_type_id === null) {
      $import_error_activity_type_id = 0;
      try {
        $import_error_activity_type_id = civicrm_api3('OptionValue', 'getvalue', [
            'option_group_id' => 'activity_type',
            'name'            => 'donutapp_importerror',
            'return'          => 'value'
        ]);
      } catch (CiviCRM_API3_Exception $ex) {
        // doesn't exist? lets create it
        $result = civicrm_api3('OptionValue', 'create', [
            'option_group_id' => 'activity_type',
            'name'            => 'donutapp_importerror',
            'label'           => "RaiseTogether Importfehler",
            'is_active'       => 1,
        ]);
        $import_error_activity_type_id = civicrm_api3('OptionValue', 'getvalue', [
            'option_group_id' => 'activity_type',
            'name'            => 'donutapp_importerror',
            'return'          => 'value'
        ]);
      }
    }
    return $import_error_activity_type_id;
  }

  /**
   * Determine the Civi Campaign ID for an API entity
   *
   * @param \CRM_Donutapp_API_Entity $entity
   *
   * @return int
   */
  protected function getCampaign(CRM_Donutapp_API_Entity $entity) {
    // in this case we use a fixed campaign
    $campaign_id = 11;

    // make sure it exists
    static $campaign_exists = null;
    if ($campaign_exists === null) {
      $campaign_exists = civicrm_api3('Campaign', 'getcount', ['id' => $campaign_id]);
      if (!$campaign_exists) {
        civicrm_api3('Campaign', 'create', [
            'id' => $campaign_id,
            'title' => 'Raise Together'
        ]);
      }
    }

    return $campaign_id;
  }

}