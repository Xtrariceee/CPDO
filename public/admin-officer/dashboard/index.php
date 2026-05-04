<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_role([ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN]);
redirect('admin-officer/index.php');
