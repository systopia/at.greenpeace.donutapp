<?php

abstract class CRM_Donutapp_Processor_Base {
  protected $params;

  /**
   * Use this function do verify whether the setup works for this processor
   *
   * If something's wrong, throw an exception
   *
   * @throws Exception
   *  if anything's wrong
   */
  abstract public function verifySetup();

  public function __construct($params) {
    $this->params = $params;
  }

  /**
   * Process entity
   *
   * @return mixed
   */
  abstract public function process();

  /**
   * Verify that the given extension is active
   *
   * @param string $extension_key
   *   the key of the extension, e.g. de.systopia.xcm
   *
   * @throws CRM_Exception
   *   if the extension is not installed/active
   */
  public function assertExtensionInstalled($extension_key) {
    static $active_extension_list = null;
    if ($active_extension_list === null) {
      $active_extension_list = [];
      $query = civicrm_api3('Extension', 'get', [
          'is_active'    => 1,
          'option.limit' => 0,
          'return'       => 'key'
      ]);
      foreach ($query['values'] as $extension) {
        $active_extension_list[] = $extension['key'];
      }
    }

    if (!in_array($extension_key, $active_extension_list)) {
      throw new CRM_Exception("Required extension '{$extension_key}' not active/installed.");
    }
  }
}
