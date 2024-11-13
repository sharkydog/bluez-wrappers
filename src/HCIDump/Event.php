<?php
namespace SharkyDog\BlueZ\HCIDump;
use SharkyDog\BlueZ\HCIDump;

abstract class Event {
  private static $_classCodes = [];

  public $code = '00';
  public $params = '';

  final public function __construct() {
    if(!isset(self::$_classCodes[static::class])) {
      self::$_classCodes[static::class] = $this->code ?: '00';
    }
  }

  final public function getCode() {
    return self::$_classCodes[static::class];
  }

  public function filter(callable $callback): callable {
    return $callback;
  }

  public function parse(string $code, string $params, HCIDump $hcid): ?self {
    if(($evt=$this->_parse($code,$params,$hcid)) === null) {
      return null;
    }
    $evt->params = bin2hex($params);
    return $evt;
  }

  protected function _parse(string $code, string $params, HCIDump $hcid): ?self {
    return null;
  }
}
