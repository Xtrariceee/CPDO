<?php
// Redirect to the zoning officer dashboard
require_once __DIR__ . '/../../../app/bootstrap_cpdo.php';
require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);
redirect('zoning-officer/index.php');
