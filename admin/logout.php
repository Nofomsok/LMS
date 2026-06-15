<?php

require_once __DIR__ . '/../includes.php';

$_SESSION = [];
session_destroy();
redirect('login.php');
