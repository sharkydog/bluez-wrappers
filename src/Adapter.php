<?php
namespace SharkyDog\BlueZ;

class Adapter {
  protected $hci;
  protected $mac;

  public function __construct(string $hci, string $mac) {
    $this->hci = strtolower($hci);
    $this->mac = strtoupper($mac);
  }

  public function __get($prop) {
    if($prop[0] == '_') return null;
    return $this->$prop ?? null;
  }
}
