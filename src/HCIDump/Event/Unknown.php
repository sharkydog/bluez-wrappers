<?php
namespace SharkyDog\BlueZ\HCIDump\Event;
use SharkyDog\BlueZ\HCIDump;
use SharkyDog\BlueZ\HCIDump\Event;

class Unknown extends Event {
  protected function _parse(string $code, string $params, HCIDump $hcid): ?self {
    $evt = new self;
    $evt->code = bin2hex($code);
    return $evt;
  }
}
