<?php
namespace SharkyDog\BlueZ\HCIDump\Command;
use SharkyDog\BlueZ\HCIDump;
use SharkyDog\BlueZ\HCIDump\Command;

class Unknown extends Command {
  protected function _parse(string $opcode, string $params, HCIDump $hcid): ?self {
    if(($opc=self::binOpcodeToOgfOcf($opcode)) === null) {
      return null;
    }

    $cmd = new self;
    $cmd->ogf = bin2hex($opc['ogf']);
    $cmd->ocf = bin2hex($opc['ocf']);

    return $cmd;
  }
}
