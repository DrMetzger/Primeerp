<?php

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;

ClienteAuth::logout();
header('Location: ' . url('portal/login.php'));
exit;
