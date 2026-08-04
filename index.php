<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;

header('Location: ' . url(Auth::check() ? 'dashboard.php' : 'login.php'));
exit;
