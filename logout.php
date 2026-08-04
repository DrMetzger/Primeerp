<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;

Auth::logout();
header('Location: ' . url('login.php'));
exit;
