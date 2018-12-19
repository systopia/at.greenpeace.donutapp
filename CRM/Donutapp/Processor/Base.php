<?php

abstract class CRM_Donutapp_Processor_Base {
  protected $params;

  public function __construct($params) {
    $this->params = $params;
  }

  protected function findOrCreateDialoger($petition) {
    if (!preg_match('/^gpat\-(\d{4,5})$/', $petition->fundraiser_code, $match)) {
      return NULL;
    }
    $dialoger_id = $match[1];
    $dialoger_id_field = CRM_Core_BAO_CustomField::getCustomFieldID('dialoger_id', 'dialoger_data', TRUE);
    // lookup dialoger by dialoger_id
    $dialoger = civicrm_api3('Contact', 'get', [
      $dialoger_id_field => $dialoger_id,
      'contact_sub_type' => 'Dialoger',
      'return'           => 'id'
    ]);
    if (empty($dialoger['id'])) {
      // no matching dialoger found, create with dialoger_id and name
      $dialoger_start_field = CRM_Core_BAO_CustomField::getCustomFieldID('dialoger_start_date', 'dialoger_data', TRUE);
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

  abstract public function process();
}