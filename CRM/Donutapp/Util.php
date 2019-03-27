<?php

class CRM_Donutapp_Util {
  // @TODO: use a more generic name/type
  const IMPORT_ERROR_ACTIVITY_TYPE = 'streetimport_error';

  public static function createImportError($component, $message, $context = NULL) {
    $params = [
      'activity_type_id'   => self::IMPORT_ERROR_ACTIVITY_TYPE,
      'subject'            => 'DonutApp ' . $component . ' Error',
      'status_id'          => 'Scheduled',
      'details'            => '<pre>' . $message . "\n" . print_r($context, true) . '</pre>',
    ];
    civicrm_api3('Activity', 'create', $params);
  }
}