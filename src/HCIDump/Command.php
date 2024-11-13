<?php
namespace SharkyDog\BlueZ\HCIDump;
use SharkyDog\BlueZ\HCIDump;

abstract class Command {
  private static $_classOpcodes = [];

  public $ogf = '00';
  public $ocf = '0000';
  public $params = '';

  public static function hexOpcodeToOgfOcf(string $opcode): ?array {
    if(($opcode=@hex2bin($opcode)) === false) {
      return null;
    }

    if(($opc=self::binOpcodeToOgfOcf($opcode)) === null) {
      return null;
    }

    return [
      'ogf' => bin2hex($opc['ogf']),
      'ocf' => bin2hex($opc['ocf'])
    ];
  }

  public static function hexOgfOcfToOpcode(string $ogf, string $ocf): ?string {
    if(($ogf=@hex2bin($ogf)) === false) {
      return null;
    }
    if(($ocf=@hex2bin($ocf)) === false) {
      return null;
    }

    if(($opc=self::binOgfOcfToOpcode($ogf,$ocf)) === null) {
      return null;
    }

    return bin2hex($opc);
  }

  public static function binOpcodeToOgfOcf(string $opcode): ?array {
    if(($opc=@unpack('vopc', $opcode)) === false) {
      return null;
    }

    $opc = $opc['opc'];
    $ogf = chr($opc >> 10);
    $ocf = pack('n', $opc & 0x03FF);

    return [
      'ogf' => $ogf,
      'ocf' => $ocf
    ];
  }

  public static function binOgfOcfToOpcode(string $ogf, string $ocf): ?string {
    if(($ocf=@unpack('nocf', $ocf)) === false) {
      return null;
    }

    $ogf = ord($ogf);
    $ocf = $ocf['ocf'];
    $opc = pack('v', (($ogf << 10) | $ocf));

    return $opc;
  }

  final public function __construct() {
    if(!isset(self::$_classOpcodes[static::class])) {
      self::$_classOpcodes[static::class] = self::hexOgfOcfToOpcode($this->ogf,$this->ocf) ?: '0000';
    }
  }

  final public function getOpcode() {
    return self::$_classOpcodes[static::class];
  }

  final public function parse(string $opcode, string $params, HCIDump $hcid): ?self {
    if(($cmd=$this->_parse($opcode,$params,$hcid)) === null) {
      return null;
    }
    $cmd->params = bin2hex($params);
    return $cmd;
  }

  final public function parseReturn(string $params, HCIDump $hcid): ?self {
    if(($cmd=$this->_parseReturn($params,$hcid)) === null) {
      return null;
    }
    return $cmd;
  }

  protected function _parse(string $opcode, string $params, HCIDump $hcid): ?self {
    return null;
  }

  protected function _parseReturn(string $params, HCIDump $hcid): ?self {
    return null;
  }
}
