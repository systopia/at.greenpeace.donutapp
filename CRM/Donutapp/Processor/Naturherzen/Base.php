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
            'return'          => 'value'
        ]);
      } catch (CiviCRM_API3_Exception $ex) {
        // doesn't exist? lets create it
        $result = civicrm_api3('OptionValue', 'create', [
            'option_group_id' => 'activity_type',
            'name'            => 'donutapp_recruitment',
            'label'           => "RaiseTogether Werbung",
            'is_active'       => 1,
        ]);
        $recruitment_activity_id = civicrm_api3('OptionValue', 'getvalue', [
            'option_group_id' => 'activity_type',
            'name'            => 'donutapp_recruitment',
            'return'          => 'value'
        ]);
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
  protected function getCampaignID(CRM_Donutapp_API_Entity $entity) {
    // make sure it exists
    static $campaign_id = null;
    if ($campaign_id === null) {
      try {
        $campaign_id = civicrm_api3('Campaign', 'getvalue', [
            'name'   => 'Strassenkampagne_smito',
            'return' => 'id']);
      } catch (CiviCRM_API3_Exception $ex) {
        // that doesn't seem to exist
      }

      if (!$campaign_id) {
        $result = civicrm_api3('Campaign', 'create', [
            'name'             => 'Strassenkampagne_smito',
            'title'            => 'Strassenkampagne Raise Together',
            'start_date'       => '2019-07-01',
            'is_active'        => 1,
            'status_id'        => 2,
//            'campaign_type_id' => 4
        ]);
        $campaign_id = $result['id'];
      }
    }

    return $campaign_id;
  }

}
