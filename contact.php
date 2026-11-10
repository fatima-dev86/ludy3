<?php
// Receives the contact form, saves it to the owner's inbox (admin.php) and emails the owner.
require __DIR__ . '/lib.php';

function len(string $v): int { return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v); }
function back(string $code): void { header('Location: index.php?sent=' . $code . '#contact'); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: index.php#contact'); exit; }
if (!empty($_POST['website'])) { back('1'); }                       // hidden field filled in: a robot

$name  = trim(preg_replace('/[\r\n]+/', ' ', (string)($_POST['name'] ?? '')));
$email = trim((string)($_POST['email'] ?? ''));
$msg   = trim((string)($_POST['message'] ?? ''));
if ($name === '' || $msg === '' || len($name) > 100 || len($msg) > 3000) { back('2'); }
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $email = ''; }

session_start();
if (isset($_SESSION['last_msg']) && time() - (int)$_SESSION['last_msg'] < 30) { back('3'); }

$saved  = save_message($name, $email, $msg);      // always keep a copy in the admin inbox
$mailed = send_notification($name, $email, $msg); // and email the owner

if ($saved || $mailed) { $_SESSION['last_msg'] = time(); }
back(($saved || $mailed) ? '1' : '0');
