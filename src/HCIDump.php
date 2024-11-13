<?php
namespace SharkyDog\BlueZ;
use SharkyDog\BlueZ\HCI\Error;
use SharkyDog\BlueZ\HCIDump\Command;
use SharkyDog\BlueZ\HCIDump\Event;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;
use React\ChildProcess\Process;
use React\EventLoop\Loop;

class HCIDump {
  use PrivateEmitterTrait;

  private $_hci;
  private $_proc;

  private $_autostart = true;
  private $_stopping = false;

  private $_hnd_cmd = [];
  private $_cb_cmd = [];
  private $_hnd_evt = [];
  private $_cb_evt = [];
  private $_cb_idx = 0;

  private $_buffstr = '';
  private $_buffbin = '';
  private $_datalen = 0;

  public function __construct(Adapter $hci) {
    $this->_hci = $hci;
  }

  public function getAdapter(): Adapter {
    return $this->_hci;
  }

  public function addCommandHandler(Command $cmd) {
    $opc = strtoupper($cmd->getOpcode());
    if(isset($this->_hnd_cmd[$opc])) return;
    $this->_hnd_cmd[$opc] = $cmd;
  }
  public function getCommandHandler(string $opcode): ?Command {
    return $this->_hnd_cmd[strtoupper($opcode)] ?? null;
  }

  public function addEventHandler(Event $evt) {
    $code = strtoupper($evt->getCode());
    if(isset($this->_hnd_evt[$code])) return;
    $this->_hnd_evt[$evt->getCode()] = $evt;
  }
  public function getEventHandler(string $code): ?Event {
    return $this->_hnd_evt[strtoupper($code)] ?? null;
  }

  public function getError(string $code): Error {
    return new Error($code);
  }

  public function onCommand(callable $callback, ?Command $cmd = null): int {
    if(!$cmd) $cmd = new Command\Unknown;
    $opc = strtoupper($cmd->getOpcode());

    if(!isset($this->_cb_cmd[$opc])) $this->_cb_cmd[$opc] = [];
    $this->_cb_cmd[$opc][++$this->_cb_idx] = $callback;
    $this->addCommandHandler($cmd);

    if($this->_autostart) {
      $this->start();
    }

    return $this->_cb_idx;
  }

  public function onEvent(callable $callback, ?Event $evt = null): int {
    if(!$evt) $evt = new Event\Unknown;
    $code = strtoupper($evt->getCode());
    $callback = $evt->filter($callback);

    if(!isset($this->_cb_evt[$code])) $this->_cb_evt[$code] = [];
    $this->_cb_evt[$code][++$this->_cb_idx] = $callback;
    $this->addEventHandler($evt);

    if($this->_autostart) {
      $this->start();
    }

    return $this->_cb_idx;
  }

  public function removeCommandListener(int $index) {
    foreach($this->_cb_cmd as $opc => $listeners) {
      unset($this->_cb_cmd[$opc][$index]);

      if(empty($this->_cb_cmd[$opc])) {
        unset($this->_cb_cmd[$opc]);
      }
    }

    if($this->_autostart && empty($this->_cb_cmd) && empty($this->_cb_evt)) {
      $this->stop();
    }
  }

  public function removeEventListener(int $index) {
    foreach($this->_cb_evt as $code => $listeners) {
      unset($this->_cb_evt[$code][$index]);

      if(empty($this->_cb_evt[$code])) {
        unset($this->_cb_evt[$code]);
      }
    }

    if($this->_autostart && empty($this->_cb_cmd) && empty($this->_cb_evt)) {
      $this->stop();
    }
  }

  public function start() {
    if($this->_stopping) {
      return;
    }
    if($this->_proc) {
      return;
    }

    $this->_proc = new Process('exec hcidump -R -i '.$this->_hci->hci);
    $this->_proc->start();

    $this->_proc->on('exit', function($code,$term) {
      $this->_onExit($code, $term);
    });

    $this->_proc->stderr->on('data', function($data) {
      try {
        $this->_onStdErr($data);
      } catch(\Throwable $e) {
        $this->stop(true);
        throw $e;
      }
    });
    $this->_proc->stdout->on('data', function($data) {
      try {
        $this->_onStdOut($data);
      } catch(\Throwable $e) {
        $this->stop(true);
        throw $e;
      }
    });
  }

  public function stop(bool $kill=false) {
    if(!$this->_proc) {
      return;
    }

    if(!$this->_stopping) {
      foreach($this->_proc->pipes as $pipe) {
        $pipe->close();
      }
    }

    if($this->_stopping && !$kill) {
      return;
    }
    if(!$this->_proc) {
      return;
    }

    $stopping = $this->_stopping;
    $this->_stopping = true;

    $this->_proc->terminate($stopping||$kill ? SIGKILL : SIGTERM);
  }

  public function autostart(bool $autostart) {
    $this->_autostart = $autostart;
  }

  private function _onExit($code, $term) {
    $this->_proc->removeAllListeners();
    $this->_proc = null;

    $this->_stopping = false;
    $this->_emit('exit', [$code,$term]);
  }

  private function _onStdErr($data) {
    $data = trim($data);
    $this->_emit('stderr', [$data]);
    Log::error('HCIDump: '.$data, 'stderr','hcidump');
  }

  private function _onStdOut($data) {
    if(strlen($data)) {
      $data = preg_replace('/[^A-F0-9\>\<]+/i', '', $data);
      $this->_buffstr .= $data;
    }

    $data = '';
    $dlen = 0;

    if(!($blen=strlen($this->_buffstr))) {
      return;
    }

    // split _buffstr on next packet
    if(preg_match('/[\>\<]/', $this->_buffstr, $m, PREG_OFFSET_CAPTURE)) {
      $data = substr($this->_buffstr, $m[0][1]);
      $dlen = strlen($data);
      $this->_buffstr = substr($this->_buffstr, 0, $m[0][1]);
      $blen = $m[0][1];
    }

    if($this->_datalen) {
      if($dlen && $blen < $this->_datalen) {
        // data for next packet and not enough data for the current
        $this->_buffstr = '';
        $this->_buffbin = '';
        $this->_datalen = 0;
      } else {
        // blen and dlen can not be both 0
        // either no next packet and some or all data (or more) for the current
        // or next packet and all data (or more) for the current

        $blen = min($blen, $this->_datalen);
        $blen = ($blen % 2) ? ($blen - 1) : $blen;
        if(!$blen) {
          return;
        }

        $this->_buffbin .= hex2bin(substr($this->_buffstr, 0, $blen));
        $this->_datalen -= $blen;

        if($this->_datalen) {
          // more data needed for current packet
          // no more data is available or one char left in _buffstr
          $this->_buffstr = substr($this->_buffstr, $blen);
          return;
        } else {
          // current packet ready
          // any data left in _buffstr is junk
          $this->_buffstr = '';
          $this->_datalen = 0;
          $this->_handlePacket();
          $this->_buffbin = '';
        }
      }
    } else {
      // anything before packet start (< or >) is junk
      // scrap _buffstr, use whatever is in data
      // first char in data is packet start (see split _buffstr above)

      // command
      // <01xxxxnn : xxxx - opcode, nn - params length
      // event
      // >04xxnn   : xx - opcode, nn - params length

      if($dlen < 3) {
        // more data needed
        $this->_buffstr = $data;
        return;
      }

      // including packet start
      $hdrlen = 0;

      if(strpos($data,'<01') === 0) {
        // command
        $hdrlen = 9;
      } else if(strpos($data,'>04') === 0) {
        // event
        $hdrlen = 7;
      } else {
        // unknown, continue with everything after packet start
        $this->_buffstr = substr($data, 1);
        $this->_onStdOut('');
        return;
      }

      if($dlen < $hdrlen) {
        // more data needed
        $this->_buffstr = $data;
        return;
      }

      $header = substr($data, 1, $hdrlen-1);

      if(preg_match('/[\>\<]/', $header, $m, PREG_OFFSET_CAPTURE)) {
        // found packet start in header, continue with everything after and including it
        $this->_buffstr = substr($data, $m[0][1]+1);
        $this->_onStdOut('');
        return;
      }

      if(strpos($data,'<01') === 0) {
        // command
        $opcode = strtoupper(substr($data, 3, 4));
        $handle = isset($this->_hnd_cmd[$opcode]) || isset($this->_hnd_cmd['0000']);
      } else if(strpos($data,'>04') === 0) {
        // event
        $evcode = strtoupper(substr($data, 3, 2));
        $handle = isset($this->_hnd_evt[$evcode]) || isset($this->_hnd_evt['00']);
      } else {
        $handle = false;
      }

      if(!$handle) {
        // unhandled packet, remove header and continue
        $this->_buffstr = substr($data, $hdrlen);
        $this->_onStdOut('');
        return;
      }

      // at this point, last two hex digits of header are the packet length excluding header
      // everything after header goes into _buffstr
      $this->_buffstr = substr($data, $hdrlen);
      $this->_buffbin = $data[0].hex2bin($header);
      $this->_datalen = hexdec(substr($header,-2)) * 2; // in _buffstr (hex) chars
      $this->_onStdOut('');
      return;
    }

    if($dlen) {
      $this->_buffstr = $data;
      $this->_onStdOut('');
      return;
    }
  }

  private function _handlePacket() {
    $hdr = substr($this->_buffbin, 0, 2);

    if($hdr == "<\x01") {
      $opcbin = substr($this->_buffbin, 2, 2);
      $opchex = strtoupper(bin2hex($opcbin));

      if(($cmd = $this->_hnd_cmd[$opchex] ?? $this->_hnd_cmd['0000'] ?? null) === null) {
        return;
      }
      if(($cmd = $cmd->parse($opcbin, substr($this->_buffbin, 5), $this)) === null) {
        return;
      }

      $listeners = ($this->_cb_cmd[$opchex] ?? []) + ($this->_cb_cmd['0000'] ?? []);
      ksort($listeners);

      foreach($listeners as $listener) {
        $listener($cmd);
      }

      return;
    }

    if($hdr == ">\x04") {
      $codebin = $this->_buffbin[2];
      $codehex = strtoupper(bin2hex($codebin));

      if(($evt = $this->_hnd_evt[$codehex] ?? $this->_hnd_evt['00'] ?? null) === null) {
        return;
      }
      if(($evt = $evt->parse($codebin, substr($this->_buffbin, 4), $this)) === null) {
        return;
      }

      $listeners = ($this->_cb_evt[$codehex] ?? []) + ($this->_cb_evt['00'] ?? []);
      ksort($listeners);

      foreach($listeners as $listener) {
        $listener($evt);
      }

      return;
    }
  }
}
