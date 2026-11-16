<?php
require __DIR__ . '/_inc.php';
require_post_csrf();
logout_admin();
header('Location: login.php');
