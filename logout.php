<?php
declare(strict_types=1);
require_once __DIR__ . '/backend/bootstrap.php';

destroy_session('logout');

header("Location: login.php");
exit;
