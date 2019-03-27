<?php

abstract class CRM_Donutapp_Processor_Base {
  protected $params;

  public function __construct($params) {
    $this->params = $params;
  }

  /**
   * Process entity
   *
   * @return mixed
   */
  abstract public function process();

}
