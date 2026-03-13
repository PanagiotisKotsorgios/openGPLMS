<?php
require 'config.php';
if (isLoggedIn()) {
    $db = getDB();
    $db->prepare("INSERT INTO " . TABLE_PREFIX . "audit_log (user_id, action, target_type, details) VALUES (?,?,?,?)")
       ->execute([$_SESSION['user_id'], 'logout', 'user', '']);
}
session_destroy();
header('Location: index.php'); exit;