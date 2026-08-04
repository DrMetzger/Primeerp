<?php

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;

header('Location: ' . url(ClienteAuth::check() ? 'portal/chamados.php' : 'portal/login.php'));
exit;
