<?php
namespace SharkyDog\BlueZ\HCI;

class Error {
  public static $errors = [
    'NA' => 'No response',
    '0C' => 'Command Disallowed',
    '11' => 'Unsupported Feature or Parameter Value',
    '12' => 'Invalid HCI Command Parameters'
  ];

  public $code;
  public $text;

  public function __construct(string $code) {
    $this->code = strtoupper($code) ?: 'UN';
    $this->text = static::$errors[$this->code] ?? 'Unknown';
  }
}
