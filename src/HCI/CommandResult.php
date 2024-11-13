<?php
namespace SharkyDog\BlueZ\HCI;

class CommandResult {
  public $ok = false;
  public $ogf = '';
  public $ocf = '';
  public $ret = '';
  public $err = null;

  public function __construct(?self $prev=null) {
    if($prev) {
      foreach(get_class_vars(self::class) as $prop => $value) {
        if(!isset($prev->$prop)) continue;
        $this->$prop = $prev->$prop;
      }
    }
  }
}
