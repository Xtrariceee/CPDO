<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);
redirect('zoning-officer/index.php');
