<?php

require_once __DIR__ . '/includes.php';

unset($_SESSION['course_user_id'], $_SESSION['course_user_email']);
redirect('login.php');
