<?php

abstract class CRM_Donutapp_Processor_Greenpeace_Base extends CRM_Donutapp_Processor_Base {

  abstract protected function getCampaign(CRM_Donutapp_API_Entity $entity);

  /**
   * Find or create a dialoger based on the dialoger ID
   *
   * @param $petition
   *
   * @return |null
   * @throws \CiviCRM_API3_Exception
   */
  protected function findOrCreateDialoger($petition) {
    if (!preg_match('/^gpat\-(\d{4,5})$/', $petition->fundraiser_code, $match)) {
      return NULL;
    }
    $dialoger_id = $match[1];
    $dialoger_id_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID('dialoger_id', 'dialoger_data');
    // lookup dialoger by dialoger_id
    $dialoger = civicrm_api3('Contact', 'get', [
      $dialoger_id_field => $dialoger_id,
      'contact_sub_type' => 'Dialoger',
      'return'           => 'id'
    ]);
    if (empty($dialoger['id'])) {
      // no matching dialoger found, create with dialoger_id and name
      $dialoger_start_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID('dialoger_start_date', 'dialoger_data');
      $name = explode(',', $petition->fundraiser_name);
      $first_name = $last_name = NULL;
      if (count($name) == 2) {
        $first_name = trim($name[1]);
        $last_name = $name[0];
      }
      else {
        $last_name = $petition->fundraiser_name;
      }
      // fetch campaign title for contact source
      $source = civicrm_api3('Campaign', 'getvalue', [
        'external_identifier' => 'DD',
        'return' => 'title'
      ]);

      // create dialoger. We assume they started on the first of this month
      $dialoger = civicrm_api3('Contact', 'create', [
        'contact_type' => 'Individual',
        'contact_sub_type'    => 'Dialoger',
        'last_name'           => $last_name,
        'first_name'          => $first_name,
        'source'              => $source,
        $dialoger_id_field    => $dialoger_id,
        $dialoger_start_field => date('Y-m-01'),
      ]);
    }
    return $dialoger['id'];
  }

  /**
   * Create activities for welcome/thank you email
   *
   * @param \CRM_Donutapp_API_Entity $entity
   * @param $contactId
   * @param $parentActivityId
   *
   * @throws \Exception
   */
  protected function processWelcomeEmail(CRM_Donutapp_API_Entity $entity, $contactId, $parentActivityId) {
    $subject = 'Email "' . $this->getEmailSubject($entity) . '"';
    $create_date = new DateTime($entity->createtime);
    $create_date->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $apiParams = [
      'activity_date_time' => $create_date->format('YmdHis')
    ];

    $bounced = FALSE;
    switch ($entity->welcome_email_status) {
      case 'bounce':
      case 'hard_bounce':
        $bounced = TRUE;
        // no break
      case 'sent':
      case 'open':
        $email_action = $this->addEmailAction(
          $contactId,
          $parentActivityId,
          $this->getCampaign($entity),
          $entity->donor_email,
          $subject,
          $apiParams
        );
        break;
    }
    if ($bounced) {
      $bounce_type = 'Softbounce';
      if ($entity->welcome_email_status == 'hard_bounce') {
        $bounce_type = 'Hardbounce';
      }
      $this->addBounce(
        $bounce_type,
        $contactId,
        $email_action['id'],
        $this->getCampaign($entity),
        $entity->donor_email,
        $apiParams
      );
    }
  }

  protected function addEmailAction($contactId, $parentActivityId, $campaignId, $email, $subject, $apiParams = []) {
    $parent_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'parent_activity_id',
      'activity_hierarchy'
    );
    $email_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'email',
      'email_information'
    );
    $email_provider_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'email_provider',
      'email_information'
    );
    $params = [
      'target_id'           => $contactId,
      'activity_type_id'    => 'Action',
      'status_id'           => 'Completed',
      'medium_id'           => 'email',
      'campaign_id'         => $campaignId,
      'subject'             => "{$subject} - {$email}",
      $email_field          => $email,
      $parent_field         => $parentActivityId,
      $email_provider_field => 'Formunauts',
    ];
    return civicrm_api3(
      'Activity',
      'create',
      array_merge($params, $apiParams)
    );
  }

  protected function addBounce($bounceType, $contactId, $parentActivityId, $campaignId, $email, $apiParams = []) {
    $parent_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'parent_activity_id',
      'activity_hierarchy'
    );
    $bounce_type_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'bounce_type',
      'bounce_information'
    );
    $email_provider_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'email_provider',
      'bounce_information'
    );
    $email_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'email',
      'bounce_information'
    );
    $params = [
      'target_id'           => $contactId,
      'activity_type_id'    => 'Bounce',
      'medium_id'           => 'email',
      'status_id'           => 'Completed',
      'campaign_id'         => $campaignId,
      'subject'             => "{$bounceType} - {$email}",
      $email_field          => $email,
      $bounce_type_field    => $bounceType,
      $parent_field         => $parentActivityId,
      $email_provider_field => 'Formunauts',
    ];
    return civicrm_api3(
      'Activity',
      'create',
      array_merge($params, $apiParams)
    );
  }

  protected function getGroupId($groupName) {
    return civicrm_api3('Group', 'getvalue', [
      'title'  => $groupName,
      'return' => 'id',
    ]);
  }

  protected function addGroup($contactId, $groupName) {
    return civicrm_api3('GroupContact', 'create', [
      'group_id' => $this->getGroupId($groupName),
      'contact_id' => $contactId,
    ]);
  }

}
