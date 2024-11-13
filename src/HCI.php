<?php
namespace SharkyDog\BlueZ;
use SharkyDog\BlueZ\HCI\Error;
use SharkyDog\BlueZ\HCI\CommandResult;

// HCI commands and events
// https://www.bluetooth.com/wp-content/uploads/Files/Specification/HTML/Core-54/out/en/host-controller-interface/host-controller-interface-functional-specification.html#UUID-ee8bbec6-ebdd-b47d-41d5-a7e655cad979

class HCI {
  private static $_cmdSilenceNext = false;

  private static function _exec($cmd) {
    $ret = @exec($cmd.' 2>&1', $out, $code);
    $ret = $ret !== false && $code === 0;

    if(!$ret) {
      foreach($out as $line) {
        Log::error($line, 'cmd','hci');
      }
    }

    return $ret ? $out : null;
  }

  public static function adapters(): ?array {
    if(($hciout = self::_exec('hciconfig')) === null || !count($hciout)) {
      return null;
    }

    $adapters = [];
    $hci = '';

    foreach($hciout as $hciline) {
      if(!$hci) {
        if(preg_match('/^([^\s\:]+)\:/',$hciline,$m)) {
          $hci = $m[1];
        }
        continue;
      }

      if(!preg_match('/BD\sAddress\:\s+((?:[a-f0-9]{2})(?:\:[a-f0-9]{2}){5})/i',$hciline,$m)) {
        continue;
      }

      $adapters[$hci] = new Adapter($hci, $m[1]);
      $hci = '';
    }

    return $adapters;
  }

  public static function adapter(string $hciOrmac, array $adapters=[]): ?Adapter {
    if(empty($adapters) && ($adapters = self::adapters()) === null) {
      return null;
    }

    $hciOrmac = strtolower($hciOrmac);

    foreach($adapters as $adapter) {
      if(!($adapter instanceOf Adapter)) {
        continue;
      }
      if($hciOrmac != $adapter->hci && $hciOrmac != strtolower($adapter->mac)) {
        continue;
      }
      return $adapter;
    }

    return null;
  }

  public static function reset(string $hci): bool {
    return self::_exec('hciconfig '.$hci.' reset') !== null;
  }

  public static function btAdapter(string $mac, string $params): ?array {
    return self::_exec('bt-adapter -a '.$mac.' '.$params);
  }

  public static function btAdapterInfo(string $mac, ?AdapterInfo $adapter=null): ?AdapterInfo {
    if(($ret = self::btAdapter($mac,'-i')) === null) {
      return null;
    } else {
      return AdapterInfo::parseBtAdapterInfo($mac,$ret,$adapter);
    }
  }

  public static function cmdRet(?string $ret, ?int $stsbyte=0, ?string $fn=null): CommandResult {
    $silent = self::$_cmdSilenceNext;
    self::$_cmdSilenceNext = false;

    $res = new CommandResult;

    if(!$ret) {
      $res->err = new Error('NA');
      return $res;
    }

    $opc = HCIDump\Command::hexOpcodeToOgfOcf(substr($ret,2,4));
    $sts = $stsbyte !== null ? substr($ret,6+$stsbyte,2) : '00';

    $res->ok = ($sts == '00');
    $res->ogf = $opc['ogf'];
    $res->ocf = $opc['ocf'];
    $res->ret = $stsbyte!==null ? substr($ret,8+$stsbyte) : '';
    $res->err = $res->ok ? null : new Error($sts);

    if($res->err && !$silent) {
      $fn = $fn ? $fn.',' : '';
      Log::error('HCI: '.$res->err->code.' '.$res->err->text.' ['.$fn.'ogf:0x'.$res->ogf.',ocf:0x'.$res->ocf.']');
    }

    return $res;
  }

  public static function cmdSilenceNext() {
    self::$_cmdSilenceNext = true;
  }

  public static function cmdStr(string $hci, int $ogf, int $ocf, int ...$params): string {
    $cmd = 'hcitool -i '.$hci.' cmd 0x'.dechex($ogf).' 0x'.dechex($ocf);

    if(!empty($params)) {
      $params = array_map('dechex', $params);
      $cmd .= ' 0x'.implode(' 0x', $params);
    }

    return $cmd;
  }

  public static function cmd(string $hci, int $ogf, int $ocf, int ...$params): ?string {
    if(($ret = self::_exec(self::cmdStr($hci,$ogf,$ocf,...$params))) === null) {
      return null;
    }

    $cmdRet = null;
    foreach($ret as $line) {
      if($cmdRet !== null) {
        $cmdRet .= preg_replace('/[^A-F0-9]+/i', '', $line);
        continue;
      }
      if(strpos($line, '> HCI Event: 0x0e') === 0) {
        $cmdRet = '';
        continue;
      }
    }
    $cmdRet = $cmdRet ?: '';

    return $cmdRet;
  }

  public static function hciReset(string $hci): CommandResult {
    $ret = self::cmd($hci, 0x03, 0x0003);
    return self::cmdRet($ret, 0, __FUNCTION__);
  }

  public static function setEventMask(string $hci, int $mask): CommandResult {
    $params = unpack('C*',pack('P',$mask));
    $ret = self::cmd($hci, 0x03, 0x0001, ...$params);
    return self::cmdRet($ret, 0, __FUNCTION__);
  }
}
