<?php
namespace SharkyDog\BlueZ;

class AdapterInfo extends Adapter {
  protected $name = '';
  protected $alias = '';
  protected $discoverable = false;
  protected $discoverableTimeout = 0;
  protected $pairable = false;
  protected $pairableTimeout = 0;
  protected $powered = false;

  public static function parseBtAdapterInfo(string $mac, array $ret, ?self $adapter=null): ?self {
    if(!($hci = trim(array_shift($ret),' []')) || empty($ret)) {
      return null;
    }

    $mac = strtoupper($mac);
    $props = [];

    foreach($ret as $line) {
      if(!preg_match('/^\s*([^\:]+)\:\s*(.+?)(\[r?w?\])?$/',$line,$m)) {
        continue;
      }
      $props[strtolower($m[1])] = $m[2];
    }

    if(empty($props['address']) || strtoupper($props['address']) != $mac) {
      return null;
    }

    if($adapter) {
      $adapter->hci = strtolower($hci);
      $adapter->mac = $mac;
    } else {
      $adapter = new self($hci,$mac);
    }

    foreach($props as $prop => $value) {
      switch($prop) {
        case 'name':
          $adapter->name = $value;
          break;
        case 'alias':
          $adapter->alias = $value;
          break;
        case 'discoverable':
          $adapter->discoverable = (bool)(int)$value;
          break;
        case 'discoverabletimeout':
          $adapter->discoverableTimeout = (int)$value;
          break;
        case 'pairable':
          $adapter->pairable = (bool)(int)$value;
          break;
        case 'pairabletimeout':
          $adapter->pairableTimeout = (int)$value;
          break;
        case 'powered':
          $adapter->powered = (bool)(int)$value;
          break;
        default:
      }
    }

    return $adapter;
  }

  private function __construct($hci,$mac) {
    parent::__construct($hci,$mac);
  }

  public function __get($prop) {
    if($prop[0] == '_') return null;
    return $this->$prop ?? null;
  }

  public function update() {
    HCI::btAdapterInfo($this->mac, $this);
  }

  public function setAlias(string $alias) {
    HCI::btAdapter($this->mac, '--set Alias "'.str_replace(['\'','"'],'',$alias).'"');
  }

  public function setDiscoverable(bool $flag) {
    HCI::btAdapter($this->mac, '--set Discoverable '.(int)$flag);
  }

  public function setDiscoverableTimeout(int $timeout) {
    HCI::btAdapter($this->mac, '--set DiscoverableTimeout '.$timeout);
  }

  public function setPairable(bool $flag) {
    HCI::btAdapter($this->mac, '--set Pairable '.(int)$flag);
  }

  public function setPairableTimeout(int $timeout) {
    HCI::btAdapter($this->mac, '--set PairableTimeout '.$timeout);
  }

  public function setPowered(bool $flag) {
    HCI::btAdapter($this->mac, '--set Powered '.(int)$flag);
  }
}
